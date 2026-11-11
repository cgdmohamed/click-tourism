<?php

namespace App\Console\Commands;

/**
 * Alias kept for existing runbooks: runs the same logic as
 * `accounting:ensure-accounts`, limited to the unrealized FX accounts 7901/6901.
 */
class EnsureFxAccounts extends EnsureAccounts
{
    protected $signature = 'accounting:ensure-fx-accounts
        {--dry-run : Show which accounts would be created (default)}
        {--apply : Create the missing accounts}';

    protected $description = 'Create the unrealized FX gain/loss accounts (7901/6901) if absent';

    public function __construct()
    {
        parent::__construct();
        $this->settingKeys = self::FX_ONLY;
    }
}
