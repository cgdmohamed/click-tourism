<?php

namespace App\Console\Commands;

use App\Modules\CRM\Services\LeadService;
use Illuminate\Console\Command;

/**
 * Runs the configured lead inactivity reassignment (GAP-CRM-01).
 *
 * LeadService locks each lead, measures inactivity in configured business
 * hours, and writes an activity + audit row for every reassignment or
 * escalation. A rerun immediately afterwards is a no-op because the lead's
 * last_activity_at is moved to now on every attempt.
 */
class ReassignStaleLeads extends Command
{
    protected $signature = 'crm:reassign-stale-leads';

    protected $description = 'Reassign or escalate open leads inactive beyond the configured business-hour window';

    public function handle(LeadService $leads): int
    {
        $count = $leads->reassignStale();

        $this->info("{$count} lead(s) reassigned or escalated.");

        return self::SUCCESS;
    }
}
