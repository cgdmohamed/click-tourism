<?php

namespace App\Http\Controllers\Api;

use App\Modules\Accounting\Models\AccountingDocument;
use App\Modules\Accounting\Models\FixedAsset;
use App\Modules\Accounting\Models\FixedAssetTransfer;
use App\Modules\Accounting\Services\FixedAssetReportService;
use App\Modules\Accounting\Services\FixedAssetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Fixed-asset register, lifecycle events and audit trail (Prompt 25).
 *
 * Draft assets are editable; capitalization and disposal are approval steps
 * that post through FixedAssetService. A transfer between branches posts two balanced journals
 * (one per branch, via the inter-branch clearing accounts), and impairment only ever becomes effective through an approved and
 * posted accounting document.
 */
class FixedAssetController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $branch = $this->branch();

        $assets = FixedAsset::query()
            ->with(
                'branch',
                'category.assetAccount',
                'category.accumulatedDepreciationAccount',
                'category.depreciationExpenseAccount',
                'supplier',
                'costCenter',
                'employee',
            )
            ->when($branch, fn ($query) => $query->where('branch_id', $branch->id))
            ->when($request->query('status'), fn ($query, $status) => $query->where('status', $status))
            ->when($request->query('category'), fn ($query, $category) => $query->whereRelation('category', 'public_id', $category))
            ->when($request->query('search'), function ($query, $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('code', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%")
                        ->orWhere('location', 'like', "%{$search}%");
                });
            })
            ->orderBy('code')
            ->get();

        return $this->ok($assets->map(fn (FixedAsset $asset) => $this->payload($asset))->values());
    }

    public function store(Request $request, FixedAssetService $service): JsonResponse
    {
        $data = $request->validate($this->rules());

        $asset = $service->createAsset($data, $this->branchOrFail(), $this->actorId());

        return $this->created($this->payload($asset));
    }

    public function show(FixedAsset $asset, FixedAssetService $service): JsonResponse
    {
        $this->authorizeBranchId($asset->branch_id);

        return $this->ok($this->payload($service->load($asset)));
    }

    public function update(Request $request, FixedAsset $asset, FixedAssetService $service): JsonResponse
    {
        $this->authorizeBranchId($asset->branch_id);

        $data = $request->validate($this->rules(true));

        return $this->ok($this->payload($service->updateAsset($asset, $data, $this->actorId())));
    }

    public function capitalize(FixedAsset $asset, FixedAssetService $service): JsonResponse
    {
        $this->authorizeBranchId($asset->branch_id);

        return $this->ok($this->payload($service->capitalize($asset, $this->actorId())));
    }

    public function transfer(Request $request, FixedAsset $asset, FixedAssetService $service): JsonResponse
    {
        $this->authorizeBranchId($asset->branch_id);

        $data = $request->validate([
            'to_branch_id' => ['nullable', 'string', 'max:40'],
            'to_cost_center_id' => ['nullable', 'string', 'max:40'],
            'to_employee_id' => ['nullable', 'string', 'max:40'],
            'transfer_date' => ['nullable', 'date'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $transfer = $service->transfer($asset, $data, $this->actorId());

        return $this->created([
            'transfer' => $transfer->toApiArray(),
            'asset' => $this->payload($service->load($transfer->asset)),
        ]);
    }

    public function reverseTransfer(Request $request, FixedAssetTransfer $transfer, FixedAssetService $service): JsonResponse
    {
        $this->authorizeBranchId($transfer->from_branch_id);
        $this->authorizeBranchId($transfer->to_branch_id);

        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $reversed = $service->reverseTransfer($transfer, $data['reason'], $this->actorId());

        return $this->ok([
            'transfer' => $reversed->toApiArray(),
            'asset' => $this->payload($service->load($reversed->asset)),
        ]);
    }

    public function dispose(Request $request, FixedAsset $asset, FixedAssetService $service): JsonResponse
    {
        $this->authorizeBranchId($asset->branch_id);

        $data = $request->validate([
            'disposal_date' => ['nullable', 'date'],
            'disposal_type' => ['nullable', Rule::in(FixedAsset::DISPOSAL_TYPES)],
            'proceeds_minor' => ['nullable', 'integer', 'min:0'],
            'proceeds_account_code' => ['nullable', 'string', 'max:20'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        return $this->ok($this->payload($service->dispose($asset, $data, $this->actorId())));
    }

    public function impairment(Request $request, FixedAsset $asset, FixedAssetService $service): JsonResponse
    {
        $this->authorizeBranchId($asset->branch_id);

        $data = $request->validate([
            'amount_minor' => ['required', 'integer', 'min:1'],
            'document_date' => ['nullable', 'date'],
            'memo' => ['nullable', 'string', 'max:255'],
            'submit' => ['nullable', 'boolean'],
        ]);

        $document = $service->recordImpairment($asset, $data, $this->actorId());

        return $this->created($document->toApiArray(true));
    }

    public function applyImpairment(
        FixedAsset $asset,
        AccountingDocument $document,
        FixedAssetService $service,
    ): JsonResponse {
        $this->authorizeBranchId($asset->branch_id);

        return $this->ok($this->payload($service->applyImpairment($asset, $document, $this->actorId())));
    }

    public function schedule(FixedAsset $asset, FixedAssetReportService $reports): JsonResponse
    {
        $this->authorizeBranchId($asset->branch_id);

        return $this->ok([
            'asset_id' => $asset->public_id,
            'code' => $asset->code,
            'periods' => $reports->schedule($asset),
        ]);
    }

    public function auditTrail(FixedAsset $asset, FixedAssetService $service): JsonResponse
    {
        $this->authorizeBranchId($asset->branch_id);

        return $this->ok($service->auditTrail($asset));
    }

    /** @return array<string, mixed> */
    private function payload(FixedAsset $asset): array
    {
        return $asset->toApiArray() + ['base_currency' => $this->settings()->baseCurrency()];
    }

    /** @return array<string, mixed> */
    private function rules(bool $partial = false): array
    {
        $presence = $partial ? 'sometimes' : 'required';

        return [
            'code' => ['nullable', 'string', 'max:60'],
            'category_id' => [$presence, 'string', 'max:40'],
            'description' => [$presence, 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'supplier_id' => ['nullable', 'string', 'max:40'],
            'cost_center_id' => ['nullable', 'string', 'max:40'],
            'employee_id' => ['nullable', 'string', 'max:40'],
            'acquisition_journal_entry_id' => ['nullable', 'string', 'max:40'],
            'purchase_date' => ['nullable', 'date'],
            'capitalization_date' => [$presence, 'date'],
            'in_service_date' => ['nullable', 'date'],
            'currency' => ['nullable', 'string', 'size:3'],
            'fx_rate' => ['nullable', 'numeric', 'gt:0'],
            'acquisition_value_minor' => [$presence, 'integer', 'min:1'],
            'residual_value_minor' => ['nullable', 'integer', 'min:0'],
            'useful_life_months' => ['nullable', 'integer', 'min:1', 'max:600'],
            'depreciation_convention' => ['nullable', Rule::in(FixedAsset::CONVENTIONS)],
            'attachments' => ['nullable', 'array'],
            'attachments.*.name' => ['required_with:attachments', 'string', 'max:255'],
            'attachments.*.url' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
