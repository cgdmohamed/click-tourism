<?php

namespace App\Modules\Integrations\Services;

use App\Modules\Integrations\Jobs\SendWhatsAppMessageJob;
use App\Modules\Integrations\Models\OutboxEvent;
use App\Modules\Integrations\Models\WebhookEvent;
use App\Modules\Integrations\Models\WhatsAppMessage;
use Illuminate\Support\Facades\Log;

/**
 * Publishes outbox rows to the queue (at-least-once). Each row is claimed by
 * an atomic `published_at IS NULL` update, so concurrent dispatchers never
 * publish it twice; consumers are idempotent anyway (see deliver()).
 */
class OutboxDispatcher
{
    public function publishPending(int $limit = 50): int
    {
        $published = 0;
        $events = OutboxEvent::query()
            ->where('type', WhatsAppOutboundService::OUTBOX_TYPE)
            ->whereNull('published_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($events as $event) {
            $claimed = OutboxEvent::query()->whereKey($event->id)->whereNull('published_at')
                ->update(['published_at' => now(), 'attempts' => $event->attempts + 1]);
            if ($claimed !== 1) {
                continue;
            }

            try {
                SendWhatsAppMessageJob::dispatch((int) ($event->payload['message_id'] ?? 0));
                $published++;
            } catch (\Throwable $exception) {
                // Sync queue (or a broker outage) surfaces here; release the row so the scheduler retries it.
                Log::warning('Outbox publish failed', ['outbox_id' => $event->id, 'exception' => $exception::class]);
                OutboxEvent::query()->whereKey($event->id)->update(['published_at' => null]);
            }
        }

        return $published;
    }

    /**
     * Safety net run by the scheduler: re-queue Meta webhook events that were
     * never processed (worker crash, lost job). Messages stuck in `sending`
     * are NOT resent automatically (could double-send); they are reported
     * by the admin status screen for manual review.
     */
    public function sweepStuck(int $olderThanMinutes = 10): int
    {
        $count = 0;
        $service = app(MetaLeadIngestionService::class);

        WebhookEvent::query()
            ->where('provider', 'meta')
            ->whereIn('status', ['received', 'retrying'])
            ->where('attempts', '<', 5)
            ->where('updated_at', '<', now()->subMinutes($olderThanMinutes))
            ->limit(50)
            ->get()
            ->each(function (WebhookEvent $event) use ($service, &$count) {
                $event->touch();
                $service->dispatch($event);
                $count++;
            });

        // Re-queue outbound messages whose job was lost (still `queued` long after enqueue).
        WhatsAppMessage::query()
            ->where('direction', 'out')
            ->where('status', 'queued')
            ->where('attempts', '<', 5)
            ->where('updated_at', '<', now()->subMinutes($olderThanMinutes))
            ->limit(50)
            ->get()
            ->each(function (WhatsAppMessage $message) use (&$count) {
                $message->touch();
                try {
                    SendWhatsAppMessageJob::dispatch($message->id);
                    $count++;
                } catch (\Throwable $exception) {
                    Log::warning('Outbound re-queue failed', ['message_id' => $message->id, 'exception' => $exception::class]);
                }
            });

        return $count;
    }
}
