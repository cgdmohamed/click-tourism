<?php

namespace App\Modules\Integrations\Jobs;

use App\Modules\Integrations\Contracts\MetaLeadFetcher;
use App\Modules\Integrations\Services\MetaLeadIngestionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessMetaLeadJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public readonly int $webhookEventId) {}

    /** @return list<int> seconds between attempts */
    public function backoff(): array
    {
        return [30, 120, 600, 1800];
    }

    public function handle(MetaLeadIngestionService $service, MetaLeadFetcher $fetcher): void
    {
        $service->process($this->webhookEventId, $fetcher);
    }

    public function failed(Throwable $exception): void
    {
        app(MetaLeadIngestionService::class)->markFailed($this->webhookEventId, 'retries exhausted: '.$exception->getMessage());
    }
}
