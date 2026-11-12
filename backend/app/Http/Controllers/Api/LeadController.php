<?php

namespace App\Http\Controllers\Api;

use App\Modules\Core\Models\AuditEvent;
use App\Modules\Core\Models\User;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Models\LeadActivity;
use App\Modules\CRM\Services\LeadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeadController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $branch = $this->branch();

        $leads = Lead::query()
            ->with(['source', 'stage', 'owner'])
            ->when($branch, fn ($query) => $query->where('branch_id', $branch->id))
            ->when($request->query('stage'), fn ($query, $stage) => $query->whereHas('stage', fn ($query) => $query->where('code', $stage)))
            ->when($request->query('status'), fn ($query, $status) => $query->where('status', $status))
            ->when($request->query('search'), function ($query, $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->orderByDesc('id')
            ->paginate(min(100, max(1, (int) $request->query('per_page', 50))));

        $leads->getCollection()->transform(fn (Lead $lead) => $lead->toApiArray());

        return $this->paginated($leads);
    }

    /**
     * Lead detail with its activity timeline, current owner, and owner changes.
     * Activities are paginated newest-first; owner history is derived from the
     * audit trail so reassignment/escalation stays traceable.
     */
    public function show(Request $request, Lead $lead): JsonResponse
    {
        $this->authorizeBranchId($lead->branch_id);

        $lead->load(['source', 'stage', 'owner', 'branch', 'convertedCustomer', 'convertedBooking']);

        $activities = $lead->activities()
            ->with('actor')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(min(100, max(1, (int) $request->query('per_page', 25))));

        $activities->getCollection()->transform(fn (LeadActivity $activity) => $activity->toApiArray());

        return response()->json([
            'data' => array_merge($lead->toApiArray(), [
                'owner' => $lead->owner ? [
                    'id' => $lead->owner->public_id,
                    'name' => $lead->owner->name,
                ] : null,
                'owner_history' => $this->ownerHistory($lead),
                'activities' => $activities->items(),
            ]),
            'meta' => [
                'current_page' => $activities->currentPage(),
                'last_page' => $activities->lastPage(),
                'total' => $activities->total(),
                'per_page' => $activities->perPage(),
            ],
        ]);
    }

    public function store(Request $request, LeadService $leads): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'source' => ['nullable', 'string', 'max:40'],
            'stage' => ['nullable', 'string', 'max:40'],
            'branch_id' => ['required', 'string'],
            'owner_user_id' => ['nullable', 'string'],
            'consent' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'external_id' => ['nullable', 'string', 'max:120'],
            'service_category' => ['nullable', 'string', 'max:40'],
        ]);

        $lead = $leads->create($data, $this->actorId());

        return $this->created($lead->toApiArray());
    }

    public function update(Request $request, Lead $lead, LeadService $leads): JsonResponse
    {
        $this->authorizeBranchId($lead->branch_id);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'stage' => ['nullable', 'string', 'max:40'],
            'owner_user_id' => ['nullable', 'string'],
            'consent' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'note' => ['nullable', 'string', 'max:2000'],
            'service_category' => ['nullable', 'string', 'max:40'],
        ]);

        return $this->ok($leads->update($lead, $data, $this->actorId())->toApiArray());
    }

    public function convert(Lead $lead, LeadService $leads): JsonResponse
    {
        $this->authorizeBranchId($lead->branch_id);

        $customer = $leads->convert($lead, $this->actorId());

        return $this->ok([
            'lead' => $lead->refresh()->load('source', 'stage')->toApiArray(),
            'customer' => $customer->toApiArray(),
        ]);
    }

    /**
     * Owner changes recorded by the audit service: manual overrides, automatic
     * reassignment, and escalation. The current owner is in the lead payload.
     */
    private function ownerHistory(Lead $lead): array
    {
        $events = AuditEvent::query()
            ->where('entity_type', 'Lead')
            ->where('entity_id', $lead->id)
            ->whereIn('action', ['lead.updated', 'lead.reassigned', 'lead.escalated'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->filter(fn (AuditEvent $event) => data_get($event->before, 'owner_user_id') !== null
                || data_get($event->after, 'owner_user_id') !== null);

        $userIds = $events
            ->flatMap(fn (AuditEvent $event) => [
                data_get($event->before, 'owner_user_id'),
                data_get($event->after, 'owner_user_id'),
            ])
            ->filter(fn ($id) => is_numeric($id))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $users = User::query()->whereIn('id', $userIds)->get()->keyBy('id');

        return $events->map(function (AuditEvent $event) use ($users) {
            $from = data_get($event->before, 'owner_user_id');
            $to = data_get($event->after, 'owner_user_id');

            return [
                'action' => match ($event->action) {
                    'lead.reassigned' => 'reassigned',
                    'lead.escalated' => 'escalated',
                    default => 'assigned',
                },
                'from' => $this->ownerReference($from, $users),
                'to' => $this->ownerReference($to, $users),
                'attempt' => data_get($event->after, 'attempt', data_get($event->after, 'attempts')),
                'at' => $event->created_at?->toIso8601String(),
            ];
        })->values()->all();
    }

    private function ownerReference(mixed $id, $users): ?array
    {
        if (! is_numeric($id)) {
            return null;
        }

        $user = $users->get((int) $id);

        return $user ? ['id' => $user->public_id, 'name' => $user->name] : null;
    }
}
