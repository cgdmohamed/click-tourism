<?php

namespace App\Modules\Commissions\Services;

use App\Modules\Bookings\Models\Booking;
use App\Modules\Commissions\Models\CommissionRule;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Services\AuditService;
use App\Modules\Core\Services\SettingService;
use App\Modules\Parties\Models\Employee;
use App\Modules\Parties\Models\Supplier;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Commission rule lifecycle and precedence resolution.
 *
 * Lifecycle: draft -> pending_approval -> approved | rejected; approved ->
 * retired. An approved version is immutable: a change is a new version of the
 * same code that supersedes the old one from its own effective date.
 *
 * Precedence (DEC-013, closed 2026-11-11): per beneficiary exactly one rule
 * wins - the most specific, then the latest effective date, then the highest
 * version. Rules never stack for the same beneficiary.
 */
class CommissionRuleService
{
    public const SERVICE_TYPES = ['flight', 'hotel', 'transport', 'visa', 'program', 'other'];

    public function __construct(
        private readonly SettingService $settings,
        private readonly AuditService $audit,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(array $data, ?int $actorId): CommissionRule
    {
        return DB::transaction(function () use ($data, $actorId) {
            $code = strtoupper(trim((string) $data['code']));
            if (CommissionRule::query()->where('code', $code)->exists()) {
                throw new DomainException("Commission rule {$code} already exists; create a new version instead.");
            }

            $rule = CommissionRule::query()->create($this->attributes($data) + [
                'code' => $code,
                'version' => 1,
                'status' => 'draft',
                'created_by' => $actorId,
            ]);
            $this->audit->log('commission_rule.created', $rule, null, $rule->toApiArray(), $actorId);

            return $this->fresh($rule);
        });
    }

    /** @param array<string, mixed> $data */
    public function update(CommissionRule $rule, array $data, ?int $actorId): CommissionRule
    {
        return DB::transaction(function () use ($rule, $data, $actorId) {
            $rule = CommissionRule::query()->lockForUpdate()->findOrFail($rule->id);
            if (! in_array($rule->status, ['draft', 'rejected'], true)) {
                throw new DomainException('Only draft or rejected rules can be edited; create a new version of an approved rule.');
            }

            $before = $rule->toApiArray();
            $rule->update($this->attributes($data + $this->editable($rule)) + ['status' => 'draft', 'rejection_reason' => null]);
            $this->audit->log('commission_rule.updated', $rule, $before, $rule->toApiArray(), $actorId);

            return $this->fresh($rule);
        });
    }

    /** Start the next version of a rule: a draft copy that supersedes the latest approved one when approved. */
    public function newVersion(CommissionRule $source, ?int $actorId): CommissionRule
    {
        return DB::transaction(function () use ($source, $actorId) {
            $latest = CommissionRule::query()->where('code', $source->code)->lockForUpdate()->orderByDesc('version')->firstOrFail();
            if (in_array($latest->status, ['draft', 'pending_approval', 'rejected'], true)) {
                throw new DomainException('This rule already has an unapproved version; finish or edit it first.');
            }

            $rule = CommissionRule::query()->create([
                'code' => $latest->code,
                'version' => $latest->version + 1,
                'name_ar' => $latest->name_ar,
                'name_en' => $latest->name_en,
                'status' => 'draft',
                'beneficiary_type' => $latest->beneficiary_type,
                'beneficiary_id' => $latest->beneficiary_id,
                'basis' => $latest->basis,
                'rate_bp' => $latest->rate_bp,
                'fixed_minor' => $latest->fixed_minor,
                'branch_id' => $latest->branch_id,
                'service_type' => $latest->service_type,
                'allow_cross_branch' => $latest->allow_cross_branch,
                'effective_from' => max($latest->effective_from->toDateString(), now()->toDateString()),
                'effective_to' => null,
                'supersedes_id' => $latest->id,
                'created_by' => $actorId,
            ]);
            $this->audit->log('commission_rule.version_created', $rule, null, ['code' => $rule->code, 'version' => $rule->version], $actorId);

            return $this->fresh($rule);
        });
    }

    public function submit(CommissionRule $rule, ?int $actorId): CommissionRule
    {
        return DB::transaction(function () use ($rule, $actorId) {
            $rule = CommissionRule::query()->lockForUpdate()->findOrFail($rule->id);
            if (! in_array($rule->status, ['draft', 'rejected'], true)) {
                throw new DomainException('Only draft or rejected rules can be submitted.');
            }

            $rule->update(['status' => 'pending_approval', 'submitted_by' => $actorId, 'rejection_reason' => null]);
            $this->audit->log('commission_rule.submitted', $rule, ['status' => 'draft'], ['status' => 'pending_approval'], $actorId);

            return $this->fresh($rule);
        });
    }

    public function approve(CommissionRule $rule, ?int $actorId): CommissionRule
    {
        return DB::transaction(function () use ($rule, $actorId) {
            $rule = CommissionRule::query()->lockForUpdate()->findOrFail($rule->id);
            if ($rule->status !== 'pending_approval') {
                throw new DomainException('Only submitted rules can be approved.');
            }

            if ($this->settings->bool('commissions', 'segregation', true)
                && $actorId !== null
                && in_array($actorId, array_filter([$rule->created_by, $rule->submitted_by]), true)) {
                throw new DomainException('The person who created or submitted a commission rule cannot approve it.');
            }

            $from = $rule->effective_from->toDateString();
            // The new version takes over from its effective date; earlier bookings keep the old terms.
            CommissionRule::query()->where('code', $rule->code)->where('id', '!=', $rule->id)
                ->where('status', 'approved')->lockForUpdate()->get()
                ->each(function (CommissionRule $old) use ($from) {
                    if ($old->effective_from->toDateString() >= $from) {
                        $old->update(['status' => 'retired']);
                    } elseif ($old->effective_to === null || $old->effective_to->toDateString() >= $from) {
                        $old->update(['effective_to' => Carbon::parse($from)->subDay()->toDateString()]);
                    }
                });

            $rule->update(['status' => 'approved', 'approved_by' => $actorId, 'approved_at' => now()]);
            $this->audit->log('commission_rule.approved', $rule, ['status' => 'pending_approval'], ['status' => 'approved'], $actorId);

            return $this->fresh($rule);
        });
    }

    public function reject(CommissionRule $rule, string $reason, ?int $actorId): CommissionRule
    {
        return DB::transaction(function () use ($rule, $reason, $actorId) {
            $rule = CommissionRule::query()->lockForUpdate()->findOrFail($rule->id);
            if ($rule->status !== 'pending_approval') {
                throw new DomainException('Only submitted rules can be rejected.');
            }

            $rule->update(['status' => 'rejected', 'rejection_reason' => $reason]);
            $this->audit->log('commission_rule.rejected', $rule, ['status' => 'pending_approval'], ['status' => 'rejected', 'reason' => $reason], $actorId);

            return $this->fresh($rule);
        });
    }

    /** Disable an approved rule. Existing accruals are untouched; new bookings stop matching it. */
    public function retire(CommissionRule $rule, ?int $actorId): CommissionRule
    {
        return DB::transaction(function () use ($rule, $actorId) {
            $rule = CommissionRule::query()->lockForUpdate()->findOrFail($rule->id);
            if ($rule->status !== 'approved') {
                throw new DomainException('Only approved rules can be retired.');
            }

            $rule->update(['status' => 'retired']);
            $this->audit->log('commission_rule.retired', $rule, ['status' => 'approved'], ['status' => 'retired'], $actorId);

            return $this->fresh($rule);
        });
    }

    public function destroy(CommissionRule $rule, ?int $actorId): void
    {
        DB::transaction(function () use ($rule, $actorId) {
            $rule = CommissionRule::query()->lockForUpdate()->findOrFail($rule->id);
            if ($rule->status !== 'draft') {
                throw new DomainException('Only draft rules can be deleted.');
            }

            $this->audit->log('commission_rule.deleted', $rule, $rule->toApiArray(), null, $actorId);
            $rule->delete();
        });
    }

    /**
     * Winning rule per beneficiary for a booking at a date.
     *
     * @return Collection<int, array{rule: CommissionRule, beneficiary_type: string, beneficiary_id: int}>
     */
    public function resolve(Booking $booking, string $date): Collection
    {
        $booking->loadMissing('items');
        $serviceTypes = $booking->items->pluck('service_type')->unique()->all();

        $candidates = CommissionRule::query()->where('status', 'approved')
            ->whereDate('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date))
            ->get()
            ->filter(fn (CommissionRule $rule) => ($rule->branch_id === null || $rule->branch_id === $booking->branch_id)
                && ($rule->service_type === null || in_array($rule->service_type, $serviceTypes, true)));

        $groups = [];
        foreach ($candidates as $rule) {
            $beneficiaryId = $this->beneficiaryFor($rule, $booking);
            if ($beneficiaryId === null || ! $this->eligible($rule, $beneficiaryId, $booking)) {
                continue;
            }
            $groups[$rule->beneficiary_type.':'.$beneficiaryId][] = ['rule' => $rule, 'beneficiary_type' => $rule->beneficiary_type, 'beneficiary_id' => $beneficiaryId];
        }

        return collect($groups)->map(function (array $entries) {
            usort($entries, fn ($a, $b) => [$b['rule']->specificity(), $b['rule']->effective_from->toDateString(), $b['rule']->version, $b['rule']->id]
                <=> [$a['rule']->specificity(), $a['rule']->effective_from->toDateString(), $a['rule']->version, $a['rule']->id]);

            return $entries[0];
        })->values();
    }

    /** Internal id of the party a rule pays for this booking, or null when none can be determined. */
    private function beneficiaryFor(CommissionRule $rule, Booking $booking): ?int
    {
        if ($rule->beneficiary_id !== null) {
            return $rule->beneficiary_id;
        }

        return match ($rule->beneficiary_type) {
            'employee' => $booking->owner_user_id === null ? null
                : Employee::query()->where('user_id', $booking->owner_user_id)->where('is_active', true)->value('id'),
            'branch' => $booking->branch_id,
            default => null,
        };
    }

    /**
     * Cross-branch eligibility: a beneficiary attached to another branch earns
     * on this booking only when the rule explicitly allows cross-branch pay.
     */
    private function eligible(CommissionRule $rule, int $beneficiaryId, Booking $booking): bool
    {
        $beneficiaryBranch = match ($rule->beneficiary_type) {
            'employee' => $this->activeEmployeeBranch($beneficiaryId),
            'agent' => $this->activeAgentBranch($beneficiaryId),
            'branch' => Branch::query()->where('id', $beneficiaryId)->where('is_active', true)->exists() ? $beneficiaryId : false,
            default => false,
        };

        if ($beneficiaryBranch === false) {
            return false; // missing or inactive beneficiary
        }

        return $beneficiaryBranch === null || $beneficiaryBranch === $booking->branch_id || $rule->allow_cross_branch;
    }

    /** @return int|false|null Branch id (null = unattached) or false when the agent is missing or inactive. */
    private function activeAgentBranch(int $supplierId): int|false|null
    {
        $agent = Supplier::query()->where('id', $supplierId)->where('is_active', true)->where('category', 'agent')->first();

        return $agent ? $agent->branch_id : false;
    }

    /** @return int|false|null Branch id (null = unattached) or false when the employee is missing or inactive. */
    private function activeEmployeeBranch(int $employeeId): int|false|null
    {
        $employee = Employee::query()->where('id', $employeeId)->where('is_active', true)->first();

        return $employee ? $employee->branch_id : false;
    }

    /**
     * Validate and normalise input into column values.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        $type = (string) ($data['beneficiary_type'] ?? '');
        if (! in_array($type, CommissionRule::BENEFICIARIES, true)) {
            throw new DomainException('Choose a beneficiary type: employee, agent or branch.');
        }

        $beneficiaryId = null;
        if (! empty($data['beneficiary_id'])) {
            $ref = (string) $data['beneficiary_id'];
            $beneficiaryId = match ($type) {
                'employee' => Employee::query()->where('public_id', $ref)->where('is_active', true)->value('id'),
                'agent' => Supplier::query()->where('public_id', $ref)->where('is_active', true)->where('category', 'agent')->value('id'),
                'branch' => Branch::query()->where('public_id', $ref)->value('id'),
            };
            if ($beneficiaryId === null) {
                throw new DomainException('The selected beneficiary does not exist, is inactive or is not an '.$type.'.');
            }
        } elseif ($type === 'agent') {
            throw new DomainException('An agent rule needs a specific agent.');
        }

        $basis = (string) ($data['basis'] ?? '');
        if (! in_array($basis, CommissionRule::BASES, true)) {
            throw new DomainException('Choose a calculation basis: percent of revenue, percent of margin or fixed.');
        }

        $rate = null;
        $fixed = null;
        if ($basis === 'fixed') {
            $fixed = (int) ($data['fixed_minor'] ?? 0);
            if ($fixed <= 0) {
                throw new DomainException('A fixed commission needs an amount greater than zero.');
            }
        } else {
            $rate = (int) ($data['rate_bp'] ?? 0);
            if ($rate <= 0 || $rate > 10000) {
                throw new DomainException('The rate must be between 1 and 10000 basis points (0.01% to 100%).');
            }
        }

        $branchId = null;
        if (! empty($data['branch_id'])) {
            $branchId = Branch::query()->where('public_id', (string) $data['branch_id'])->value('id');
            if ($branchId === null) {
                throw new DomainException('The rule branch does not exist.');
            }
        }

        $serviceType = $data['service_type'] ?? null;
        if ($serviceType !== null && ! in_array($serviceType, self::SERVICE_TYPES, true)) {
            throw new DomainException('Unknown service type.');
        }

        $from = Carbon::parse((string) $data['effective_from'])->toDateString();
        $to = ! empty($data['effective_to']) ? Carbon::parse((string) $data['effective_to'])->toDateString() : null;
        if ($to !== null && $to < $from) {
            throw new DomainException('The end date cannot be before the start date.');
        }

        return [
            'name_ar' => (string) $data['name_ar'],
            'name_en' => (string) $data['name_en'],
            'beneficiary_type' => $type,
            'beneficiary_id' => $beneficiaryId,
            'basis' => $basis,
            'rate_bp' => $rate,
            'fixed_minor' => $fixed,
            'branch_id' => $branchId,
            'service_type' => $serviceType,
            'allow_cross_branch' => (bool) ($data['allow_cross_branch'] ?? false),
            'effective_from' => $from,
            'effective_to' => $to,
        ];
    }

    /** @return array<string, mixed> Current values in the shape accepted by attributes(). */
    private function editable(CommissionRule $rule): array
    {
        $beneficiary = $rule->beneficiaryArray();

        return [
            'name_ar' => $rule->name_ar,
            'name_en' => $rule->name_en,
            'beneficiary_type' => $rule->beneficiary_type,
            'beneficiary_id' => $beneficiary['id'] ?? null,
            'basis' => $rule->basis,
            'rate_bp' => $rule->rate_bp,
            'fixed_minor' => $rule->fixed_minor,
            'branch_id' => $rule->branch?->public_id,
            'service_type' => $rule->service_type,
            'allow_cross_branch' => $rule->allow_cross_branch,
            'effective_from' => $rule->effective_from->toDateString(),
            'effective_to' => $rule->effective_to?->toDateString(),
        ];
    }

    private function fresh(CommissionRule $rule): CommissionRule
    {
        return $rule->refresh()->load('branch', 'supersedes');
    }
}
