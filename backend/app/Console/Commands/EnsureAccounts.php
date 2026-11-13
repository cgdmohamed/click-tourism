<?php

namespace App\Console\Commands;

use App\Modules\Accounting\Models\Account;
use App\Modules\Core\Services\AuditService;
use App\Modules\Core\Services\SettingService;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Console\Command;

/**
 * Creates accounts the code relies on that older installs may lack: the
 * unrealized FX gain/loss accounts (7901/6901), Bank Interest Income (7920), Sales Commissions (6700) and the inter-branch clearing accounts (1180/2160).
 * Names and types come from ChartOfAccountsSeeder. The 1151-1154 children of
 * 1150 are deliberately NOT created here; `accounting:split-1150` owns them.
 *
 * Dry run by default; never modifies an existing account.
 */
class EnsureAccounts extends Command
{
    /** Setting key => default account code, for the accounts this command owns. */
    public const ACCOUNTS = [
        'fx_unrealized_gain_account_code' => '7901',
        'fx_unrealized_loss_account_code' => '6901',
        'bank_interest_account_code' => '7920',
        'interbranch_receivable_account_code' => '1180',
        'interbranch_payable_account_code' => '2160',
        'commission_expense_account_code' => '6700',
        'accrued_costs_account_code' => '2170',
    ];

    public const FX_ONLY = ['fx_unrealized_gain_account_code', 'fx_unrealized_loss_account_code'];

    protected $signature = 'accounting:ensure-accounts
        {--dry-run : Show which accounts would be created (default)}
        {--apply : Create the missing accounts}';

    protected $description = 'Create accounts newer code relies on (7901, 6901, 7920, 6700, 1180, 2160, 2170) if absent';

    /** @var list<string> */
    protected array $settingKeys = [];

    public function __construct()
    {
        parent::__construct();
        $this->settingKeys = array_keys(self::ACCOUNTS);
    }

    public function handle(SettingService $settings, AuditService $audit): int
    {
        $apply = (bool) $this->option('apply');
        if ($apply && $this->option('dry-run')) {
            $this->error('Choose either --dry-run or --apply, not both.');

            return self::FAILURE;
        }

        $catalog = collect(ChartOfAccountsSeeder::accounts())->keyBy('code');
        $missing = 0;
        $created = [];

        foreach ($this->settingKeys as $key) {
            $default = self::ACCOUNTS[$key];
            $code = (string) $settings->get('accounting', $key, $default);
            // The configured code may be re-coded; name and type come from the default row.
            $row = $catalog->get($default);
            if ($row === null) {
                continue;
            }

            if (Account::query()->where('code', $code)->exists()) {
                $this->line("Account {$code} already exists.");

                continue;
            }

            $missing++;
            if (! $apply) {
                $this->line("Would create account {$code} {$row['name_en']} ({$row['type']}).");

                continue;
            }

            Account::query()->create([
                'code' => $code, 'name_ar' => $row['name_ar'], 'name_en' => $row['name_en'], 'type' => $row['type'],
                'is_postable' => true, 'is_active' => true,
                'classification' => Account::defaultClassification($row['type'], $code),
            ]);
            $created[] = $code;
            $this->info("Created account {$code} {$row['name_en']}.");
        }

        if ($created !== []) {
            $audit->log('accounting.accounts_ensured', null, null, ['codes' => $created], null);
        }

        if (! $apply) {
            $this->comment($missing === 0
                ? 'Dry run only. Nothing to create.'
                : 'Dry run only. Re-run with --apply to create the missing accounts.');
        }

        return self::SUCCESS;
    }
}
