<?php

namespace App\Console\Commands;

use App\Modules\Assistant\Services\ConversationService;
use Illuminate\Console\Command;

class PruneAssistantHistory extends Command
{
    protected $signature = 'assistant:prune-history';

    protected $description = 'Delete saved assistant conversations older than assistant.history_retention_days (ADR-0022)';

    public function handle(ConversationService $conversations): int
    {
        $this->info('Conversations deleted: '.$conversations->prune());

        return self::SUCCESS;
    }
}
