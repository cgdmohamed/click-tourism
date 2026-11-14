<?php

namespace Tests\Feature;

use App\Mail\ScheduledReportMail;
use App\Modules\Core\Models\AuditEvent;
use App\Modules\Core\Models\Notification;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\NotificationService;
use App\Modules\Reports\Models\ExportJob;
use App\Modules\Reports\Models\ReportSchedule;
use App\Modules\Reports\Services\ReportScheduleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\BuildsAccounting;
use Tests\TestCase;

/** GAP-RPT-06 (ADR-0026): reports e-mailed on a schedule, through the SMTP settings of the environment. */
class ReportScheduleTest extends TestCase
{
    use BuildsAccounting;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAccounting();
        Storage::fake(config('exports.disk'));
        Mail::fake();
        foreach (['reports.view', 'reports.export', 'reports.schedule', 'accounts.view'] as $name) {
            Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->user->givePermissionTo(['reports.view', 'reports.export', 'reports.schedule', 'accounts.view']);
        Sanctum::actingAs($this->user, ['*']);
        config()->set('mail.default', 'smtp');
    }

    private function create(array $over = []): TestResponse
    {
        return $this->postJson('/api/v1/report-schedules', $over + [
            'name' => 'Monthly trial balance', 'report' => 'trial-balance', 'format' => 'xlsx', 'frequency' => 'monthly', 'day' => 1,
            'period' => 'previous_month', 'recipients' => ['Boss@Example.com', 'cfo@example.com'],
        ]);
    }

    public function test_a_schedule_is_created_for_its_owner_with_the_next_run(): void
    {
        $row = $this->create()->assertCreated()->json('data');

        $this->assertSame(['boss@example.com', 'cfo@example.com'], $row['recipients']);
        $this->assertTrue($row['is_active']);
        $this->assertSame(1, Carbon::parse($row['next_run_at'])->day);
        $this->assertSame(6, Carbon::parse($row['next_run_at'])->hour);
        $this->assertNotEmpty(AuditEvent::query()->where('action', 'report_schedule.created')->get());
    }

    public function test_validation_and_permissions(): void
    {
        $this->create(['recipients' => []])->assertUnprocessable();
        $this->create(['recipients' => ['not-an-email']])->assertUnprocessable();
        $this->create(['report' => 'users'])->assertUnprocessable();
        $this->create(['frequency' => 'hourly'])->assertUnprocessable();
        $this->create(['period' => 'next_year'])->assertUnprocessable();
        $this->create(['recipients' => array_map(fn ($i) => "a{$i}@example.com", range(1, 11))])->assertUnprocessable();

        $noSchedule = User::factory()->create();
        $noSchedule->givePermissionTo('reports.export');
        Sanctum::actingAs($noSchedule, ['*']);
        $this->create()->assertForbidden();
        $this->getJson('/api/v1/report-schedules')->assertForbidden();
    }

    public function test_only_the_owner_sees_changes_or_runs_a_schedule(): void
    {
        $id = $this->create()->assertCreated()->json('data.id');

        $other = User::factory()->create();
        $other->givePermissionTo(['reports.schedule', 'reports.export']);
        Sanctum::actingAs($other, ['*']);
        $this->getJson('/api/v1/report-schedules')->assertOk()->assertJsonCount(0, 'data');
        $this->patchJson('/api/v1/report-schedules/'.$id, ['is_active' => false])->assertNotFound();
        $this->postJson('/api/v1/report-schedules/'.$id.'/run')->assertNotFound();
        $this->deleteJson('/api/v1/report-schedules/'.$id)->assertNotFound();
    }

    public function test_running_a_schedule_mails_the_file_to_every_recipient_and_keeps_no_copy(): void
    {
        $id = $this->create()->assertCreated()->json('data.id');

        $row = $this->postJson('/api/v1/report-schedules/'.$id.'/run')->assertOk()->json('data');

        $this->assertSame('sent', $row['last_status'], json_encode($row));
        Mail::assertSent(ScheduledReportMail::class, function (ScheduledReportMail $mail) {
            return $mail->hasTo('boss@example.com') && $mail->hasTo('cfo@example.com') && count($mail->attachments()) === 1;
        });
        $this->assertSame(0, ExportJob::query()->count(), 'the export job and its file are not kept');
        $this->assertSame([], Storage::disk(config('exports.disk'))->allFiles());
        $this->assertNotEmpty(AuditEvent::query()->where('action', 'report_schedule.sent')->get());
    }

    public function test_nothing_is_sent_while_the_mailer_is_not_configured(): void
    {
        config()->set('mail.default', 'log');
        $id = $this->create()->assertCreated()->json('data.id');

        $row = $this->postJson('/api/v1/report-schedules/'.$id.'/run')->assertOk()->json('data');

        $this->assertSame('mail_not_configured', $row['last_status']);
        Mail::assertNothingSent();
        $this->assertTrue($row['is_active'], 'a missing SMTP setting does not count as a failure');
        $this->assertSame(0, Notification::query()->where('type', 'report_schedule.failed')->count());
        $this->getJson('/api/v1/feature-flags')->assertJsonPath('data.mail_configured', false);
    }

    public function test_a_failing_report_sends_nothing_and_five_failures_pause_the_schedule(): void
    {
        // The general ledger needs an account, so the export fails inside the job.
        $id = $this->create(['report' => 'general-ledger', 'period' => 'previous_month'])->assertCreated()->json('data.id');

        for ($i = 0; $i < 4; $i++) {
            $row = $this->postJson('/api/v1/report-schedules/'.$id.'/run')->json('data');
            $this->assertSame('failed', $row['last_status']);
            $this->assertTrue($row['is_active']);
        }
        $row = $this->postJson('/api/v1/report-schedules/'.$id.'/run')->json('data');

        $this->assertFalse($row['is_active']);
        $this->assertNotEmpty($row['last_error']);
        Mail::assertNothingSent();

        // Each failed run tells the owner; the last one says the schedule was switched off.
        $notices = Notification::query()->where('type', 'report_schedule.failed')->orderBy('id')->get();
        $this->assertCount(5, $notices);
        $this->assertSame([$this->user->id], $notices->pluck('user_id')->unique()->values()->all());
        $this->assertStringContainsString('switched off', (string) $notices->last()->body);
        $this->assertStringNotContainsString('switched off', (string) $notices->first()->body);

        $this->patchJson('/api/v1/report-schedules/'.$id, ['is_active' => true])->assertOk()->assertJsonPath('data.is_active', true);
    }

    public function test_a_notification_that_cannot_be_saved_does_not_stop_the_run_or_its_audit_event(): void
    {
        $this->mock(NotificationService::class, function ($mock) {
            $mock->shouldReceive('send')->andThrow(new \RuntimeException('notifications table is unavailable'));
        });
        $id = $this->create(['report' => 'general-ledger', 'period' => 'previous_month'])->assertCreated()->json('data.id');

        $row = $this->postJson('/api/v1/report-schedules/'.$id.'/run')->assertOk()->json('data');

        $this->assertSame('failed', $row['last_status']);
        $this->assertNotEmpty(AuditEvent::query()->where('action', 'report_schedule.not_sent')->get());
    }

    public function test_due_schedules_are_sent_by_the_command_and_future_ones_are_left(): void
    {
        $due = $this->create()->assertCreated()->json('data.id');
        $later = $this->create(['name' => 'later'])->assertCreated()->json('data.id');
        ReportSchedule::query()->where('public_id', $due)->update(['next_run_at' => now()->subMinute()]);

        $this->artisan('reports:send-scheduled')->assertSuccessful();

        Mail::assertSent(ScheduledReportMail::class, 1);
        $this->assertSame('sent', ReportSchedule::query()->where('public_id', $due)->value('last_status'));
        $this->assertNull(ReportSchedule::query()->where('public_id', $later)->value('last_status'));
        $this->assertTrue(ReportSchedule::query()->where('public_id', $due)->first()->next_run_at->isFuture());
    }

    public function test_a_deactivated_owner_never_gets_a_report_sent(): void
    {
        $id = $this->create()->assertCreated()->json('data.id');
        $this->user->forceFill(['is_active' => false])->save();

        app(ReportScheduleService::class)->run(ReportSchedule::query()->where('public_id', $id)->firstOrFail());

        Mail::assertNothingSent();
        $this->assertSame('failed', ReportSchedule::query()->where('public_id', $id)->value('last_status'));
    }

    public function test_period_presets_and_next_run_calculation(): void
    {
        $service = app(ReportScheduleService::class);
        $now = Carbon::parse('2026-11-14 10:00:00');

        $this->assertSame(['from' => '2026-10-01', 'to' => '2026-10-31'], $service->range('previous_month', $now));
        $this->assertSame(['from' => '2026-11-01', 'to' => '2026-11-14'], $service->range('month_to_date', $now));
        $this->assertSame(['from' => '2026-01-01', 'to' => '2026-11-14'], $service->range('year_to_date', $now));
        $this->assertSame(['from' => '2026-11-13', 'to' => '2026-11-13'], $service->range('yesterday', $now));
        $this->assertSame('2026-11-15 06:00:00', $service->nextRunAt('daily', null, $now)->toDateTimeString());
        $this->assertSame('2026-11-16 06:00:00', $service->nextRunAt('weekly', 1, $now)->toDateTimeString());
        $this->assertSame('2026-12-01 06:00:00', $service->nextRunAt('monthly', 1, $now)->toDateTimeString());
        $this->assertSame('2026-11-14 06:00:00', $service->nextRunAt('daily', null, Carbon::parse('2026-11-14 05:00:00'))->toDateTimeString());
    }
}
