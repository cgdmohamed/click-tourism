<?php

namespace App\Http\Controllers\Api;

use App\Modules\Accounting\Models\FixedAssetDepreciationRun;
use App\Modules\Accounting\Services\FixedAssetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Depreciation runs: preview, create draft, inspect and post (Prompt 25).
 *
 * A run is the approval document for one branch-period. Posting is idempotent
 * and produces exactly one journal entry; a second attempt returns the posted
 * run instead of writing another entry.
 */
class DepreciationRunController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $branch = $this->branch();

        $runs = FixedAssetDepreciationRun::query()
            ->with('branch', 'journalEntry', 'items.asset.branch', 'items.asset.category')
            ->when($branch, fn ($query) => $query->where('branch_id', $branch->id))
            ->when($request->query('period'), fn ($query, $period) => $query->where('period', $period))
            ->when($request->query('status'), fn ($query, $status) => $query->where('status', $status))
            ->orderByDesc('period')
            ->orderByDesc('id')
            ->get();

        return $this->ok($runs->map(fn (FixedAssetDepreciationRun $run) => $this->payload($run))->values());
    }

    public function preview(Request $request, FixedAssetService $service): JsonResponse
    {
        $data = $request->validate([
            'period' => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
        ]);

        return $this->ok($service->previewDepreciationRun($this->branchOrFail(), $data['period']));
    }

    public function store(Request $request, FixedAssetService $service): JsonResponse
    {
        $data = $request->validate([
            'period' => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $run = $service->createDepreciationRun($data, $this->branchOrFail(), $this->actorId());

        return $this->created($this->payload($run));
    }

    public function show(FixedAssetDepreciationRun $run, FixedAssetService $service): JsonResponse
    {
        $this->authorizeBranchId($run->branch_id);

        return $this->ok($this->payload($service->loadRun($run)));
    }

    public function post(FixedAssetDepreciationRun $run, FixedAssetService $service): JsonResponse
    {
        $this->authorizeBranchId($run->branch_id);

        return $this->ok($this->payload($service->postDepreciationRun($run, $this->actorId())));
    }

    public function destroy(FixedAssetDepreciationRun $run, FixedAssetService $service): JsonResponse
    {
        $this->authorizeBranchId($run->branch_id);

        $service->deleteDepreciationRun($run, $this->actorId());

        return $this->ok(['deleted' => true]);
    }

    /** @return array<string, mixed> */
    private function payload(FixedAssetDepreciationRun $run): array
    {
        return $run->toApiArray() + ['currency' => $this->settings()->baseCurrency()];
    }
}
