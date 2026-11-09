<?php

namespace App\Http\Controllers\Api;

use App\Modules\Core\Models\Branch;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Services\LeadService;
use App\Modules\Integrations\Models\WebhookEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Meta / WhatsApp webhooks (PRD FR-INT-*).
 *
 * Live acceptance is blocked until credentials exist; the verification and
 * signature paths are fully implemented and covered by fixture tests.
 */
class WebhookController extends ApiController
{
    public function verifyMeta(Request $request): Response|JsonResponse
    {
        return $this->verify($request, (string) config('services.meta.verify_token'));
    }

    public function verifyWhatsApp(Request $request): Response|JsonResponse
    {
        return $this->verify($request, (string) config('services.whatsapp.verify_token'));
    }

    public function receiveMeta(Request $request, LeadService $leads): JsonResponse
    {
        return $this->receive($request, 'meta', (string) config('services.meta.app_secret'), 'facebook', $leads);
    }

    public function receiveWhatsApp(Request $request, LeadService $leads): JsonResponse
    {
        return $this->receive($request, 'whatsapp', (string) config('services.whatsapp.app_secret'), 'whatsapp', $leads);
    }

    private function verify(Request $request, string $expectedToken): Response|JsonResponse
    {
        $mode = (string) $request->query('hub_mode', $request->query('hub.mode'));
        $token = (string) $request->query('hub_verify_token', $request->query('hub.verify_token'));
        $challenge = (string) $request->query('hub_challenge', $request->query('hub.challenge'));

        if ($expectedToken === '') {
            return response()->json([
                'message' => 'Webhook verification token is not configured.',
                'code' => 'webhook_not_configured',
            ], 503);
        }

        if ($mode !== 'subscribe' || ! hash_equals($expectedToken, $token)) {
            return response()->json(['message' => 'Verification failed.', 'code' => 'verification_failed'], 403);
        }

        return response($challenge, 200)->header('Content-Type', 'text/plain');
    }

    private function receive(Request $request, string $provider, string $secret, string $source, LeadService $leads): JsonResponse
    {
        $payload = $request->getContent();
        $signature = (string) $request->header('X-Hub-Signature-256', '');

        if ($secret === '') {
            // A missing secret is a configuration error in production; only
            // local/test environments may ingest unsigned fixture payloads.
            if (app()->environment('production')) {
                return response()->json([
                    'message' => 'Webhook app secret is not configured.',
                    'code' => 'webhook_not_configured',
                ], 503);
            }
        } else {
            $expected = 'sha256='.hash_hmac('sha256', $payload, $secret);
            if (! hash_equals($expected, $signature)) {
                return response()->json(['message' => 'Invalid signature.', 'code' => 'invalid_signature'], 403);
            }
        }

        $data = json_decode($payload, true) ?: [];
        $messageId = (string) ($data['entry'][0]['changes'][0]['value']['messages'][0]['id'] ?? '');
        // Deduplicate on the first message id when present; otherwise fall back
        // to a payload hash so provider retries of the same event are no-ops.
        $externalId = $messageId !== '' ? $messageId : hash('sha256', $payload);

        $event = WebhookEvent::query()->firstOrCreate(
            ['provider' => $provider, 'external_event_id' => $externalId],
            ['payload' => $data, 'status' => 'received'],
        );

        if (! $event->wasRecentlyCreated) {
            return $this->ok(['status' => 'duplicate']);
        }

        try {
            $this->ingestMessages($data, $provider, $source, $leads);
            $event->update(['status' => 'processed', 'attempts' => $event->attempts + 1]);
        } catch (\Throwable $exception) {
            $event->update(['status' => 'failed', 'attempts' => $event->attempts + 1]);
            report($exception);
        }

        return $this->ok(['status' => 'received']);
    }

    private function ingestMessages(array $data, string $provider, string $source, LeadService $leads): void
    {
        $branch = Branch::query()->where('is_active', true)->orderBy('id')->first();
        if (! $branch) {
            return;
        }

        foreach ($data['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $value = $change['value'] ?? [];
                $contacts = collect($value['contacts'] ?? [])->keyBy('wa_id');

                foreach ($value['messages'] ?? [] as $message) {
                    $from = (string) ($message['from'] ?? '');
                    if ($from === '') {
                        continue;
                    }

                    $externalId = (string) ($message['id'] ?? '');
                    if ($externalId !== '' && Lead::query()->where('external_id', $externalId)->exists()) {
                        continue;
                    }

                    $leads->create([
                        'name' => (string) ($contacts[$from]['profile']['name'] ?? $from),
                        'phone' => $from,
                        'source' => $source,
                        'branch_id' => $branch->public_id,
                        'consent' => true,
                        'notes' => (string) ($message['text']['body'] ?? ''),
                        'external_id' => $externalId ?: null,
                    ]);
                }
            }
        }
    }
}
