<?php

namespace App\Http\Controllers\Api;

use App\Modules\Treasury\Models\Transfer;
use App\Modules\Treasury\Services\TreasuryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TransferController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $branch = $this->branch();

        $transfers = Transfer::query()
            ->with(['from.account', 'to.account', 'journalEntry'])
            ->when($branch, fn ($query) => $query->where('branch_id', $branch->id))
            ->when($request->query('status'), fn ($query, $status) => $query->where('status', $status))
            // Either side of the transfer (public id of the cash or bank account).
            ->when($request->query('cash_account_id'), fn ($query, $accountId) => $query->where(function ($query) use ($accountId) {
                $query->whereHas('from', fn ($account) => $account->where('public_id', $accountId))
                    ->orWhereHas('to', fn ($account) => $account->where('public_id', $accountId));
            }))
            ->when($request->query('from'), fn ($query, $from) => $query->whereDate('transfer_date', '>=', $from))
            ->when($request->query('to'), fn ($query, $to) => $query->whereDate('transfer_date', '<=', $to))
            ->orderByDesc('transfer_date')
            ->orderByDesc('id')
            ->paginate(min(100, max(1, (int) $request->query('per_page', 50))));

        $transfers->getCollection()->transform(fn (Transfer $transfer) => $transfer->toApiArray());

        return $this->paginated($transfers);
    }

    public function show(Transfer $transfer): JsonResponse
    {
        $this->authorizeBranchId($transfer->branch_id);

        return $this->ok($transfer->load('from.account', 'to.account', 'journalEntry')->toApiArray());
    }

    public function store(Request $request, TreasuryService $treasury): JsonResponse
    {
        $data = $request->validate([
            'from_cash_account_id' => ['required', 'string', Rule::exists('cash_accounts', 'public_id')],
            'to_cash_account_id' => ['required', 'string', Rule::exists('cash_accounts', 'public_id')],
            'transfer_date' => ['required', 'date'],
            'amount_minor' => ['required', 'integer', 'min:1'],
            'to_amount_minor' => ['nullable', 'integer', 'min:1'],
            'from_fx_rate' => ['nullable', 'numeric', 'gt:0'],
            'to_fx_rate' => ['nullable', 'numeric', 'gt:0'],
            'fee_minor' => ['nullable', 'integer', 'min:0'],
            'memo' => ['nullable', 'string', 'max:255'],
        ]);

        foreach (['from_fx_rate', 'to_fx_rate'] as $key) {
            if (isset($data[$key])) {
                $data[$key] = (string) $request->input($key);
            }
        }

        $transfer = $treasury->transfer($data, $this->branchOrFail(), $this->actorId());

        return $this->created($transfer->toApiArray());
    }

    public function reverse(Request $request, Transfer $transfer, TreasuryService $treasury): JsonResponse
    {
        $this->authorizeBranchId($transfer->branch_id);

        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return $this->ok($treasury->reverse($transfer, $data['reason'], $this->actorId())->toApiArray());
    }
}
