<?php

namespace App\Modules\Integrations\Services;

use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Models\LeadActivity;
use App\Modules\CRM\Models\LeadSource;
use App\Modules\CRM\Services\LeadService;
use App\Modules\Integrations\Contracts\MetaLeadFetcher;
use App\Modules\Integrations\Exceptions\IntegrationException;
use App\Modules\Integrations\Jobs\ProcessMetaLeadJob;
use App\Modules\Integrations\Models\WebhookEvent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * Meta Lead Ads: webhook payload -> queued job -> Graph API fetch -> CRM lead.
 *
 * Idempotency layers: (1) webhook_events is unique per leadgen id, (2) leads
 * are unique per (source, external_id), (3) a lead with the same phone/email
 * gets a note instead of a duplicate. Branch routing uses the configured form
 * mapping/default branch; the owner is picked by the existing assignment rules.
 *
 * UNVERIFIED webhook shape (confirm in the official Lead Ads webhooks docs):
 *   entry[].changes[] = {"field": "leadgen", "value": {"leadgen_id", "page_id",
 *   "form_id", "ad_id", "adgroup_id", "created_time"}}
 */
class MetaLeadIngestionService
{
    public const SOURCE = 'facebook';

    public function __construct(
        private readonly IntegrationSettings $settings,
        private readonly ContactMatcher $matcher,
        private readonly LeadService $leads,
    ) {}

    /**
     * Records one webhook event per leadgen id and queues processing.
     *
     * @return 'received'|'duplicate'
     */
    public function handleWebhook(array $data, string $rawPayload): string
    {
        $queued = 0;
        $seen = 0;

        foreach ((array) ($data['entry'] ?? []) as $entry) {
            foreach ((array) ($entry['changes'] ?? []) as $change) {
                $value = (array) ($change['value'] ?? []);
                $leadgenId = (string) ($value['leadgen_id'] ?? '');
                if (($change['field'] ?? '') !== 'leadgen' || $leadgenId === '') {
                    continue;
                }
                $seen++;

                $pageId = (string) ($value['page_id'] ?? $entry['id'] ?? '');
                $configuredPage = $this->settings->metaPageId();
                if ($configuredPage !== '' && $pageId !== '' && $configuredPage !== $pageId) {
                    continue; // Another page's event: not this installation's lead form.
                }

                $event = WebhookEvent::query()->firstOrCreate(
                    ['provider' => 'meta', 'external_event_id' => 'leadgen:'.$leadgenId],
                    ['payload' => [
                        'leadgen_id' => $leadgenId,
                        'page_id' => $pageId,
                        'form_id' => (string) ($value['form_id'] ?? ''),
                        'ad_id' => (string) ($value['ad_id'] ?? ''),
                    ], 'status' => 'received'],
                );

                // A provider re-delivery of an event that exhausted its retries is re-queued.
                if ($event->wasRecentlyCreated || $event->status === 'failed') {
                    $event->update(['status' => 'received']);
                    $this->dispatch($event);
                    $queued++;
                }
            }
        }

        if ($seen === 0) {
            // Nothing actionable (e.g. other field). Record once so replays stay no-ops.
            $event = WebhookEvent::query()->firstOrCreate(
                ['provider' => 'meta', 'external_event_id' => hash('sha256', $rawPayload)],
                ['payload' => Redactor::payload($data), 'status' => 'ignored'],
            );

            return $event->wasRecentlyCreated ? 'received' : 'duplicate';
        }

        return $queued > 0 ? 'received' : 'duplicate';
    }

    public function dispatch(WebhookEvent $event): void
    {
        try {
            ProcessMetaLeadJob::dispatch($event->id);
        } catch (\Throwable $exception) {
            // With the sync queue (tests, small installs) a failing job throws here;
            // the event row already carries the failure and the provider must still get a 200.
            Log::warning('Meta lead processing failed', ['event_id' => $event->id, 'error' => $exception->getMessage()]);
        }
    }

    /**
     * Runs inside the queued job. Transient provider failures are re-thrown so
     * the queue retries with backoff; permanent ones mark the event failed.
     */
    public function process(int $eventId, MetaLeadFetcher $fetcher): void
    {
        $event = WebhookEvent::query()->find($eventId);
        if (! $event || in_array($event->status, ['processed', 'ignored'], true)) {
            return;
        }

        $event->increment('attempts');

        if (! $this->settings->metaEnabled()) {
            $event->update(['status' => 'failed', 'last_error' => 'integration_disabled']);

            return;
        }

        try {
            $outcome = $this->ingest($event, $fetcher);
        } catch (IntegrationException $exception) {
            $event->update([
                'status' => $exception->transient ? 'retrying' : 'failed',
                'last_error' => mb_substr($exception->getMessage(), 0, 250),
            ]);
            if ($exception->transient) {
                throw $exception;
            }

            return;
        }

        $event->update([
            'status' => 'processed',
            'processed_at' => now(),
            'last_error' => null,
            'payload' => array_merge((array) $event->payload, $outcome),
        ]);
    }

    public function markFailed(int $eventId, string $reason): void
    {
        WebhookEvent::query()->whereKey($eventId)->where('status', '!=', 'processed')
            ->update(['status' => 'failed', 'last_error' => mb_substr($reason, 0, 250)]);
    }

    /** @return array{outcome: string, lead_id: string|null} */
    private function ingest(WebhookEvent $event, MetaLeadFetcher $fetcher): array
    {
        $leadgenId = (string) ($event->payload['leadgen_id'] ?? '');
        $source = LeadSource::query()->where('code', self::SOURCE)->first();

        $existing = $source
            ? Lead::query()->where('source_id', $source->id)->where('external_id', $leadgenId)->first()
            : null;
        if ($existing) {
            return ['outcome' => 'duplicate_external_id', 'lead_id' => $existing->public_id];
        }

        $meta = $fetcher->fetch($leadgenId);

        $duplicate = $this->matcher->leadByPhone($meta->phone()) ?? $this->matcher->leadByEmail($meta->email());
        if ($duplicate) {
            LeadActivity::query()->create([
                'lead_id' => $duplicate->id,
                'type' => 'note',
                'body' => 'Meta lead form submitted again (leadgen '.$leadgenId.'); no duplicate lead created.',
                'created_at' => now(),
            ]);

            return ['outcome' => 'duplicate_contact', 'lead_id' => $duplicate->public_id];
        }

        $formId = $meta->formId ?? (string) ($event->payload['form_id'] ?? '');
        $branch = $this->settings->branchForMetaForm($formId !== '' ? $formId : null);
        if (! $branch) {
            throw new IntegrationException('No active branch is available for Meta leads.', false, 'no_branch');
        }

        $notes = ['Meta Lead Ads'.($formId !== '' ? ' form '.$formId : '')];
        foreach ($meta->extraAnswers() as $name => $value) {
            $notes[] = $name.': '.$value;
        }

        try {
            $lead = $this->leads->create([
                'name' => $meta->name() ?? $meta->email() ?? $meta->phone() ?? 'Meta lead '.$leadgenId,
                'phone' => $meta->phone() !== null ? mb_substr($meta->phone(), 0, 30) : null,
                'email' => $meta->email(),
                'source' => self::SOURCE,
                'branch_id' => $branch->public_id,
                // The form's own consent wording is not verified here; WhatsApp opt-in is tracked separately.
                'consent' => false,
                'notes' => mb_substr(implode("\n", $notes), 0, 2000),
                'external_id' => $leadgenId,
            ]);
        } catch (QueryException) {
            // Concurrent worker created it first (unique source_id + external_id).
            $lead = $source ? Lead::query()->where('source_id', $source->id)->where('external_id', $leadgenId)->first() : null;
            if (! $lead) {
                throw new IntegrationException('Lead could not be stored.', true, 'store_failed');
            }

            return ['outcome' => 'duplicate_external_id', 'lead_id' => $lead->public_id];
        }

        return ['outcome' => 'created', 'lead_id' => $lead->public_id];
    }
}
