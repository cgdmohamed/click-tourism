<?php

namespace Database\Seeders;

use App\Modules\Core\Services\SettingService;
use Illuminate\Database\Seeder;

/**
 * Materialises every default setting so an accountant can review and edit
 * them in the UI. Items marked PENDING ACCOUNTANT APPROVAL are stored with
 * their provisional value and must be confirmed before go-live.
 */
class SettingSeeder extends Seeder
{
    public function run(): void
    {
        $settings = app(SettingService::class);

        foreach (SettingService::DEFAULTS as $group => $keys) {
            foreach ($keys as $key => $value) {
                $settings->set($group, $key, (string) $value);
            }
        }

        // Explicit review flag: the accounting treatment below is provisional.
        $settings->set('accounting', 'approval_status', 'pending_accountant_review');
        $settings->set(
            'accounting',
            'approval_note',
            'Revenue recognition trigger, tax point, FX policy, commission treatment, closing and branch accounting require accountant sign-off (DEC-001, DEC-002, DEC-007, DEC-010, DEC-019).',
        );
    }
}
