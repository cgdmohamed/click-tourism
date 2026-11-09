<?php

namespace App\Http\Controllers\Api;

use App\Modules\Accounting\Models\Account;
use App\Modules\Reports\Services\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ReportController extends ApiController
{
    public function trialBalance(Request $request, ReportService $reports): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);

        return $this->ok($reports->trialBalance($data['from'], $data['to'], $this->branch()));
    }

    public function generalLedger(Request $request, ReportService $reports): JsonResponse
    {
        $data = $request->validate([
            'account_id' => ['required', 'string', Rule::exists('accounts', 'public_id')],
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $page = (int) ($data['page'] ?? 1);
        $perPage = (int) ($data['per_page'] ?? 50);

        $account = Account::query()->where('public_id', $data['account_id'])->firstOrFail();
        $result = $reports->generalLedger($account, $data['from'], $data['to'], $this->branch(), $page, $perPage);

        return response()->json([
            'data' => $result['summary'] + ['lines' => $result['lines']],
            'meta' => [
                'current_page' => $page,
                'last_page' => max(1, (int) ceil($result['total'] / $perPage)),
                'total' => $result['total'],
                'per_page' => $perPage,
            ],
        ]);
    }

    public function incomeStatement(Request $request, ReportService $reports): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);

        return $this->ok($reports->incomeStatement($data['from'], $data['to'], $this->branch()));
    }

    public function balanceSheet(Request $request, ReportService $reports): JsonResponse
    {
        $data = $request->validate([
            'as_of' => ['required', 'date'],
        ]);

        return $this->ok($reports->balanceSheet($data['as_of'], $this->branch()));
    }

    public function aging(Request $request, ReportService $reports): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:ar,ap'],
            'as_of' => ['required', 'date'],
        ]);

        return $this->ok($reports->aging($data['type'], $data['as_of'], $this->branch()));
    }

    public function dashboard(ReportService $reports): JsonResponse
    {
        return $this->ok($reports->dashboard($this->branch()));
    }
}
