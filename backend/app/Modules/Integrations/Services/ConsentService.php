<?php

namespace App\Modules\Integrations\Services;

use App\Modules\Integrations\Models\MessagingConsent;

/**
 * WhatsApp opt-in registry. Business-initiated messages require an explicit,
 * recorded opt-in; the most recent record per phone wins, so an opt-out
 * (manual or inbound STOP keyword) always overrides an earlier opt-in.
 */
class ConsentService
{
    public const CHANNEL = 'whatsapp';

    /** @return 'opt_in'|'opt_out'|null */
    public function status(string $phoneDigits): ?string
    {
        return MessagingConsent::query()
            ->where('channel', self::CHANNEL)
            ->where('phone', $phoneDigits)
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->value('status');
    }

    public function record(
        string $phoneDigits,
        string $status,
        string $source,
        ?int $leadId = null,
        ?int $customerId = null,
        ?int $actorId = null,
        ?string $note = null,
    ): MessagingConsent {
        return MessagingConsent::query()->create([
            'channel' => self::CHANNEL,
            'phone' => $phoneDigits,
            'status' => $status,
            'source' => $source,
            'note' => $note !== null ? mb_substr($note, 0, 255) : null,
            'lead_id' => $leadId,
            'customer_id' => $customerId,
            'recorded_by' => $actorId,
            'recorded_at' => now(),
        ]);
    }
}
