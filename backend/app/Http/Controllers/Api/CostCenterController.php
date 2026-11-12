<?php

namespace App\Http\Controllers\Api;

use App\Modules\Accounting\Models\CostCenter;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Services\CostCenterService;
use App\Modules\Reports\Services\ReportCsv;
use App\Modules\Reports\Services\ReportExporter;
use App\Support\Like;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class CostCenterController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $branch = $this->branch();

        $centers = CostCenter::query()->with('parent')
            ->when($request->query('status'), fn ($query, $status) => $query->where('status', $status))
            ->when(Like::term($request->query('search')), fn ($query, $search) => Like::contains(
                $query, ['code', 'name_ar', 'name_en'], $search))
            ->when($request->boolean('include_archived') === false, fn ($query) => $query->where('is_active', true))
            ->orderBy('code')->get()
            ->map(fn (CostCenter $center) => $center->toApiArray($request->boolean('with_children')));

        return $this->ok($centers);
    }

    public function show(CostCenter $center): JsonResponse
    {
        return $this->ok($center->load('parent', 'children', 'children.children')->toApiArray(true));
    }

    public function store(Request $request, CostCenterService $service): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40', 'unique:cost_centers,code'],
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['required', 'string', 'max:255'],
            'parent_id' => ['nullable', 'string', Rule::exists('cost_centers', 'public_id')],
            'status' => ['nullable', Rule::in(['active', 'archived'])],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $center = $service->create($data, $this->actorId());

        return $this->created($center->toApiArray());
    }

    public function update(Request $request, CostCenter $center, CostCenterService $service): JsonResponse
    {
        $data = $request->validate([
            'code' => ['sometimes', 'string', 'max:40', Rule::unique('cost_centers')->ignore($center->id)],
            'name_ar' => ['sometimes', 'string', 'max:255'],
            'name_en' => ['sometimes', 'string', 'max:255'],
            'parent_id' => ['sometimes', 'nullable', 'string', Rule::exists('cost_centers', 'public_id')],
            'status' => ['sometimes', Rule::in(['active', 'archived'])],
            'effective_from' => ['sometimes', 'nullable', 'date'],
            'effective_to' => ['sometimes', 'nullable', 'date'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        return $this->ok($service->update($center, $data, $this->actorId())->toApiArray());
    }

    public function archive(CostCenter $center, CostCenterService $service): JsonResponse
    {
        return $this->ok($service->archive($center, $this->actorId())->toApiArray());
    }

    public function allocations(Request $request, JournalLine $line): JsonResponse
    {
        $this->authorizeBranchId($line->entry->branch_id);

        return $this->ok(app(CostCenterService::class)->allocationsForLine((int) $line->id));
    }

    public function allocate(Request $request, JournalLine $line, CostCenterService $service): JsonResponse
    {
        $this->authorizeBranchId($line->entry->branch_id);

        $data = $request->validate([
            'allocations' => ['required', 'array', 'min:1'],
            'allocations.*.cost_center_id' => ['required', 'string', Rule::exists('cost_centers', 'public_id')],
            'allocations.*.allocation_type' => ['sometimes', Rule::in(['exact', 'percent'])],
            'allocations.*.amount_minor' => ['sometimes', 'integer', 'min:0'],
            'allocations.*.percent' => ['sometimes', 'numeric', 'between:0,100'],
        ]);

        $allocated = $service->allocate((int) $line->id, $data['allocations'], $this->actorId());

        return $this->created(array_values(array_map(fn ($a) => $a->toApiArray(), $allocated)));
    }

    public function profitAndLoss(Request $request, CostCenterService $service): JsonResponse
    {
        $branch = $this->branch();

        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date'],
        ]);

        return $this->ok($service->profitAndLoss($data['from'], $data['to'], $branch));
    }

    public function unallocated(Request $request, CostCenterService $service): JsonResponse
    {
        $branch = $this->branch();

        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date'],
        ]);

        return $this->ok($service->unallocated($data['from'], $data['to'], $branch));
    }

    public function exportProfitAndLoss(Request $request, CostCenterService $service, ReportCsv $csv, ReportExporter $exporter): Response
    {
        $options = $exporter->options($request);
        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date'],
        ]);

        // The cost-center report carries no currency of its own; amounts are
        // analytical portions of base-currency journal lines.
        $currency = $this->settings()->baseCurrency();
        $report = $service->profitAndLoss($data['from'], $data['to'], $this->branch());

        return $exporter->respond($options, $this->branch(), $this->actorId(), 'cost-center-pl', $data,
            $csv->costCenterProfitAndLossRows($report, $currency),
            "cost-center-pl_{$data['from']}_{$data['to']}",
            'Cost Center Profit and Loss / أرباح وخسائر مراكز التكلفة',
            ['period' => "{$data['from']} - {$data['to']}", 'currency' => $currency],
        );
    }

    public function exportUnallocated(Request $request, CostCenterService $service, ReportCsv $csv, ReportExporter $exporter): Response
    {
        $options = $exporter->options($request);
        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date'],
        ]);

        $currency = $this->settings()->baseCurrency();
        $report = $service->unallocated($data['from'], $data['to'], $this->branch());

        return $exporter->respond($options, $this->branch(), $this->actorId(), 'cost-center-unallocated', $data,
            $csv->costCenterUnallocatedRows($report, $currency),
            "cost-center-unallocated_{$data['from']}_{$data['to']}",
            'Cost Center Unallocated Lines / بنود بدون مركز تكلفة',
            ['period' => "{$data['from']} - {$data['to']}", 'currency' => $currency],
        );
    }
}
