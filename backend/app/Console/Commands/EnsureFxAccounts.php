<?php

namespace App\Console\Commands;

use App\Modules\Accounting\Models\Account;
use App\Modules\Core\Services\SettingService;
use Illuminate\Console\Command;

/**
 * Creates the unrealized FX gain/loss accounts on installs whose chart of
 * accounts predates them. Never modifies an existing account.
 */
class EnsureFxAccounts extends Command
{
    protected $signature = 'accounting:ensure-fx-accounts';

    protected $description = 'Create the unrealized FX gain/loss accounts (7901/6901) if absent';

    public function handle(SettingService $settings): int
    {
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

        foreach ($wanted as [$code, $nameAr, $nameEn, $type]) {
            if (Account::query()->where('code', $code)->exists()) {
                $this->line("Account {$code} already exists.");

                continue;
            }
            Account::query()->create([
                'code' => $code, 'name_ar' => $nameAr, 'name_en' => $nameEn, 'type' => $type,
                'is_postable' => true, 'is_active' => true,
            ]);
            $this->info("Created account {$code} {$nameEn}.");
        }

        return self::SUCCESS;
    }
}
