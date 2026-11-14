<?php

namespace App\Http\Controllers\Api;

use App\Modules\Accounting\Models\AccountingSchedule;
use App\Modules\Accounting\Models\AccountingScheduleEvent;
use App\Modules\Accounting\Services\AccountingScheduleService;
use App\Support\Like;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Accruals, prepayments and period-end adjustment schedules (Prompt 24).
 *
 * Creating and editing only ever touches a draft schedule. Activation is the
 * approval step; posting is delegated to AccountingScheduleService, which
 * writes through PostingService only.
 */
class AccountingScheduleController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $branch = $this->branch();

        $schedules = AccountingSchedule::query()
            ->with('branch', 'sourceAccount', 'targetAccount', 'events.journalEntry', 'events.reversesEvent')
            ->when($branch, fn ($query) => $query->where('branch_id', $branch->id))
            ->when($request->query('kind'), fn ($query, $kind) => $query->where('kind', $kind))
            ->when($request->query('status'), fn ($query, $status) => $query->where('status', $status))
            ->when(Like::term($request->query('search')), fn ($query, $search) => Like::contains(
                $query, ['number', 'description', 'reference'], $search))
            ->orderByDesc('starts_on')
            ->orderByDesc('id')
            ->get();

        return $this->ok($schedules->map(fn (AccountingSchedule $schedule) => $schedule->toApiArray())->values());
    }

    public function upcoming(Request $request, AccountingScheduleService $service): JsonResponse
    {
        $data = $request->validate([
            'days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        return $this->ok($service->upcoming($this->branch(), (int) ($data['days'] ?? 30)));
    }

    public function exceptions(AccountingScheduleService $service): JsonResponse
    {
        return $this->ok($service->exceptions($this->branch()));
    }

    public function store(Request $request, AccountingScheduleService $service): JsonResponse
    {
        $data = $request->validate($this->rules());

        $schedule = $service->create($data, $this->branchOrFail(), $this->actorId());

        return $this->created($schedule->toApiArray());
    }

    public function show(AccountingSchedule $schedule, AccountingScheduleService $service): JsonResponse
    {
        $this->authorizeBranchId($schedule->branch_id);

        return $this->ok($service->load($schedule)->toApiArray());
    }

    public function update(Request $request, AccountingSchedule $schedule, AccountingScheduleService $service): JsonResponse
    {
        $this->authorizeBranchId($schedule->branch_id);

        $data = $request->validate($this->rules(true));

        return $this->ok($service->update($schedule, $data, $this->actorId())->toApiArray());
    }

    public function activate(AccountingSchedule $schedule, AccountingScheduleService $service): JsonResponse
    {
        $this->authorizeBranchId($schedule->branch_id);

        return $this->ok($service->activate($schedule, $this->actorId())->toApiArray());
    }

    public function cancel(Request $request, AccountingSchedule $schedule, AccountingScheduleService $service): JsonResponse
    {
        $this->authorizeBranchId($schedule->branch_id);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
            'write_off' => ['nullable', 'boolean'],
            'write_off_date' => ['nullable', 'date'],
            'write_off_memo' => ['nullable', 'string', 'max:255'],
            'submit_write_off' => ['nullable', 'boolean'],
        ]);

        $result = $service->cancel($schedule, $data, $this->actorId());

        return $this->ok([
            'schedule' => $result['schedule']->toApiArray(),
            'adjustment_document' => $result['adjustment_document']?->toApiArray(true),
        ]);
    }

    public function postEvent(
        AccountingSchedule $schedule,
        AccountingScheduleEvent $event,
        AccountingScheduleService $service,
    ): JsonResponse {
        $this->authorizeBranchId($schedule->branch_id);

        $posted = $service->postEvent($schedule, $event, $this->actorId());

        return $this->ok($service->load($schedule)->toApiArray() + [
            'posted_event' => $posted->toApiArray(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(bool $partial = false): array
    {
        $presence = $partial ? 'sometimes' : 'required';

        return [
            'kind' => [$presence, Rule::in(AccountingSchedule::KINDS)],
            'description' => [$presence, 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:100'],
            'total_minor' => [$presence, 'integer', 'min:1'],
            'periods' => [$presence, 'integer', 'min:1', 'max:120'],
            'frequency' => ['nullable', Rule::in(['monthly'])],
            'starts_on' => [$presence, 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'currency' => ['nullable', 'string', 'size:3'],
            'fx_rate' => ['nullable', 'numeric', 'gt:0'],
            'source_account_code' => ['nullable', 'string', 'max:20'],
            'target_account_code' => ['nullable', 'string', 'max:20'],
            'reversal_policy' => ['nullable', Rule::in(AccountingSchedule::REVERSAL_POLICIES)],
        ];
    }
}
