<?php

namespace App\Modules\Integrations\Jobs;

use App\Modules\Integrations\Contracts\WhatsAppSender;
use App\Modules\Integrations\Services\WhatsAppOutboundService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SendWhatsAppMessageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public readonly int $messageId) {}

    /** @return list<int> seconds between attempts (also spaces out rate-limit retries) */
    public function backoff(): array
    {
        return [30, 120, 600, 1800];
    }

    public function handle(WhatsAppOutboundService $service, WhatsAppSender $sender): void
    {
        $service->deliver($this->messageId, $sender);
    }

    public function failed(Throwable $exception): void
    {
        app(WhatsAppOutboundService::class)->markExhausted($this->messageId, 'Retries exhausted: '.$exception->getMessage());
    }
}
