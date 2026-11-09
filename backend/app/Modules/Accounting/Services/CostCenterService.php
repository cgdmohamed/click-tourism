<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\CostCenter;
use App\Modules\Accounting\Models\CostCenterAllocation;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\AuditService;
use App\Modules\Core\Services\BranchScope;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Cost center master data and analytical allocations (Prompt 20).
 *
 * Cost centers are analytical dimensions on posted journal lines; they never
 * create ledger entries. Allocations are recorded against posted lines and
 * validated so exact amounts sum to the line amount and percentages sum to 100.
 */
class CostCenterService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly BranchScope $branchScope,
    ) {}

    /**
     * Create a cost center. Cycle protection: a center cannot be its own
     * ancestor. Effective dates are optional; a center outside its effective
     * window is treated as inactive for new allocations.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?int $actorId = null): CostCenter
    {
        return DB::transaction(function () use ($data, $actorId) {
            $code = strtoupper((string) $data['code']);
            $parentId = null;
            $parentPublicId = $data['parent_id'] ?? null;

            if ($parentPublicId) {
                $parent = CostCenter::query()->where('public_id', $parentPublicId)->first();
                if (! $parent) {
                    throw new DomainException("Parent cost center {$parentPublicId} not found.");
                }
                $parentId = $parent->id;
            }

            $center = CostCenter::query()->create([
                'code' => $code,
                'name_ar' => $data['name_ar'],
                'name_en' => $data['name_en'],
                'parent_id' => $parentId,
                'status' => $data['status'] ?? CostCenter::STATUS_ACTIVE,
                'effective_from' => $data['effective_from'] ?? null,
                'effective_to' => $data['effective_to'] ?? null,
                'is_active' => $data['is_active'] ?? true,
                'created_by' => $actorId,
            ]);

            if ($center->wouldCreateCycle($parentId)) {
                throw new DomainException("Assigning parent {$code} would create a cycle in the cost center tree.");
            }

            $this->audit->log('cost_center.created', $center, null, [
                'code' => $center->code,
                'parent_id' => $parentPublicId,
            ], $actorId);

            return $center->load('parent', 'children');
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(CostCenter $center, array $data, ?int $actorId = null): CostCenter
    {
        return DB::transaction(function () use ($center, $data, $actorId) {
            $center = CostCenter::query()->lockForUpdate()->findOrFail($center->id);

            $newParentId = $center->parent_id;
            if (isset($data['parent_id'])) {
                $newParentId = null;
                if ($data['parent_id']) {
                    $parent = CostCenter::query()->where('public_id', $data['parent_id'])->first();
                    if (! $parent) {
                        throw new DomainException("Parent cost center {$data['parent_id']} not found.");
                    }
                    $newParentId = $parent->id;
                }
            }

            if ($newParentId !== $center->parent_id && $center->wouldCreateCycle($newParentId)) {
                throw new DomainException('Re-parenting would create a cycle in the cost center tree.');
            }

            $before = $center->only(['code', 'name_ar', 'name_en', 'parent_id', 'status', 'effective_from', 'effective_to', 'is_active']);

            $center->update(array_filter([
                'code' => isset($data['code']) ? strtoupper((string) $data['code']) : null,
                'name_ar' => $data['name_ar'] ?? null,
                'name_en' => $data['name_en'] ?? null,
                'parent_id' => $newParentId,
                'status' => $data['status'] ?? null,
                'effective_from' => $data['effective_from'] ?? null,
                'effective_to' => $data['effective_to'] ?? null,
                'is_active' => isset($data['is_active']) ? (bool) $data['is_active'] : null,
            ]));

            $this->audit->log('cost_center.updated', $center, $before, $data, $actorId);

            return $center->refresh()->load('parent', 'children');
        });
    }

    public function archive(CostCenter $center, ?int $actorId = null): CostCenter
    {
        return DB::transaction(function () use ($center, $actorId) {
            $center = CostCenter::query()->with('children')->lockForUpdate()->findOrFail($center->id);

            if ($center->status === CostCenter::STATUS_ARCHIVED) {
                throw new DomainException('Cost center is already archived.');
            }

            // Prevent archiving a center that has allocations itself or whose
            // descendants have allocations, to preserve historical attribution.
            $descendantIds = $this->descendantIds($center);
            $hasAllocations = CostCenterAllocation::query()
                ->where('cost_center_id', $center->id)
                ->orWhereIn('cost_center_id', $descendantIds)
                ->exists();

            if ($hasAllocations) {
                throw new DomainException('Cannot archive a center that still carries allocations.');
            }

            $center->update(['status' => CostCenter::STATUS_ARCHIVED, 'is_active' => false]);

            $this->audit->log('cost_center.archived', $center, ['status' => CostCenter::STATUS_ACTIVE], [
                'status' => CostCenter::STATUS_ARCHIVED,
            ], $actorId);

            return $center->refresh();
        });
    }

    /**
     * Record or replace allocations for a posted journal line.
     *
     * @param  array<int, array<string, mixed>>  $allocations
     * @return CostCenterAllocation[]
     */
    public function allocate(int $journalLineId, array $allocations, ?int $actorId = null): array
    {
        return DB::transaction(function () use ($journalLineId, $allocations, $actorId) {
            $line = JournalLine::query()->with('entry')->findOrFail($journalLineId);
            $entry = $line->entry;

            if (! $entry || $entry->status !== 'posted') {
                throw new DomainException('Allocations can only be recorded against posted journal lines.');
            }

            $this->assertBranch($entry->branch_id, $actorId);

            $lineAmount = (int) max($line->debit_minor, $line->credit_minor);
            if ($lineAmount <= 0) {
                throw new DomainException('The journal line has no allocatable amount.');
            }

            $validated = $this->validateAllocations($allocations, $lineAmount);

            // Replace existing allocations for this line (idempotent re-allocation).
            CostCenterAllocation::query()->where('journal_line_id', $journalLineId)->delete();

            $created = [];
            foreach ($validated as $allocation) {
                $created[] = CostCenterAllocation::query()->create([
                    'journal_line_id' => $journalLineId,
                    'cost_center_id' => $allocation['cost_center_id'],
                    'allocation_type' => $allocation['allocation_type'],
                    'amount_minor' => $allocation['amount_minor'],
                    'percent' => $allocation['percent'] ?? null,
                ]);
            }

            $this->audit->log('cost_center.allocated', $line, null, [
                'journal_line_id' => $journalLineId,
                'journal_entry' => $entry->number,
                'allocations' => count($created),
            ], $actorId);

            return collect($created)->each(fn ($a) => $a->load('costCenter'))->all();
        });
    }

    /**
     * Get allocations for a journal line, with cost center details.
     *
     * @return array<int, array<string, mixed>>
     */
    public function allocationsForLine(int $journalLineId): array
    {
        return CostCenterAllocation::query()
            ->where('journal_line_id', $journalLineId)
            ->with('costCenter')
            ->get()
            ->map(fn (CostCenterAllocation $a) => $a->toApiArray())
            ->values()->all();
    }

    /**
     * Cost center P&L for a period: for each center, the sum of allocated
     * amounts on revenue (credit) and expense (debit) lines, reconciled to GL.
     *
     * @return array<string, mixed>
     */
    public function profitAndLoss(string $from, string $to, ?Branch $branch = null): array
    {
        $centers = CostCenter::query()->active()->orderBy('code')->get();

        $report = [];
        $grandRevenue = 0;
        $grandExpense = 0;

        foreach ($centers as $center) {
            $allocations = CostCenterAllocation::query()
                ->where('cost_center_id', $center->id)
                ->join('journal_lines as jl', 'jl.id', '=', 'cost_center_allocations.journal_line_id')
                ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
                ->where('je.status', 'posted')
                ->whereDate('je.entry_date', '>=', $from)
                ->whereDate('je.entry_date', '<=', $to)
                ->selectRaw('jl.account_id, jl.debit_minor, jl.credit_minor, cost_center_allocations.amount_minor')
                ->get();

            $revenue = 0;
            $expense = 0;

            foreach ($allocations as $alloc) {
                $account = Account::query()->find($alloc->account_id);
                if (! $account) {
                    continue;
                }
                $amount = (int) $alloc->amount_minor;
                if ($account->type === 'revenue') {
                    $revenue += $amount;
                    $grandRevenue += $amount;
                } elseif ($account->type === 'expense') {
                    $expense += $amount;
                    $grandExpense += $amount;
                }
            }

            $report[] = [
                'center' => $center->toApiArray(),
                'allocated_revenue_minor' => $revenue,
                'allocated_expense_minor' => $expense,
                'allocated_net_minor' => $revenue - $expense,
            ];
        }

        return [
            'from' => $from,
            'to' => $to,
            'branch_id' => $branch?->id,
            'centers' => $report,
            'grand_allocated_revenue_minor' => $grandRevenue,
            'grand_allocated_expense_minor' => $grandExpense,
            'grand_allocated_net_minor' => $grandRevenue - $grandExpense,
        ];
    }

    /**
     * Journal lines in the period that have no cost center allocation
     * (unallocated entries), for reconciliation.
     *
     * @return array<string, mixed>
     */
    public function unallocated(string $from, string $to, ?Branch $branch = null): array
    {
        $lines = JournalLine::query()
            ->join('journal_entries as je', 'je.id', '=', 'journal_lines.journal_entry_id')
            ->leftJoin('cost_center_allocations as cca', 'cca.journal_line_id', '=', 'journal_lines.id')
            ->where('je.status', 'posted')
            ->whereDate('je.entry_date', '>=', $from)
            ->whereDate('je.entry_date', '<=', $to)
            ->whereNull('cca.id')
            ->with('entry', 'account')
            ->get();

        $total = (int) $lines->sum(fn ($l) => max($l->debit_minor, $l->credit_minor));

        return [
            'from' => $from,
            'to' => $to,
            'branch_id' => $branch?->id,
            'unallocated_line_count' => $lines->count(),
            'unallocated_total_minor' => $total,
            'lines' => $lines->map(fn (JournalLine $l) => [
                'journal_entry' => $l->entry->number,
                'entry_date' => $l->entry->entry_date?->toDateString(),
                'account' => $l->account?->code,
                'amount_minor' => max($l->debit_minor, $l->credit_minor),
                'allocation_type' => 'unallocated',
            ])->values()->all(),
        ];
    }

    /**
     * Validate allocations and compute `amount_minor` for percent-based ones.
     *
     * Exact allocations must sum to the line amount. Percent allocations must
     * sum to 100% (or to 100% of the remaining amount when mixed with exact).
     * Rounding uses the largest-remainder method so the total always equals
     * the line amount exactly.
     *
     * @param  array<int, array<string, mixed>>  $allocations
     * @return array<int, array<string, mixed>>
     */
    private function validateAllocations(array $allocations, int $lineAmount): array
    {
        if (count($allocations) === 0) {
            throw new DomainException('At least one allocation is required.');
        }

        $validated = [];
        $exactSum = 0;
        $percentSum = 0.0;
        $anyExact = false;

        foreach ($allocations as $index => $alloc) {
            $position = $index + 1;
            $centerPublicId = $alloc['cost_center_id'] ?? null;
            $center = CostCenter::query()->where('public_id', $centerPublicId)->first();

            if (! $center || $center->status !== CostCenter::STATUS_ACTIVE || ! $center->is_active) {
                throw new DomainException("Allocation {$position}: cost center {$centerPublicId} is not active.");
            }

            $type = $alloc['allocation_type'] ?? CostCenterAllocation::TYPE_EXACT;
            $item = [
                'cost_center_id' => $center->id,
                'allocation_type' => $type,
                'amount_minor' => 0,
                'percent' => null,
            ];

            if ($type === CostCenterAllocation::TYPE_EXACT) {
                $amount = (int) ($alloc['amount_minor'] ?? 0);
                if ($amount <= 0) {
                    throw new DomainException("Allocation {$position}: exact amount must be greater than zero.");
                }
                $item['amount_minor'] = $amount;
                $exactSum += $amount;
                $anyExact = true;
            } else {
                $percent = (float) ($alloc['percent'] ?? 0);
                if ($percent <= 0 || $percent > 100) {
                    throw new DomainException("Allocation {$position}: percent must be between 0 and 100.");
                }
                $item['percent'] = $percent;
                $percentSum += $percent;
            }

            $validated[] = $item;
        }

        $hasPercent = $percentSum > 0;

        if ($anyExact && $hasPercent) {
            // Mixed: exact amounts plus percent of the remaining line amount.
            $remaining = $lineAmount - $exactSum;
            if ($remaining < 0) {
                throw new DomainException('Exact allocations exceed the journal line amount.');
            }
            $this->distributePercent($validated, $remaining);

            $percentItems = array_filter($validated, fn ($v) => $v['allocation_type'] === CostCenterAllocation::TYPE_PERCENT);
            $percentTotal = (int) array_sum(array_column($percentItems, 'amount_minor'));
            $mixedTotal = $exactSum + $percentTotal;
            if ($mixedTotal !== $lineAmount) {
                throw new DomainException(
                    "Mixed allocations total {$mixedTotal} but the journal line amount is {$lineAmount}."
                );
            }
        } elseif ($anyExact) {
            // Exact only.
            if ($exactSum !== $lineAmount) {
                throw new DomainException(
                    "Exact allocations sum to {$exactSum} but the journal line amount is {$lineAmount}."
                );
            }
        } else {
            // Percent only: must sum to 100%.
            if (abs($percentSum - 100.0) > 0.01) {
                throw new DomainException("Percentage allocations sum to {$percentSum}% but must equal 100%.");
            }
            $this->distributePercent($validated, $lineAmount);
        }

        return array_values($validated);
    }

    /**
     * Compute amount_minor for percent-based allocations using largest remainder.
     *
     * @param  array<int, array<string, mixed>>  $validated
     */
    private function distributePercent(array &$validated, int $baseAmount): void
    {
        $percentItems = array_keys(array_filter($validated, fn ($v) => $v['allocation_type'] === CostCenterAllocation::TYPE_PERCENT));

        if (count($percentItems) === 0) {
            return;
        }

        $rawAmounts = [];
        $floored = [];
        $remainders = [];
        $flooredSum = 0;

        foreach ($percentItems as $idx) {
            $percent = $validated[$idx]['percent'];
            // Each percent is a fraction of the base amount (lineAmount for
            // percent-only, remaining for mixed).
            $raw = ($baseAmount * $percent) / 100.0;
            $rawAmounts[$idx] = $raw;
            $floor = (int) floor($raw);
            $floored[$idx] = $floor;
            $remainders[$idx] = $raw - $floor;
            $flooredSum += $floor;
        }

        $remainingUnits = $baseAmount - $flooredSum;
        arsort($remainders);

        $i = 0;
        foreach ($remainders as $idx => $remainder) {
            $validated[$idx]['amount_minor'] = $floored[$idx] + ($i < $remainingUnits ? 1 : 0);
            $i++;
        }
    }

    /**
     * Recursively collect descendant center ids (for archive protection).
     *
     * @return array<int>
     */
    private function descendantIds(CostCenter $center, array $collected = []): array
    {
        foreach ($center->children as $child) {
            $collected[] = $child->id;
            $collected = $this->descendantIds($child, $collected);
        }

        return $collected;
    }

    private function assertBranch(int $branchId, ?int $actorId): void
    {
        $user = $actorId ? User::query()->find($actorId) : null;
        if ($user) {
            $this->branchScope->assertBranch($user, Branch::query()->find($branchId));
        }
    }
}
