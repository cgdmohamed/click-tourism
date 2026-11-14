<?php

namespace App\Console\Commands;

use App\Modules\Assistant\Services\AssistantUploadService;
use App\Modules\Assistant\Services\ConversationService;
use Illuminate\Console\Command;

class PruneAssistantHistory extends Command
{
    protected $signature = 'assistant:prune-history';

    protected $description = 'Delete saved assistant conversations older than assistant.history_retention_days (ADR-0022) and expired chat files (ADR-0028)';

    public function handle(ConversationService $conversations, AssistantUploadService $uploads): int
    {
        $this->info('Conversations deleted: '.$conversations->prune());
        $this->info('Chat files deleted: '.$uploads->prune());

        return self::SUCCESS;
    }
}
