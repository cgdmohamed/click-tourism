<?php

namespace App\Console\Commands;

use App\Modules\Accounting\Models\Account;
use App\Modules\Core\Services\SettingService;
use Illuminate\Console\Command;

/**
 * Creates the unrealized FX gain/loss accounts on installs whose chart of
 * accounts predates them. Never modifies an existing account.
 *
 * Dry run by default, matching the other accounting repair commands, so an
 * upgrade never writes to the chart of accounts before an operator reviews it.
 */
class EnsureFxAccounts extends Command
{
    protected $signature = 'accounting:ensure-fx-accounts
        {--dry-run : Show which accounts would be created (default)}
        {--apply : Create the missing accounts}';

    protected $description = 'Create the unrealized FX gain/loss accounts (7901/6901) if absent';

    public function handle(SettingService $settings): int
    {
        $apply = (bool) $this->option('apply');
        if ($apply && $this->option('dry-run')) {
            $this->error('Choose either --dry-run or --apply, not both.');

            return self::FAILURE;
        }

        $wanted = [
            [
                (string) $settings->get('accounting', 'fx_unrealized_gain_account_code', '7901'),
                'أرباح فروق العملة غير المحققة', 'Unrealized FX Gain', 'revenue',
            ],
            [
                (string) $settings->get('accounting', 'fx_unrealized_loss_account_code', '6901'),
                'خسائر فروق العملة غير المحققة', 'Unrealized FX Loss', 'expense',
            ],
        ];

        $missing = 0;
        foreach ($wanted as [$code, $nameAr, $nameEn, $type]) {
            if (Account::query()->where('code', $code)->exists()) {
                $this->line("Account {$code} already exists.");

                continue;
            }

            $missing++;
            if (! $apply) {
                $this->line("Would create account {$code} {$nameEn} ({$type}).");

                continue;
            }

            Account::query()->create([
                'code' => $code, 'name_ar' => $nameAr, 'name_en' => $nameEn, 'type' => $type,
                'is_postable' => true, 'is_active' => true,
                'classification' => Account::defaultClassification($type, $code),
            ]);
            $this->info("Created account {$code} {$nameEn}.");
        }

        if (! $apply) {
            $this->comment($missing === 0
                ? 'Dry run only. Nothing to create.'
                : 'Dry run only. Re-run with --apply to create the missing accounts.');
        }

        return self::SUCCESS;
    }
}
