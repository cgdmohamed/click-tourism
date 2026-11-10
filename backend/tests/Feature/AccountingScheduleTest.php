<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\AccountingDocument;
use App\Modules\Accounting\Models\AccountingSchedule;
use App\Modules\Accounting\Models\AccountingScheduleEvent;
use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\AccountingScheduleService;
use App\Modules\Accounting\Services\PostingLine;
use App\Modules\Accounting\Services\PostingRequest;
use App\Modules\Accounting\Services\PostingService;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\BuildsAccounting;
use Tests\TestCase;

/**
 * Prompt 24 — accruals, prepayments and period-end adjustments.
 *
 * The important guarantees are: events add up to the schedule total exactly,
 * reruns never duplicate a posting, a closed period produces an exception
 * instead of a silent skip, and cancellation keeps posted history.
 */
class AccountingScheduleTest extends TestCase
{
    use BuildsAccounting;
    use RefreshDatabase;

    private const PERMISSIONS = [
        'schedules.view', 'schedules.manage', 'schedules.approve',
        'periods.view', 'periods.manage',
        'journal.view', 'journal.create',
        'documents.view', 'documents.create',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAccounting();

        foreach (self::PERMISSIONS as $permission) {
            Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->user->givePermissionTo(self::PERMISSIONS);

        Sanctum::actingAs($this->user);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function headers(?Branch $branch = null): array
    {
        return ['X-Branch-Id' => ($branch ?? $this->branch)->public_id];
    }

    private function monthStart(int $offsetMonths = 0): string
    {
        return now()->startOfMonth()->addMonthsNoOverflow($offsetMonths)->toDateString();
    }

    private function currentMonthEnd(): string
    {
        return now()->endOfMonth()->toDateString();
    }

    private function nextMonthStart(): string
    {
        return now()->startOfMonth()->addMonthNoOverflow()->toDateString();
    }

    private function currentPeriod(): FiscalPeriod
    {
        return FiscalPeriod::query()->where('name', now()->format('Y-m'))->firstOrFail();
    }

    private function createNextPeriod(): FiscalPeriod
    {
        $year = FiscalYear::query()->firstOrFail();

        return FiscalPeriod::query()->create([
            'fiscal_year_id' => $year->id,
            'name' => now()->startOfMonth()->addMonthNoOverflow()->format('Y-m'),
            'starts_on' => $this->nextMonthStart(),
            'ends_on' => now()->startOfMonth()->addMonthNoOverflow()->endOfMonth()->toDateString(),
            'status' => 'open',
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function createSchedule(array $overrides = []): TestResponse
    {
        return $this->withHeaders($this->headers())->postJson('/api/v1/accounting-schedules', $overrides + [
            'kind' => AccountingSchedule::KIND_PREPAID_EXPENSE,
            'description' => 'Annual insurance premium',
            'total_minor' => 120000,
            'periods' => 1,
            'starts_on' => $this->monthStart(),
            'source_account_code' => '1150',
            'target_account_code' => '6100',
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function createDraft(array $overrides = []): AccountingSchedule
    {
        $response = $this->createSchedule($overrides)->assertCreated();

        return AccountingSchedule::query()
            ->with('events')
            ->findOrFail(AccountingSchedule::query()
                ->where('number', $response->json('data.number'))
                ->firstOrFail()
                ->id);
    }

    /** @return array<string, mixed> */
    private function runDue(string $asOf): array
    {
        return app(AccountingScheduleService::class)->runDue($this->user->id, Carbon::parse($asOf));
    }

    // ------------------------------------------------------------------
    // Allocation & rounding
    // ------------------------------------------------------------------

    public function test_allocation_events_add_up_to_the_schedule_total_with_the_residual_on_the_last_period(): void
    {
        $schedule = $this->createDraft([
            'total_minor' => 120000,
            'periods' => 7,
            'starts_on' => $this->monthStart(1),
        ]);

        $events = $schedule->events->where('kind', AccountingScheduleEvent::KIND_RECOGNITION)->values();

        $this->assertCount(7, $events);
        $this->assertSame(120000, (int) $events->sum('amount_minor'));

        // 120000 / 7 = 17142 remainder 6, so the last event carries 17148.
        $this->assertSame(17142, $events[0]->amount_minor);
        $this->assertSame(17142, $events[5]->amount_minor);
        $this->assertSame(17148, $events[6]->amount_minor);
        $this->assertSame(120000, (int) $schedule->events->sum('amount_minor'));
    }

    public function test_accrual_schedules_generate_a_mirrored_reversal_event(): void
    {
        $schedule = $this->createDraft([
            'kind' => AccountingSchedule::KIND_ACCRUED_EXPENSE,
            'source_account_code' => '2100',
            'target_account_code' => '6100',
            'reversal_policy' => AccountingSchedule::REVERSAL_NEXT_PERIOD,
            'starts_on' => $this->monthStart(1),
        ]);

        $recognition = $schedule->events->firstWhere('kind', AccountingScheduleEvent::KIND_RECOGNITION);
        $reversal = $schedule->events->firstWhere('kind', AccountingScheduleEvent::KIND_REVERSAL);

        $this->assertNotNull($recognition);
        $this->assertNotNull($reversal);
        $this->assertSame($recognition->id, $reversal->reverses_event_id);
        $this->assertSame($recognition->amount_minor, $reversal->amount_minor);
    }

    // ------------------------------------------------------------------
    // Posting & retry safety
    // ------------------------------------------------------------------

    public function test_due_event_posts_once_and_a_rerun_creates_no_duplicates(): void
    {
        $schedule = $this->createDraft(['periods' => 1]);

        $this->withHeaders($this->headers())
            ->postJson("/api/v1/accounting-schedules/{$schedule->public_id}/activate")
            ->assertOk();

        $first = $this->runDue($this->currentMonthEnd());
        $this->assertSame(1, $first['processed']);
        $this->assertSame(1, $first['posted']);

        $second = $this->runDue($this->currentMonthEnd());
        $this->assertSame(0, $second['processed']);
        $this->assertSame(0, $second['failed']);

        $this->assertSame(1, JournalEntry::query()
            ->where('source_type', AccountingScheduleService::SOURCE_TYPE)
            ->where('source_id', $schedule->id)
            ->count());

        $schedule->refresh();
        $this->assertSame(AccountingSchedule::STATUS_COMPLETED, $schedule->status);
        $this->assertSame(120000, $schedule->recognized_minor);

        $event = $schedule->events()->firstOrFail();
        $this->assertSame(AccountingScheduleEvent::STATUS_POSTED, $event->status);
        $this->assertNotNull($event->journal_entry_id);
    }

    public function test_closed_period_raises_an_exception_and_can_be_retried_after_reopening(): void
    {
        $schedule = $this->createDraft(['periods' => 1]);

        $this->withHeaders($this->headers())
            ->postJson("/api/v1/accounting-schedules/{$schedule->public_id}/activate")
            ->assertOk();

        $period = $this->currentPeriod();
        $period->update(['status' => 'closed']);

        $result = $this->runDue($this->currentMonthEnd());
        $this->assertSame(1, $result['failed']);
        $this->assertStringContainsString('closed', (string) $result['results'][0]['reason']);

        $event = $schedule->events()->firstOrFail()->refresh();
        $this->assertSame(AccountingScheduleEvent::STATUS_FAILED, $event->status);
        $this->assertStringContainsString('closed', (string) $event->failure_reason);

        $exceptions = $this->withHeaders($this->headers())
            ->getJson('/api/v1/accounting-schedules/exceptions')
            ->assertOk()
            ->json('data');
        $this->assertSame($event->public_id, $exceptions[0]['id']);

        // Nothing reached the ledger while the period was closed.
        $this->assertSame(0, JournalEntry::query()
            ->where('source_type', AccountingScheduleService::SOURCE_TYPE)
            ->count());

        $period->update(['status' => 'open']);

        $this->withHeaders($this->headers())
            ->postJson("/api/v1/accounting-schedules/{$schedule->public_id}/events/{$event->public_id}/post")
            ->assertOk()
            ->assertJsonPath('data.status', AccountingSchedule::STATUS_COMPLETED);

        $this->assertSame(AccountingScheduleEvent::STATUS_POSTED, $event->refresh()->status);
        $this->assertSame(1, JournalEntry::query()
            ->where('source_type', AccountingScheduleService::SOURCE_TYPE)
            ->count());
    }

    public function test_accrual_reversal_posts_in_the_next_period_without_touching_the_original_journal(): void
    {
        $this->createNextPeriod();

        $schedule = $this->createDraft([
            'kind' => AccountingSchedule::KIND_ACCRUED_EXPENSE,
            'source_account_code' => '2100',
            'target_account_code' => '6100',
            'reversal_policy' => AccountingSchedule::REVERSAL_NEXT_PERIOD,
            'periods' => 1,
        ]);

        $this->withHeaders($this->headers())
            ->postJson("/api/v1/accounting-schedules/{$schedule->public_id}/activate")
            ->assertOk();

        $this->runDue($this->currentMonthEnd());

        $recognition = $schedule->events()
            ->where('kind', AccountingScheduleEvent::KIND_RECOGNITION)
            ->firstOrFail()
            ->refresh();
        $this->assertSame(AccountingScheduleEvent::STATUS_POSTED, $recognition->status);

        $original = JournalEntry::query()->with('lines.account')->findOrFail($recognition->journal_entry_id);
        $originalLines = $original->lines->map(fn ($line) => [
            'account' => $line->account->code,
            'debit' => $line->debit_minor,
            'credit' => $line->credit_minor,
        ])->all();

        $this->runDue($this->nextMonthStart());

        $reversal = $schedule->events()
            ->where('kind', AccountingScheduleEvent::KIND_REVERSAL)
            ->firstOrFail()
            ->refresh();
        $this->assertSame(AccountingScheduleEvent::STATUS_POSTED, $reversal->status);
        $this->assertNotSame($recognition->journal_entry_id, $reversal->journal_entry_id);

        // The original journal is untouched; the reversal is a separate entry.
        $original->refresh()->load('lines.account');
        $this->assertSame('posted', $original->status);
        $this->assertSame($originalLines, $original->lines->map(fn ($line) => [
            'account' => $line->account->code,
            'debit' => $line->debit_minor,
            'credit' => $line->credit_minor,
        ])->all());

        $reversalJournal = JournalEntry::query()->with('lines.account')->findOrFail($reversal->journal_entry_id);
        $this->assertSame($this->nextMonthStart(), $reversalJournal->entry_date->toDateString());
        $this->assertSame(['2100' => 120000, '6100' => 120000], $reversalJournal->lines
            ->groupBy(fn ($line) => $line->account->code)
            ->map(fn ($lines) => (int) $lines->sum('debit_minor') + (int) $lines->sum('credit_minor'))
            ->all());

        $this->assertSame(2, JournalEntry::query()
            ->where('source_type', AccountingScheduleService::SOURCE_TYPE)
            ->count());
    }

    // ------------------------------------------------------------------
    // Cancellation & reclassification
    // ------------------------------------------------------------------

    public function test_cancellation_preserves_posted_history_and_proposes_a_write_off_voucher(): void
    {
        $schedule = $this->createDraft(['periods' => 3]);

        $this->withHeaders($this->headers())
            ->postJson("/api/v1/accounting-schedules/{$schedule->public_id}/activate")
            ->assertOk();

        $this->runDue($this->currentMonthEnd());

        $posted = $schedule->events()
            ->where('kind', AccountingScheduleEvent::KIND_RECOGNITION)
            ->where('status', AccountingScheduleEvent::STATUS_POSTED)
            ->firstOrFail();
        $postedJournal = $posted->journal_entry_id;

        $this->withHeaders($this->headers())
            ->postJson("/api/v1/accounting-schedules/{$schedule->public_id}/cancel", [
                'reason' => 'Policy cancelled by the insurer',
                'write_off' => true,
                'submit_write_off' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.schedule.status', AccountingSchedule::STATUS_CANCELLED)
            ->assertJsonPath('data.adjustment_document.type', AccountingDocument::TYPE_ADJUSTMENT_VOUCHER)
            ->assertJsonPath('data.adjustment_document.status', AccountingDocument::STATUS_DRAFT);

        $schedule->refresh();
        $this->assertSame(AccountingSchedule::STATUS_CANCELLED, $schedule->status);

        // Posted history survives; only open recognitions were cancelled.
        $posted->refresh();
        $this->assertSame(AccountingScheduleEvent::STATUS_POSTED, $posted->status);
        $this->assertSame($postedJournal, $posted->journal_entry_id);
        $this->assertSame(2, $schedule->events()
            ->where('status', AccountingScheduleEvent::STATUS_CANCELLED)
            ->count());

        $document = AccountingDocument::query()
            ->where('type', AccountingDocument::TYPE_ADJUSTMENT_VOUCHER)
            ->with('lines')
            ->firstOrFail();
        $this->assertSame(80000, (int) $document->lines->sum('debit_minor'));
        $this->assertSame(80000, (int) $document->lines->sum('credit_minor'));
        $this->assertContains($document->public_id, $schedule->adjustment_document_ids);
        $this->assertSame(1, JournalEntry::query()
            ->where('source_type', AccountingScheduleService::SOURCE_TYPE)
            ->count());
    }

    // ------------------------------------------------------------------
    // Access control
    // ------------------------------------------------------------------

    public function test_schedules_are_branch_scoped_and_require_permissions(): void
    {
        $schedule = $this->createDraft();

        $otherBranch = Branch::query()->create([
            'code' => 'BR2',
            'name_ar' => 'فرع آخر',
            'name_en' => 'Other Branch',
            'accounting_mode' => 'dimension',
            'is_active' => true,
        ]);

        $scopedUser = User::factory()->create();
        $scopedUser->branches()->attach($otherBranch->id);
        $scopedUser->givePermissionTo(self::PERMISSIONS);

        Sanctum::actingAs($scopedUser);
        $this->withHeaders($this->headers($otherBranch))
            ->getJson("/api/v1/accounting-schedules/{$schedule->public_id}")
            ->assertForbidden();

        $viewer = User::factory()->create();
        $viewer->branches()->attach($this->branch->id);

        Sanctum::actingAs($viewer);
        $this->withHeaders($this->headers())
            ->getJson('/api/v1/accounting-schedules')
            ->assertForbidden();
    }

    // ------------------------------------------------------------------
    // Period-end checklist
    // ------------------------------------------------------------------

    public function test_period_end_checklist_reports_draft_documents_schedules_and_ties_the_trial_balance(): void
    {
        $period = $this->currentPeriod();

        $empty = $this->withHeaders($this->headers())
            ->getJson("/api/v1/fiscal-periods/{$period->id}/checklist")
            ->assertOk()
            ->json('data');

        $keys = collect($empty['checks'])->pluck('key')->all();
        $this->assertContains('unposted_documents', $keys);
        $this->assertContains('unresolved_reconciliations', $keys);
        $this->assertContains('draft_schedules', $keys);
        $this->assertContains('negative_cash', $keys);
        $this->assertContains('trial_balance', $keys);
        $this->assertTrue($empty['can_close']);

        // A draft voucher, a submitted schedule and a draft journal must block.
        $this->createDraft(['periods' => 1]);

        app(PostingService::class)->draft(PostingRequest::make(
            entryDate: $this->monthStart(),
            branch: $this->branch,
            currency: 'EGP',
            lines: [
                PostingLine::debit($this->account('6100'), 5000, null, null, 'Accrual draft'),
                PostingLine::credit($this->account('2100'), 5000, null, null, 'Accrual draft'),
            ],
            memo: 'Draft accrual',
            actorId: $this->user->id,
        ));

        $checklist = $this->withHeaders($this->headers())
            ->getJson("/api/v1/fiscal-periods/{$period->id}/checklist")
            ->assertOk()
            ->json('data');

        $checks = collect($checklist['checks'])->keyBy('key');

        $this->assertFalse($checks['draft_schedules']['passed']);
        $this->assertSame(1, $checks['draft_schedules']['count']);
        $this->assertFalse($checks['draft_entries']['passed']);
        $this->assertTrue($checks['trial_balance']['passed']);
        $this->assertFalse($checklist['can_close']);
    }
}
