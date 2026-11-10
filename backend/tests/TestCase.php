<?php

namespace Tests;

use App\Modules\Core\Services\SettingService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Enable accounting auto-posting for tests that need to post journal
     * entries. In production this defaults to false until an accountant
     * approves the accounting policy; tests opt in explicitly.
     */
    protected function enableAccountingAutoPost(): void
    {
        app(SettingService::class)->set('accounting', 'accounting_policy_approved', 'true');
    }
}
