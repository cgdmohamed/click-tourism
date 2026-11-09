<?php

namespace App\Http\Controllers\Api;

use App\Modules\CRM\Models\Lead;
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
}
