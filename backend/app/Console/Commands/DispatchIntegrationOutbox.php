<?php

namespace App\Console\Commands;

use App\Modules\Integrations\Services\OutboxDispatcher;
use Illuminate\Console\Command;

/**
 * Publishes pending integration outbox rows to the queue and re-queues lost
 * jobs. Safe to run repeatedly; consumers are idempotent.
 */
class DispatchIntegrationOutbox extends Command
{
    protected $signature = 'integrations:dispatch-outbox';

    protected $description = 'Publish pending WhatsApp outbox events and re-queue stuck integration jobs';

    public function handle(OutboxDispatcher $dispatcher): int
    {
        $published = $dispatcher->publishPending();
        $requeued = $dispatcher->sweepStuck(45);

        $this->info("Published {$published} outbox event(s); re-queued {$requeued} stuck job(s).");

        return self::SUCCESS;
    }
}
