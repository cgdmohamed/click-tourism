<?php

namespace App\Modules\Integrations\Services;

use App\Modules\CRM\Models\LeadActivity;
use App\Modules\CRM\Services\LeadService;
use App\Modules\Integrations\Models\WebhookEvent;
use App\Modules\Integrations\Models\WhatsAppMessage;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * WhatsApp Cloud API webhooks: inbound messages and delivery statuses.
 *
 * Inbound messages are logged and linked to an existing lead/customer by
 * phone; an unknown sender becomes a `whatsapp` lead (existing behaviour).
 * This is a message LOG, not a chat inbox, and nothing is auto-replied.
 *
 * UNVERIFIED webhook shape (confirm in the official WhatsApp Cloud API docs):
 *   entry[].changes[].value = {"contacts":[{"wa_id","profile":{"name"}}],
 *     "messages":[{"id","from","timestamp","type","text":{"body"}}],
 *     "statuses":[{"id","status":"sent|delivered|read|failed","timestamp",
 *                  "recipient_id","errors":[{"code","title","message"}]}]}
 */
class WhatsAppWebhookHandler
{
    public function __construct(
        private readonly IntegrationSettings $settings,
        private readonly ContactMatcher $matcher,
        private readonly ConsentService $consent,
        private readonly LeadService $leads,
    ) {}

    /** @return 'received'|'duplicate' */
    public function handle(array $data, string $rawPayload): string
    {
        $event = WebhookEvent::query()->firstOrCreate(
            ['provider' => 'whatsapp', 'external_event_id' => hash('sha256', $rawPayload)],
            ['payload' => Redactor::payload($data), 'status' => 'received'],
        );

        if (! $event->wasRecentlyCreated && $event->status !== 'failed') {
            return 'duplicate';
        }

        try {
            foreach ((array) ($data['entry'] ?? []) as $entry) {
                foreach ((array) ($entry['changes'] ?? []) as $change) {
                    $value = (array) ($change['value'] ?? []);
                    $contacts = collect((array) ($value['contacts'] ?? []))->keyBy('wa_id');

                    foreach ((array) ($value['statuses'] ?? []) as $status) {
                        $this->applyStatus((array) $status);
                    }
                    foreach ((array) ($value['messages'] ?? []) as $message) {
                        $this->storeInbound((array) $message, $contacts->all());
                    }
                }
            }
            $event->update(['status' => 'processed', 'processed_at' => now(), 'attempts' => $event->attempts + 1, 'last_error' => null]);
        } catch (\Throwable $exception) {
            $event->update(['status' => 'failed', 'attempts' => $event->attempts + 1, 'last_error' => mb_substr($exception->getMessage(), 0, 250)]);
            // No payload or phone numbers in logs.
            Log::error('WhatsApp webhook processing failed', ['event_id' => $event->id, 'exception' => $exception::class]);
        }

        return 'received';
    }

    private function applyStatus(array $status): void
    {
        $waId = (string) ($status['id'] ?? '');
        $new = (string) ($status['status'] ?? '');
        if ($waId === '' || ! in_array($new, ['sent', 'delivered', 'read', 'failed'], true)) {
            return;
        }

        $message = WhatsAppMessage::query()->where('wa_message_id', $waId)->where('direction', 'out')->first();
        if (! $message) {
            return;
        }

        $at = isset($status['timestamp']) && is_numeric($status['timestamp'])
            ? Carbon::createFromTimestamp((int) $status['timestamp'])
            : now();

        if ($new === 'failed') {
            if (in_array($message->status, ['delivered', 'read'], true)) {
                return;
            }
            $error = (array) (($status['errors'][0] ?? []));
            $message->update([
                'status' => 'failed',
                'error_code' => isset($error['code']) ? mb_substr((string) $error['code'], 0, 40) : null,
                'error_message' => isset($error['title']) ? mb_substr((string) $error['title'], 0, 255) : 'Delivery failed',
                'failed_at' => $at,
                'status_updated_at' => $at,
            ]);

            return;
        }

        $rank = WhatsAppMessage::STATUS_RANK;
        if (($rank[$new] ?? -1) <= ($rank[$message->status] ?? 99)) {
            return; // Out-of-order or repeated status: never move backwards.
        }

        $message->update([
            'status' => $new,
            $new.'_at' => $at,
            'status_updated_at' => $at,
        ]);
    }

    private function storeInbound(array $message, array $contacts): void
    {
        $waId = (string) ($message['id'] ?? '');
        $phone = PhoneNumber::digits((string) ($message['from'] ?? ''));
        if ($phone === '') {
            return;
        }
        if ($waId !== '' && WhatsAppMessage::query()->where('wa_message_id', $waId)->exists()) {
            return;
        }

        $isText = ($message['type'] ?? 'text') === 'text' && isset($message['text']['body']);
        $body = $isText ? mb_substr((string) $message['text']['body'], 0, 4000) : null;
        $name = (string) ($contacts[(string) $message['from']]['profile']['name'] ?? $phone);

        $lead = $this->matcher->leadByPhone($phone);
        $customer = $this->matcher->customerByPhone($phone);

        if (! $lead && ! $customer) {
            $branch = $this->settings->defaultBranch();
            if ($branch) {
                try {
                    $lead = $this->leads->create([
                        'name' => $name,
                        'phone' => $phone,
                        'source' => 'whatsapp',
                        'branch_id' => $branch->public_id,
                        // User-initiated contact: consent to be contacted back about the enquiry.
                        'consent' => true,
                        'notes' => $body !== null ? mb_substr($body, 0, 2000) : null,
                        'external_id' => $waId !== '' ? $waId : null,
                    ]);
                } catch (DomainException $exception) {
                    Log::warning('WhatsApp lead not created', ['reason' => $exception->getMessage()]);
                }
            }
        }

        try {
            WhatsAppMessage::query()->create([
                'direction' => 'in',
                'wa_message_id' => $waId !== '' ? $waId : null,
                'phone' => $phone,
                'lead_id' => $lead?->id,
                'customer_id' => $customer?->id,
                'type' => $isText ? 'text' : 'other',
                'body' => $body,
                'status' => 'received',
                'status_updated_at' => now(),
            ]);
        } catch (QueryException) {
            return; // Concurrent duplicate delivery of the same message id.
        }

        if ($lead) {
            LeadActivity::query()->create([
                'lead_id' => $lead->id,
                'type' => 'whatsapp',
                'body' => 'Inbound WhatsApp message received',
                'created_at' => now(),
            ]);
        }

        if ($body !== null && in_array(mb_strtolower(trim($body)), $this->settings->stopKeywords(), true)) {
            $this->consent->record($phone, 'opt_out', 'inbound_stop', $lead?->id, $customer?->id);
        }
    }
}
