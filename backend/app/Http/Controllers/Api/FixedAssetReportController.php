<?php

namespace App\Http\Controllers\Api;

use App\Modules\Accounting\Models\FixedAsset;
use App\Modules\Accounting\Services\FixedAssetReportService;
use App\Modules\Core\Models\Branch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Fixed-asset reports: roll-forward, carrying values, movements, schedule and
 * control-account reconciliation (Prompt 25).
 *
 * Reports are branch-scoped by default. Users who may view all branches can
 * request installation-wide figures with `?global=1`, which is the only scope
 * in which register and GL control accounts are expected to agree after an
 * operational branch transfer.
 */
class FixedAssetReportController extends ApiController
{
    public function rollForward(Request $request, FixedAssetReportService $reports): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'to' => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
        ]);

        return $this->ok($reports->rollForward($this->scope($request), $data['from'], $data['to']));
    }

    public function carryingValues(Request $request, FixedAssetReportService $reports): JsonResponse
    {
        $data = $request->validate([
            'as_of' => ['nullable', 'date'],
            'include_disposed' => ['nullable', 'boolean'],
        ]);

        return $this->ok($reports->carryingValues(
            $this->scope($request),
            $data['as_of'] ?? null,
            (bool) ($data['include_disposed'] ?? false),
        ));
    }

    public function movements(Request $request, FixedAssetReportService $reports): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'to' => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
        ]);

        return $this->ok($reports->movements($this->scope($request), $data['from'], $data['to']));
    }

    public function reconciliation(Request $request, FixedAssetReportService $reports): JsonResponse
    {
        $data = $request->validate([
            'as_of' => ['nullable', 'date'],
        ]);

        return $this->ok($reports->reconciliation($this->scope($request), $data['as_of'] ?? null));
    }

    public function schedule(Request $request, FixedAssetReportService $reports): JsonResponse
    {
        $asset = null;

        if ($request->query('asset')) {
            $asset = FixedAsset::query()->where('public_id', $request->query('asset'))->firstOrFail();
            $this->authorizeBranchId($asset->branch_id);
        }

        return $this->ok($reports->schedule($asset, $this->scope($request)));
    }

    private function scope(Request $request): ?Branch
    {
        if ($request->boolean('global') && $request->user()?->can('branches.view_all')) {
            return null;
        }

        return $this->branch();
    }
}
