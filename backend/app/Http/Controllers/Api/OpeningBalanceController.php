<?php

namespace App\Http\Controllers\Api;

use App\Modules\Accounting\Models\OpeningBalanceBatch;
use App\Modules\Accounting\Services\OpeningBalanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class OpeningBalanceController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $branch = $this->branch();

        $batches = OpeningBalanceBatch::query()->with('branch')
            ->when($branch, fn ($query) => $query->where('branch_id', $branch->id))
            ->when($request->query('status'), fn ($query, $status) => $query->where('status', $status))
            ->orderByDesc('as_of_date')->orderByDesc('id')
            ->paginate(min(100, max(1, (int) $request->query('per_page', 50))));

        $batches->getCollection()->transform(fn (OpeningBalanceBatch $batch) => $batch->toApiArray());

        return $this->paginated($batches);
    }

    public function show(OpeningBalanceBatch $batch): JsonResponse
    {
        $this->authorizeBranchId($batch->branch_id);

        return $this->ok($batch->load('lines.account', 'branch')->toApiArray(true));
    }

    public function store(Request $request, OpeningBalanceService $service): JsonResponse
    {
        $data = $request->validate([
            'as_of_date' => ['required', 'date'],
            'memo' => ['nullable', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:2', 'max:2000'],
            'lines.*.account_id' => ['required', 'string'],
            'lines.*.currency' => ['nullable', 'string', 'size:3', Rule::exists('currencies', 'code')],
            'lines.*.fx_rate' => ['nullable', 'numeric', 'gt:0'],
            'lines.*.debit_minor' => ['nullable', 'integer', 'min:0'],
            'lines.*.credit_minor' => ['nullable', 'integer', 'min:0'],
            'lines.*.party_type' => ['nullable', Rule::in(['customer', 'supplier'])],
            'lines.*.party_id' => ['nullable', 'string'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
        ]);

        foreach ($data['lines'] as $index => $line) {
            if (isset($line['fx_rate'])) {
                $data['lines'][$index]['fx_rate'] = (string) $request->input("lines.{$index}.fx_rate");
            }
        }

        $batch = $service->create($data, $this->branchOrFail(), $this->actorId());

        return $this->created($batch->toApiArray(true));
    }

    public function destroy(OpeningBalanceBatch $batch, OpeningBalanceService $service): Response
    {
        $this->authorizeBranchId($batch->branch_id);

        $service->discard($batch, $this->actorId());

        return response()->noContent();
    }

    public function post(OpeningBalanceBatch $batch, OpeningBalanceService $service): JsonResponse
    {
        $this->authorizeBranchId($batch->branch_id);

        return $this->ok($service->post($batch, $this->actorId())->toApiArray(true));
    }

    public function reverse(Request $request, OpeningBalanceBatch $batch, OpeningBalanceService $service): JsonResponse
    {
        $this->authorizeBranchId($batch->branch_id);

        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return $this->ok($service->reverse($batch, $data['reason'], $this->actorId())->toApiArray(true));
    }

    public function reconciliation(OpeningBalanceBatch $batch, OpeningBalanceService $service): JsonResponse
    {
        $this->authorizeBranchId($batch->branch_id);

        return $this->ok($service->reconciliation($batch));
    }
}
