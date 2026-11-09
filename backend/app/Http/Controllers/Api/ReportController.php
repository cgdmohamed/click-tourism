<?php

namespace App\Http\Controllers\Api;

use App\Modules\Reports\Services\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
