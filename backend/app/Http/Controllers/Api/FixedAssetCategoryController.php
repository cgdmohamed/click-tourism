<?php

namespace App\Http\Controllers\Api;

use App\Modules\Accounting\Models\FixedAssetCategory;
use App\Modules\Accounting\Services\FixedAssetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Fixed-asset categories and their GL mappings (Prompt 25).
 */
class FixedAssetCategoryController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $categories = FixedAssetCategory::query()
            ->with(
                'assetAccount',
                'accumulatedDepreciationAccount',
                'depreciationExpenseAccount',
                'disposalGainAccount',
                'disposalLossAccount',
            )
            ->withCount('assets')
            ->when($request->boolean('include_archived') === false, fn ($query) => $query->where('is_active', true))
            ->orderBy('code')
            ->get();

        return $this->ok($categories->map(fn (FixedAssetCategory $category) => $category->toApiArray())->values());
    }

    public function store(Request $request, FixedAssetService $service): JsonResponse
    {
        $data = $request->validate($this->rules());

        $category = $service->createCategory($data, $this->actorId());

        return $this->created($category->toApiArray());
    }

    public function show(FixedAssetCategory $category): JsonResponse
    {
        $category->load(
            'assetAccount',
            'accumulatedDepreciationAccount',
            'depreciationExpenseAccount',
            'disposalGainAccount',
            'disposalLossAccount',
        )->loadCount('assets');

        return $this->ok($category->toApiArray());
    }

    public function update(Request $request, FixedAssetCategory $category, FixedAssetService $service): JsonResponse
    {
        $data = $request->validate($this->rules(true));

        return $this->ok($service->updateCategory($category, $data, $this->actorId())->toApiArray());
    }

    /** @return array<string, mixed> */
    private function rules(bool $partial = false): array
    {
        $presence = $partial ? 'sometimes' : 'required';

        return [
            'code' => $partial ? ['sometimes', 'string', 'max:40'] : ['nullable', 'string', 'max:40'],
            'name_ar' => [$presence, 'string', 'max:255'],
            'name_en' => [$presence, 'string', 'max:255'],
            'asset_account_code' => ['nullable', 'string', 'max:20'],
            'accumulated_depreciation_account_code' => ['nullable', 'string', 'max:20'],
            'depreciation_expense_account_code' => ['nullable', 'string', 'max:20'],
            'disposal_gain_account_code' => ['nullable', 'string', 'max:20'],
            'disposal_loss_account_code' => ['nullable', 'string', 'max:20'],
            'default_useful_life_months' => ['nullable', 'integer', 'min:1', 'max:600'],
            'default_residual_value_minor' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
