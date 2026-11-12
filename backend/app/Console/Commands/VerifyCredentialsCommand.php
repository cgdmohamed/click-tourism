<?php

namespace App\Console\Commands;

use App\Modules\Core\Models\User;
use App\Modules\Core\Services\InstallationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * Release-gate check: no account may use a known/default password, and a
 * non-demo installation must not contain the demo @example.com accounts.
 * Read-only. Exit code 1 when anything is found.
 */
class VerifyCredentialsCommand extends Command
{
    protected $signature = 'erp:verify-credentials';

    protected $description = 'Fail if any user has a known default password or a demo account exists outside demo mode (read-only)';

    public function handle(): int
    {
        $known = InstallationService::WEAK_PASSWORDS;
        $problems = [];

        foreach (User::query()->orderBy('id')->get(['id', 'email', 'password']) as $user) {
            foreach ($known as $candidate) {
                if (Hash::check($candidate, (string) $user->password)) {
                    $problems[] = "user #{$user->id} uses a known default password";
                    break;
                }
            }

            if (! config('demo.enabled') && str_ends_with(strtolower((string) $user->email), '@example.com')) {
                $problems[] = "user #{$user->id} is a demo-style @example.com account outside demo mode";
            }
        }

        if ($problems === []) {
            $this->info('CREDENTIALS OK: '.User::query()->count().' user(s) checked against '.count($known).' known passwords.');

            return self::SUCCESS;
        }

        foreach ($problems as $problem) {
            $this->error($problem);
        }

        return self::FAILURE;
    }
}
