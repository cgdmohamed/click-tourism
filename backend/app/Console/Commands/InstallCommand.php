<?php

namespace App\Console\Commands;

use App\Modules\Core\Services\InstallationService;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * One-time first-run setup of a self-hosted installation.
 *
 * The administrator password is NEVER a command-line argument (shell history,
 * process list). It comes from ERP_ADMIN_PASSWORD, ERP_ADMIN_PASSWORD_FILE or
 * a masked prompt. Without one the command fails closed.
 */
class InstallCommand extends Command
{
    protected $signature = 'erp:install
        {--company-name= : Company name (English). Env ERP_COMPANY_NAME}
        {--company-name-ar= : Company name (Arabic), defaults to the English name. Env ERP_COMPANY_NAME_AR}
        {--logo-path= : Relative storage path of the logo, e.g. branding/logo.png. Env ERP_LOGO_PATH}
        {--base-currency= : ISO code of the base currency (EGP, SAR, USD, EUR, AED, KWD). Env ERP_BASE_CURRENCY}
        {--fiscal-start=01-01 : Fiscal year start as MM-DD. Env ERP_FISCAL_START}
        {--timezone=UTC : IANA timezone. Env ERP_TIMEZONE}
        {--locale=en : Default language (ar or en). Env ERP_LOCALE}
        {--tax-enabled : Turn tax calculation on (default off). Env ERP_TAX_ENABLED}
        {--branch-name=Head Office : Head-office branch name (English). Env ERP_BRANCH_NAME}
        {--admin-name=Administrator : Administrator display name. Env ERP_ADMIN_NAME}
        {--admin-email= : Administrator e-mail. Env ERP_ADMIN_EMAIL}
        {--dry-run : Validate everything and write nothing}';

    protected $description = 'First-run setup: company profile, chart of accounts, roles and the initial administrator (no demo data)';

    public function handle(InstallationService $installer): int
    {
        if ($reason = $installer->refusalReason()) {
            $this->error($reason);

            return self::FAILURE;
        }

        $password = $this->resolvePassword();
        if ($password === null) {
            $this->error('No administrator password supplied. Set ERP_ADMIN_PASSWORD or ERP_ADMIN_PASSWORD_FILE, or run interactively. There is no default password.');

            return self::FAILURE;
        }

        $input = [
            'company_name_en' => $this->value('company-name', 'ERP_COMPANY_NAME'),
            'company_name_ar' => $this->value('company-name-ar', 'ERP_COMPANY_NAME_AR'),
            'logo_path' => $this->value('logo-path', 'ERP_LOGO_PATH'),
            'base_currency' => $this->value('base-currency', 'ERP_BASE_CURRENCY'),
            'fiscal_start' => $this->value('fiscal-start', 'ERP_FISCAL_START', '01-01'),
            'timezone' => $this->value('timezone', 'ERP_TIMEZONE', 'UTC'),
            'locale' => $this->value('locale', 'ERP_LOCALE', 'en'),
            'tax_enabled' => $this->option('tax-enabled') || filter_var(env('ERP_TAX_ENABLED', false), FILTER_VALIDATE_BOOL),
            'branch_name_en' => $this->value('branch-name', 'ERP_BRANCH_NAME', 'Head Office'),
            'branch_name_ar' => null,
            'admin_name' => $this->value('admin-name', 'ERP_ADMIN_NAME', 'Administrator'),
            'admin_email' => $this->value('admin-email', 'ERP_ADMIN_EMAIL'),
            'admin_password' => $password,
        ];

        try {
            $data = $installer->validate($input);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->error($message);
                }
            }

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->info('Dry run: input is valid. Nothing was written.');

            return self::SUCCESS;
        }

        $installer->install($data);

        $this->info('Installation complete.');
        $this->line("Company:        {$data['company_name_en']}");
        $this->line("Base currency:  {$data['base_currency']}");
        $this->line("Fiscal start:   {$data['fiscal_start']}");
        $this->line("Administrator:  {$data['admin_email']}");
        $this->line('No demo accounts or sample data were installed.');
        $this->warn('Accounting policy is NOT approved: auto-posting stays off until an accountant signs off.');
        $this->warn('Remove ERP_ADMIN_PASSWORD from the environment now that the account exists.');

        return self::SUCCESS;
    }

    private function value(string $option, string $envKey, ?string $default = null): ?string
    {
        $value = $this->option($option);
        // Options with a built-in default report that default; let the env var win when the option was not typed.
        $declared = $this->getDefinition()->getOption($option)->getDefault();
        if ($value === null || $value === '' || $value === $declared) {
            $fromEnv = env($envKey);
            if (is_string($fromEnv) && trim($fromEnv) !== '') {
                return trim($fromEnv);
            }
        }

        return ($value === null || $value === '') ? $default : (string) $value;
    }

    private function resolvePassword(): ?string
    {
        $fromEnv = env('ERP_ADMIN_PASSWORD');
        if (is_string($fromEnv) && $fromEnv !== '') {
            return $fromEnv;
        }

        $file = env('ERP_ADMIN_PASSWORD_FILE');
        if (is_string($file) && $file !== '') {
            if (! is_readable($file)) {
                $this->error('ERP_ADMIN_PASSWORD_FILE is not readable.');

                return null;
            }

            return rtrim((string) file_get_contents($file), "\r\n");
        }

        if (! $this->input->isInteractive()) {
            return null;
        }

        $password = (string) $this->secret('Initial administrator password (minimum 12 characters)');
        $confirmation = (string) $this->secret('Repeat the password');

        if ($password === '' || ! hash_equals($password, $confirmation)) {
            $this->error('Passwords are empty or do not match.');

            return null;
        }

        return $password;
    }
}
