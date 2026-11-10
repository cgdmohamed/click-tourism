<?php

namespace App\Http\Controllers\Api;

use App\Modules\Invoices\Models\InvoiceApplication;
use App\Modules\Invoices\Services\InvoiceSettlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvoiceApplicationController extends ApiController
{
    public function destroy(Request $request, InvoiceApplication $application, InvoiceSettlementService $settlement): JsonResponse
    {
        $this->authorizeBranchId($application->branch_id);

        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return $this->ok($settlement->unapply($application, $data['reason'], $this->actorId())->toApiArray());
    }
}
