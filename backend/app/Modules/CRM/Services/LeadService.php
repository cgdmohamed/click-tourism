<?php

namespace App\Modules\CRM\Services;

use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\AuditService;
use App\Modules\Core\Services\BranchScope;
use App\Modules\Core\Services\SettingService;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Models\LeadActivity;
use App\Modules\CRM\Models\LeadSource;
use App\Modules\CRM\Models\LeadStage;
use App\Modules\Parties\Models\Customer;
use DomainException;
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
    ) {
    }

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
                : $this->pickOwner($branch, $source?->id);

            $lead = Lead::query()->create([
                'source_id' => $source?->id,
                'stage_id' => $stage->id,
                'external_id' => $data['external_id'] ?? null,
                'name' => $data['name'],
                'phone' => $data['phone'] ?? null,
                'email' => $data['email'] ?? null,
                'branch_id' => $branch->id,
                'owner_user_id' => $owner?->id,
                'status' => 'open',
                'consent' => (bool) ($data['consent'] ?? false),
                'notes' => $data['notes'] ?? null,
                'last_activity_at' => now(),
            ]);

            $this->activity($lead, 'created', 'Lead captured', $actorId);
            $this->audit->log('lead.created', $lead, null, $lead->toApiArray(), $actorId);

            return $lead->load('source', 'stage', 'owner');
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

            foreach (['name', 'phone', 'email', 'notes'] as $field) {
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
                $attributes['owner_user_id'] = $owner->id;
            }

            if (! empty($data['note'])) {
                $this->activity($lead, 'note', (string) $data['note'], $actorId);
            }

            $attributes['last_activity_at'] = now();
            $lead->update($attributes);

            if (isset($data['stage'])) {
                $this->activity($lead, 'stage_changed', 'Stage: '.$data['stage'], $actorId);
            }

            $this->audit->log('lead.updated', $lead, null, $attributes, $actorId);

            return $lead->refresh()->load('source', 'stage', 'owner');
        });
    }

    /**
     * Reassign leads with no activity for the configured window.
     * Returns the number of leads reassigned.
     */
    public function reassignStale(): int
    {
        $hours = max(1, $this->settings->int('crm', 'reassign_after_hours', 24));
        $cutoff = now()->subHours($hours);

        $leads = Lead::query()
            ->where('status', 'open')
            ->where(function ($query) use ($cutoff) {
                $query->where('last_activity_at', '<', $cutoff)
                    ->orWhere(function ($query) use ($cutoff) {
                        $query->whereNull('last_activity_at')->where('created_at', '<', $cutoff);
                    });
            })
            ->get();

        $count = 0;
        foreach ($leads as $lead) {
            $branch = Branch::query()->find($lead->branch_id);
            if (! $branch) {
                continue;
            }

            $newOwner = $this->pickOwner($branch, $lead->source_id, $lead->owner_user_id);
            if ($newOwner && $newOwner->id !== $lead->owner_user_id) {
                $previous = $lead->owner_user_id;
                $lead->update(['owner_user_id' => $newOwner->id, 'last_activity_at' => now()]);
                $this->activity($lead, 'reassigned', 'Reassigned after '.$hours.'h of inactivity', null);
                $this->audit->log('lead.reassigned', $lead, ['owner_user_id' => $previous], ['owner_user_id' => $newOwner->id]);
                $count++;
            }
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

    private function pickOwner(Branch $branch, ?int $sourceId = null, ?int $excludeUserId = null): ?User
    {
        $candidates = User::query()
            ->where('is_active', true)
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

        return $candidates->sortBy(fn (User $user) => (int) ($loads[$user->id] ?? 0))->first();
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
