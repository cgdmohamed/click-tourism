<?php

namespace App\Modules\Integrations\Services;

use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Models\LeadActivity;
use App\Modules\Integrations\Contracts\WhatsAppSender;
use App\Modules\Integrations\Exceptions\IntegrationException;
use App\Modules\Integrations\Exceptions\OutboundRefusedException;
use App\Modules\Integrations\Models\OutboxEvent;
use App\Modules\Integrations\Models\WhatsAppMessage;
use App\Modules\Parties\Models\Customer;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Outbound WhatsApp messages: explicit, staff-triggered sends only (no
 * auto-messaging). A message is written to `whatsapp_messages` and to
 * `outbox_events` in ONE transaction (transactional outbox, ADR-0009); the
 * dispatcher then queues a job that sends it with retry/backoff.
 *
 * Guards: integration enabled, recorded opt-in (and no later opt-out), and
 * free-form text only inside the 24h customer service window (UNVERIFIED
 * rule: confirm in the official WhatsApp Cloud API / pricing docs); otherwise
 * an approved template is required.
 */
class WhatsAppOutboundService
{
    public const OUTBOX_TYPE = 'whatsapp.message.queued';

    public const SERVICE_WINDOW_HOURS = 24;

    public function __construct(
        private readonly IntegrationSettings $settings,
        private readonly ConsentService $consent,
        private readonly OutboxDispatcher $dispatcher,
    ) {}

    /**
     * @param  array{type: string, body?: ?string, template_name?: ?string, language?: ?string, components?: ?array}  $content
     */
    public function enqueue(?Lead $lead, ?Customer $customer, array $content, ?string $idempotencyKey, ?int $actorId): WhatsAppMessage
    {
        if (! $this->settings->whatsappOutboundEnabled()) {
            throw new OutboundRefusedException('integration_disabled', 'WhatsApp sending is disabled for this installation.');
        }

        $phone = PhoneNumber::digits($lead?->phone ?? $customer?->phone);
        if (strlen($phone) < 8 || strlen($phone) > 15) {
            throw new OutboundRefusedException('invalid_phone', 'The contact has no valid phone number.');
        }

        if ($idempotencyKey !== null) {
            $existing = WhatsAppMessage::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                return $existing;
            }
        }

        $status = $this->consent->status($phone);
        if ($status === 'opt_out') {
            throw new OutboundRefusedException('consent_withdrawn', 'The contact opted out of WhatsApp messages.');
        }
        if ($status !== 'opt_in') {
            throw new OutboundRefusedException('consent_missing', 'No recorded WhatsApp opt-in for this contact.');
        }

        $type = $content['type'];
        if ($type === 'text' && ! $this->insideServiceWindow($phone)) {
            throw new OutboundRefusedException(
                'outside_service_window',
                'Free-form text is only allowed within 24 hours of the contact\'s last message; use an approved template.',
            );
        }

        try {
            $message = DB::transaction(function () use ($lead, $customer, $content, $idempotencyKey, $actorId, $phone, $type) {
                $message = WhatsAppMessage::query()->create([
                    'direction' => 'out',
                    'idempotency_key' => $idempotencyKey,
                    'phone' => $phone,
                    'lead_id' => $lead?->id,
                    'customer_id' => $customer?->id,
                    'type' => $type,
                    'body' => $type === 'text' ? $content['body'] : null,
                    'template_name' => $type === 'template' ? $content['template_name'] : null,
                    'template_language' => $type === 'template' ? ($content['language'] ?? 'en') : null,
                    'template_components' => $type === 'template' ? ($content['components'] ?? null) : null,
                    'status' => 'queued',
                    'status_updated_at' => now(),
                    'created_by' => $actorId,
                ]);

                OutboxEvent::query()->create([
                    'event_id' => (string) Str::ulid(),
                    'type' => self::OUTBOX_TYPE,
                    'version' => 1,
                    'payload' => ['message_id' => $message->id],
                ]);

                if ($lead) {
                    LeadActivity::query()->create([
                        'lead_id' => $lead->id,
                        'type' => 'whatsapp',
                        'body' => 'WhatsApp message queued',
                        'actor_id' => $actorId,
                        'created_at' => now(),
                    ]);
                }

                return $message;
            });
        } catch (QueryException $exception) {
            $existing = $idempotencyKey !== null
                ? WhatsAppMessage::query()->where('idempotency_key', $idempotencyKey)->first()
                : null;
            if (! $existing) {
                throw $exception;
            }

            return $existing;
        }

        $this->dispatcher->publishPending();

        return $message->refresh();
    }

    /**
     * Runs inside the queued job. Transient provider errors are re-thrown for
     * retry; permanent ones fail the message. A message is claimed
     * (queued -> sending) before the HTTP call so concurrent or duplicate jobs
     * cannot send it twice.
     */
    public function deliver(int $messageId, WhatsAppSender $sender): void
    {
        $claimed = WhatsAppMessage::query()
            ->whereKey($messageId)
            ->where('direction', 'out')
            ->where('status', 'queued')
            ->whereNull('wa_message_id')
            ->update(['status' => 'sending', 'attempts' => DB::raw('attempts + 1')]);
        if ($claimed !== 1) {
            return;
        }

        $message = WhatsAppMessage::query()->findOrFail($messageId);

        if (! $this->settings->whatsappOutboundEnabled()) {
            $this->fail($message, 'integration_disabled', 'WhatsApp sending is disabled.');

            return;
        }
        if ($this->consent->status($message->phone) !== 'opt_in') {
            $this->fail($message, 'consent_withdrawn', 'Consent is no longer valid.');

            return;
        }

        try {
            $sent = $message->type === 'template'
                ? $sender->sendTemplate($message->phone, (string) $message->template_name, (string) ($message->template_language ?: 'en'), (array) ($message->template_components ?? []))
                : $sender->sendText($message->phone, (string) $message->body);
        } catch (IntegrationException $exception) {
            if ($exception->transient) {
                $message->update([
                    'status' => 'queued',
                    'error_code' => $exception->providerCode,
                    'error_message' => mb_substr($exception->getMessage(), 0, 255),
                ]);
                throw $exception;
            }
            $this->fail($message, $exception->providerCode ?? 'send_failed', $exception->getMessage());

            return;
        }

        $message->update([
            'status' => 'sent',
            'wa_message_id' => $sent->messageId,
            'sent_at' => now(),
            'status_updated_at' => now(),
            'error_code' => null,
            'error_message' => null,
        ]);

        if ($message->lead_id) {
            LeadActivity::query()->create([
                'lead_id' => $message->lead_id,
                'type' => 'whatsapp',
                'body' => 'WhatsApp message sent',
                'actor_id' => $message->created_by,
                'created_at' => now(),
            ]);
        }
    }

    /** Called when the queue gives up on a message. */
    public function markExhausted(int $messageId, string $reason): void
    {
        WhatsAppMessage::query()->whereKey($messageId)->whereIn('status', ['queued', 'sending'])->update([
            'status' => 'failed',
            'error_code' => 'retries_exhausted',
            'error_message' => mb_substr($reason, 0, 255),
            'failed_at' => now(),
            'status_updated_at' => now(),
        ]);
    }

    private function fail(WhatsAppMessage $message, string $code, string $reason): void
    {
        $message->update([
            'status' => 'failed',
            'error_code' => mb_substr($code, 0, 40),
            'error_message' => mb_substr($reason, 0, 255),
            'failed_at' => now(),
            'status_updated_at' => now(),
        ]);
    }

    private function insideServiceWindow(string $phone): bool
    {
        return WhatsAppMessage::query()
            ->where('direction', 'in')
            ->where('phone', $phone)
            ->where('created_at', '>=', now()->subHours(self::SERVICE_WINDOW_HOURS))
            ->exists();
    }
}
