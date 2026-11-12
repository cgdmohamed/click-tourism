<?php

namespace App\Modules\CRM\Services;

use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\AuditService;
use App\Modules\Core\Services\BranchScope;
use App\Modules\Core\Services\SettingService;
use App\Modules\CRM\Models\AssignmentRule;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Models\LeadActivity;
use App\Modules\CRM\Models\LeadSource;
use App\Modules\CRM\Models\LeadStage;
use App\Modules\Parties\Models\Customer;
use App\Support\PhoneNumber;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Lead capture, routing, 24-hour inactivity reassignment, and conversion
 * (PRD FR-CRM-*). The reassignment window is a setting, not a constant.
 */
class LeadService
{
    public function __construct(
        private readonly SettingService $settings,
        private readonly BranchScope $branchScope,
        private readonly AuditService $audit,
    ) {}

    public function create(array $data, ?int $actorId = null): Lead
    {
        return DB::transaction(function () use ($data, $actorId) {
            $branch = Branch::query()->where('public_id', $data['branch_id'] ?? '')->first();
            if (! $branch) {
                throw new DomainException('Branch not found.');
            }

            if ($actorId !== null) {
                $this->branchScope->assertBranch(User::query()->findOrFail($actorId), $branch);
            }

            $source = ! empty($data['source'])
                ? LeadSource::query()->where('code', $data['source'])->first()
                : LeadSource::query()->where('code', 'other')->first();

            $stage = ! empty($data['stage'])
                ? LeadStage::query()->where('code', $data['stage'])->first()
                : LeadStage::query()->where('code', 'new')->first();

            if (! $stage) {
                throw new DomainException('Lead stages are not configured. Run the seeders first.');
            }

            $owner = ! empty($data['owner_user_id'])
                ? User::query()->where('public_id', $data['owner_user_id'])->first()
                : $this->pickOwner($branch, $source?->id, $data['service_category'] ?? null);

            $duplicate = $this->findDuplicate($branch, $data);

            $lead = Lead::query()->create([
                'source_id' => $source?->id,
                'stage_id' => $stage->id,
                'external_id' => $data['external_id'] ?? null,
                'service_category' => $data['service_category'] ?? null,
                'name' => $data['name'],
                'phone' => $data['phone'] ?? null,
                'email' => $data['email'] ?? null,
                'branch_id' => $branch->id,
                'owner_user_id' => $owner?->id,
                'status' => 'open',
                'consent' => (bool) ($data['consent'] ?? false),
                'notes' => $data['notes'] ?? null,
                'last_activity_at' => now(),
                'duplicate_of_lead_id' => $duplicate['lead']->id ?? null,
                'duplicate_reason' => $duplicate['reason'] ?? null,
            ]);

            $this->activity($lead, 'created', 'Lead captured', $actorId);
            $this->audit->log('lead.created', $lead, null, $lead->toApiArray(), $actorId);

            if ($duplicate) {
                $this->activity(
                    $lead,
                    'duplicate_flagged',
                    'Possible duplicate of '.$duplicate['lead']->name.' matched on '.$duplicate['reason'],
                    $actorId,
                );
                $this->audit->log('lead.duplicate_flagged', $lead, null, [
                    'duplicate_of' => $duplicate['lead']->public_id,
                    'matched_on' => $duplicate['reason'],
                ], $actorId);
            }

            return $lead->load('source', 'stage', 'owner', 'duplicateOf');
        });
    }

    public function update(Lead $lead, array $data, ?int $actorId = null): Lead
    {
        return DB::transaction(function () use ($lead, $data, $actorId) {
            $lead = Lead::query()->lockForUpdate()->findOrFail($lead->id);

            $attributes = [];

            if (isset($data['stage'])) {
                $stage = LeadStage::query()->where('code', $data['stage'])->first();
                if (! $stage) {
                    throw new DomainException('Unknown lead stage.');
                }
                $attributes['stage_id'] = $stage->id;
                $attributes['status'] = $stage->is_won ? 'won' : ($stage->is_lost ? 'lost' : 'open');
            }

            foreach (['name', 'phone', 'email', 'notes', 'service_category'] as $field) {
                if (array_key_exists($field, $data)) {
                    $attributes[$field] = $data[$field];
                }
            }

            if (array_key_exists('consent', $data)) {
                $attributes['consent'] = (bool) $data['consent'];
            }

            if (! empty($data['owner_user_id'])) {
                $owner = User::query()->where('public_id', $data['owner_user_id'])->first();
                if (! $owner) {
                    throw new DomainException('Owner not found.');
                }
                if (! $owner->branches()->where('branches.id', $lead->branch_id)->exists()) {
                    throw new DomainException('Owner is not assigned to the lead branch.');
                }
                $attributes['owner_user_id'] = $owner->id;
            }

            $meaningful = false;
            if (! empty($data['note'])) {
                $this->activity($lead, 'note', (string) $data['note'], $actorId);
                $meaningful = $this->isMeaningfulActivity('note');
            }

            if (isset($data['stage'])) {
                $this->activity($lead, 'stage_changed', 'Stage: '.$data['stage'], $actorId);
                $meaningful = $meaningful || $this->isMeaningfulActivity('stage_changed');
            }

            if (isset($attributes['owner_user_id']) && $attributes['owner_user_id'] !== $lead->owner_user_id) {
                $this->activity($lead, 'assigned', 'Manual owner override', $actorId);
                $meaningful = true;
                $attributes['reassignment_attempts'] = 0;
                $attributes['escalated_at'] = null;
            }

            if ($meaningful) {
                $attributes['last_activity_at'] = now();
            }

            $lead->update($attributes);

            $this->audit->log('lead.updated', $lead, null, $attributes, $actorId);

            return $lead->refresh()->load('source', 'stage', 'owner', 'duplicateOf');
        });
    }

    /**
     * GAP-CRM-04: an existing lead in the same branch that was active inside
     * the configured window and shares the normalized phone or email.
     *
     * @return array{lead: Lead, reason: string}|null
     */
    private function findDuplicate(Branch $branch, array $data): ?array
    {
        $windowDays = $this->settings->int('crm', 'duplicate_window_days', 30);
        if ($windowDays <= 0) {
            return null;
        }

        $phoneKey = PhoneNumber::matchKey($data['phone'] ?? null);
        $email = mb_strtolower(trim((string) ($data['email'] ?? '')));
        if ($phoneKey === '' && $email === '') {
            return null;
        }

        $cutoff = now()->subDays($windowDays);

        $candidates = Lead::query()
            ->where('branch_id', $branch->id)
            ->whereRaw('COALESCE(last_activity_at, created_at) >= ?', [$cutoff])
            ->where(function (Builder $query) use ($phoneKey, $email) {
                if ($phoneKey !== '') {
                    $query->whereRaw(PhoneNumber::sqlDigits('phone').' LIKE ?', ['%'.$phoneKey]);
                }
                if ($email !== '') {
                    $query->orWhereRaw('LOWER(email) = ?', [$email]);
                }
            })
            ->orderByRaw("CASE WHEN status = 'open' THEN 0 ELSE 1 END")
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        foreach ($candidates as $candidate) {
            $matchedOn = [];
            if ($phoneKey !== '' && PhoneNumber::matchKey($candidate->phone) === $phoneKey) {
                $matchedOn[] = 'phone';
            }
            if ($email !== '' && mb_strtolower(trim((string) $candidate->email)) === $email) {
                $matchedOn[] = 'email';
            }
            if ($matchedOn !== []) {
                return ['lead' => $candidate, 'reason' => implode(',', $matchedOn)];
            }
        }

        return null;
    }

    /**
     * Reassign leads with no activity for the configured window.
     * Returns the number of leads reassigned.
     */
    public function reassignStale(): int
    {
        $hours = max(1, $this->settings->int('crm', 'reassign_after_hours', 24));
        $maxAttempts = max(1, $this->settings->int('crm', 'reassignment_max_attempts', 3));

        $leads = Lead::query()
            ->where('status', 'open')
            ->whereNull('escalated_at')
            ->get();

        $count = 0;
        foreach ($leads as $lead) {
            $lastActivity = $lead->last_activity_at ?? $lead->created_at;
            if (! $lastActivity || $this->businessHoursBetween($lastActivity, now()) < $hours) {
                continue;
            }

            DB::transaction(function () use ($lead, $hours, $maxAttempts, &$count) {
                $locked = Lead::query()->lockForUpdate()->findOrFail($lead->id);
                $lastActivity = $locked->last_activity_at ?? $locked->created_at;
                if ($locked->status !== 'open' || $locked->escalated_at || ! $lastActivity || $this->businessHoursBetween($lastActivity, now()) < $hours) {
                    return;
                }

                $branch = Branch::query()->find($locked->branch_id);
                if (! $branch) {
                    return;
                }

                $attempts = (int) $locked->reassignment_attempts + 1;
                $newOwner = $this->pickOwner($branch, $locked->source_id, $locked->service_category, $locked->owner_user_id);
                if ($newOwner && $newOwner->id !== $locked->owner_user_id) {
                    $previous = $locked->owner_user_id;
                    $locked->update([
                        'owner_user_id' => $newOwner->id,
                        'last_activity_at' => now(),
                        'reassignment_attempts' => $attempts,
                    ]);
                    $this->activity($locked, 'reassigned', 'Reassigned after '.$hours.' business hours of inactivity', null);
                    $this->audit->log('lead.reassigned', $locked, ['owner_user_id' => $previous], [
                        'owner_user_id' => $newOwner->id,
                        'attempt' => $attempts,
                    ]);
                    $count++;

                    return;
                }

                if ($attempts >= $maxAttempts) {
                    $previous = $locked->owner_user_id;
                    $locked->update([
                        'owner_user_id' => null,
                        'reassignment_attempts' => $attempts,
                        'escalated_at' => now(),
                    ]);
                    $this->activity($locked, 'escalated', 'Escalated to unassigned queue after '.$attempts.' attempts', null);
                    $this->audit->log('lead.escalated', $locked, ['owner_user_id' => $previous], ['attempts' => $attempts]);
                    $count++;
                } else {
                    $locked->update(['reassignment_attempts' => $attempts, 'last_activity_at' => now()]);
                }
            });
        }

        return $count;
    }

    public function convert(Lead $lead, ?int $actorId = null): Customer
    {
        return DB::transaction(function () use ($lead, $actorId) {
            $lead = Lead::query()->lockForUpdate()->findOrFail($lead->id);

            if ($lead->converted_customer_id !== null) {
                throw new DomainException('This lead has already been converted.');
            }

            $branch = Branch::query()->findOrFail($lead->branch_id);

            $customer = Customer::query()->create([
                'type' => 'individual',
                'name_ar' => $lead->name,
                'name_en' => $lead->name,
                'phone' => $lead->phone,
                'email' => $lead->email,
                'branch_id' => $branch->id,
                'currency_code' => $this->settings->baseCurrency(),
                'is_active' => true,
            ]);

            $won = LeadStage::query()->where('is_won', true)->first();
            $lead->update([
                'converted_customer_id' => $customer->id,
                'status' => 'won',
                'stage_id' => $won?->id ?? $lead->stage_id,
                'last_activity_at' => now(),
            ]);

            $this->activity($lead, 'converted', 'Converted to customer '.$customer->name_ar, $actorId);
            $this->audit->log('lead.converted', $lead, null, ['customer_id' => $customer->public_id], $actorId);

            return $customer;
        });
    }

    private function pickOwner(Branch $branch, ?int $sourceId = null, ?string $serviceCategory = null, ?int $excludeUserId = null): ?User
    {
        $rules = AssignmentRule::query()
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('branch_id')->orWhere('branch_id', $branch->id))
            ->where(fn ($query) => $query->whereNull('source_id')->orWhere('source_id', $sourceId))
            ->where(fn ($query) => $query->whereNull('service_category')->orWhere('service_category', $serviceCategory))
            ->orderBy('priority')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($rules->where('strategy', 'manual') as $manualRule) {
            if (! $manualRule->target_user_id) {
                continue;
            }
            $manualOwner = User::query()
                ->whereKey($manualRule->target_user_id)
                ->where('is_active', true)
                ->where('is_available_for_leads', true)
                ->whereHas('branches', fn ($query) => $query->where('branches.id', $branch->id))
                ->when($excludeUserId, fn ($query) => $query->where('users.id', '!=', $excludeUserId))
                ->first();
            if ($manualOwner) {
                return $manualOwner;
            }
        }

        $rule = $rules->first(fn (AssignmentRule $candidate) => $candidate->strategy !== 'manual');

        $candidates = User::query()
            ->where('is_active', true)
            ->where('is_available_for_leads', true)
            ->whereHas('branches', fn ($query) => $query->where('branches.id', $branch->id))
            ->when($excludeUserId, fn ($query) => $query->where('users.id', '!=', $excludeUserId))
            ->get();

        if ($candidates->isEmpty()) {
            return null;
        }

        $loads = Lead::query()
            ->whereIn('owner_user_id', $candidates->pluck('id'))
            ->where('status', 'open')
            ->groupBy('owner_user_id')
            ->selectRaw('owner_user_id, COUNT(*) as total')
            ->pluck('total', 'owner_user_id');

        if ($rule?->max_leads_per_user) {
            $candidates = $candidates->filter(fn (User $user) => (int) ($loads[$user->id] ?? 0) < $rule->max_leads_per_user);
        }
        if ($candidates->isEmpty()) {
            return null;
        }

        $strategy = $rule?->strategy ?? $this->settings->get('crm', 'assignment_strategy', 'least_loaded');
        if ($strategy === 'round_robin' && $rule) {
            $ordered = $candidates->sortBy('id')->values();
            $selected = $ordered->first(fn (User $user) => $user->id > (int) $rule->last_assigned_user_id) ?? $ordered->first();
            $rule->update(['last_assigned_user_id' => $selected?->id]);

            return $selected;
        }

        return $candidates->sortBy(fn (User $user) => sprintf('%010d-%010d', (int) ($loads[$user->id] ?? 0), $user->id))->first();
    }

    private function isMeaningfulActivity(string $type): bool
    {
        $configured = explode(',', (string) $this->settings->get('crm', 'meaningful_activity_types', 'note,call,email,whatsapp,stage_changed'));

        return in_array($type, array_map('trim', $configured), true);
    }

    private function businessHoursBetween(Carbon $from, Carbon $to): float
    {
        $timezone = (string) $this->settings->get('crm', 'business_timezone', 'Africa/Cairo');
        $days = array_map('intval', explode(',', (string) $this->settings->get('crm', 'business_days', '1,2,3,4,5')));
        $startTime = (string) $this->settings->get('crm', 'business_day_start', '09:00');
        $endTime = (string) $this->settings->get('crm', 'business_day_end', '17:00');
        $from = $from->copy()->timezone($timezone);
        $to = $to->copy()->timezone($timezone);
        if ($to->lessThanOrEqualTo($from)) {
            return 0;
        }

        $seconds = 0;
        $day = $from->copy()->startOfDay();
        while ($day->lessThanOrEqualTo($to)) {
            if (in_array($day->isoWeekday(), $days, true)) {
                $businessStart = Carbon::parse($day->toDateString().' '.$startTime, $timezone);
                $businessEnd = Carbon::parse($day->toDateString().' '.$endTime, $timezone);
                $overlapStart = $from->greaterThan($businessStart) ? $from : $businessStart;
                $overlapEnd = $to->lessThan($businessEnd) ? $to : $businessEnd;
                if ($overlapEnd->greaterThan($overlapStart)) {
                    $seconds += $overlapStart->diffInSeconds($overlapEnd);
                }
            }
            $day->addDay();
        }

        return $seconds / 3600;
    }

    private function activity(Lead $lead, string $type, ?string $body, ?int $actorId): void
    {
        LeadActivity::query()->create([
            'lead_id' => $lead->id,
            'type' => $type,
            'body' => $body,
            'actor_id' => $actorId,
            'created_at' => now(),
        ]);
    }
}
