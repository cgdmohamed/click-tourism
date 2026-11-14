<?php

namespace App\Modules\Reports\Services;

use App\Mail\ScheduledReportMail;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Services\AuditService;
use App\Modules\Core\Services\SettingService;
use App\Modules\Reports\Models\ExportJob;
use App\Modules\Reports\Models\ReportSchedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Scheduled report e-mail (ADR-0026, GAP-RPT-06). Each run produces the file through the same
 * background-export path as the user who created the schedule (their permissions and branch), and
 * mails it through the SMTP settings of the server environment. Nothing is mailed when the export
 * fails, and nothing is attempted while the mailer is `log`, `array` or `null`.
 */
class ReportScheduleService
{
    public const PERIODS = ['yesterday', 'previous_week', 'previous_month', 'month_to_date', 'year_to_date', 'last_30_days'];

    public const SEND_HOUR = 6;

    private const MAX_FAILURES = 5;

    public function __construct(
        private readonly ExportJobService $exports,
        private readonly AuditService $audit,
        private readonly SettingService $settings,
    ) {}

    public function mailConfigured(): bool
    {
        return ! in_array((string) config('mail.default'), ['log', 'array', 'null', ''], true);
    }

    /** The first run after `$after` for the given frequency, at 06:00 in the application time zone. */
    public function nextRunAt(string $frequency, ?int $day, ?Carbon $after = null): Carbon
    {
        $after = ($after ?? now())->copy();
        $candidate = $after->copy()->setTime(self::SEND_HOUR, 0);

        for ($i = 0; $i < 400; $i++) {
            $matches = match ($frequency) {
                'weekly' => $candidate->isoWeekday() === ($day ?? 1),
                'monthly' => $candidate->day === ($day ?? 1),
                default => true,
            };
            if ($matches && $candidate->gt($after)) {
                return $candidate;
            }
            $candidate->addDay();
        }

        return $candidate;
    }

    /** @return array{from: string, to: string} */
    public function range(string $period, ?Carbon $now = null): array
    {
        $now = ($now ?? now())->copy();

        [$from, $to] = match ($period) {
            'yesterday' => [$now->copy()->subDay(), $now->copy()->subDay()],
            'previous_week' => [$now->copy()->subWeek()->startOfWeek(), $now->copy()->subWeek()->endOfWeek()],
            'previous_month' => [$now->copy()->subMonthNoOverflow()->startOfMonth(), $now->copy()->subMonthNoOverflow()->endOfMonth()],
            'month_to_date' => [$now->copy()->startOfMonth(), $now->copy()],
            'year_to_date' => [$now->copy()->startOfYear(), $now->copy()],
            default => [$now->copy()->subDays(29), $now->copy()],
        };

        return ['from' => $from->toDateString(), 'to' => $to->toDateString()];
    }

    /** @return array<string, string> */
    public function resolveParams(ReportSchedule $schedule, ?Carbon $now = null): array
    {
        $params = array_map('strval', $schedule->params ?? []);
        if ($schedule->period !== null) {
            $range = $this->range($schedule->period, $now);
            $params = array_merge($params, $range, ['as_of' => $range['to']]);
        }

        return $params;
    }

    public function runDue(): int
    {
        $count = 0;
        foreach (ReportSchedule::query()->where('is_active', true)->where('next_run_at', '<=', now())->get() as $schedule) {
            $this->run($schedule);
            $count++;
        }

        return $count;
    }

    public function run(ReportSchedule $schedule): void
    {
        $status = 'failed';
        $error = null;

        try {
            if (! $this->mailConfigured()) {
                $status = 'mail_not_configured';
                $error = 'SMTP is not configured on the server (MAIL_* in the environment), so nothing was sent.';
            } else {
                [$status, $error] = $this->produceAndSend($schedule);
            }
        } catch (Throwable $e) {
            report($e);
            $error = 'The report could not be sent.';
        }

        $failures = $status === 'failed' ? $schedule->consecutive_failures + 1 : ($status === 'sent' ? 0 : $schedule->consecutive_failures);
        $deactivate = $failures >= self::MAX_FAILURES;
        $schedule->update([
            'last_run_at' => now(),
            'last_status' => $status,
            'last_error' => $error === null ? null : mb_substr($error, 0, 300),
            'consecutive_failures' => min($failures, 255),
            'is_active' => $deactivate ? false : $schedule->is_active,
            'next_run_at' => $this->nextRunAt($schedule->frequency, $schedule->day),
        ]);
        $this->audit->log('report_schedule.'.($status === 'sent' ? 'sent' : 'not_sent'), null, null, [
            'schedule' => $schedule->public_id, 'report' => $schedule->report, 'status' => $status, 'recipients' => count($schedule->recipients ?? []), 'deactivated' => $deactivate,
        ], $schedule->user_id, $schedule->branch_id);
    }

    /** @return array{0: string, 1: ?string} */
    private function produceAndSend(ReportSchedule $schedule): array
    {
        $user = $schedule->user;
        if ($user === null || ! $user->is_active) {
            return ['failed', 'The user who created the schedule is no longer active.'];
        }
        $branch = $schedule->branch_id ? Branch::query()->find($schedule->branch_id) : null;
        $params = $this->resolveParams($schedule);

        $job = $this->exports->queue($user, $branch, $schedule->report, $schedule->format, $params);
        $this->exports->run($job);
        $job->refresh();

        try {
            if ($job->status !== 'succeeded' || $job->file_path === null) {
                return ['failed', $job->error_message ?: 'The report could not be produced.'];
            }
            $disk = Storage::disk(config('exports.disk'));
            if (! $disk->exists($job->file_path)) {
                return ['failed', 'The report file is missing.'];
            }

            $period = $params['from'] ?? null ? ($params['from'].' - '.($params['to'] ?? '')) : now()->toDateString();
            $company = (string) $this->settings->get('general', 'company_name_en', config('app.name'));
            Mail::to($schedule->recipients)->send(new ScheduledReportMail(
                $schedule->name ?: $schedule->report, $period, (string) $job->file_name, $disk->get($job->file_path), (string) $job->mime, $company,
            ));

            return ['sent', null];
        } finally {
            // The file is in the mail now; the job row and file are not kept.
            $this->exports->delete(ExportJob::query()->find($job->id) ?? $job);
        }
    }
}
