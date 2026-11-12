<?php

namespace App\Http\Controllers\Api;

use App\Modules\Accounting\Models\AccountingDocument;
use App\Modules\Accounting\Services\AccountingDocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AccountingDocumentController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $branch = $this->branch();

        $documents = AccountingDocument::query()->with('lines.account', 'journalEntry')
            ->when($branch, fn ($query) => $query->where('branch_id', $branch->id))
            ->when($request->query('type'), fn ($query, $type) => $query->where('type', $type))
            ->when($request->query('status'), fn ($query, $status) => $query->where('status', $status))
            ->when($request->query('party_type'), fn ($query, $partyType) => $query->where('party_type', $partyType))
            ->when($request->query('party_id'), fn ($query, $partyId) => $query->where('party_id', $partyId))
            ->when($request->query('from'), fn ($query, $from) => $query->where('fx_rate_date', '>=', $from))
            ->when($request->query('to'), fn ($query, $to) => $query->where('fx_rate_date', '<=', $to))
            ->when($request->query('search'), function ($query, $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('number', 'like', "%{$search}%")
                        ->orWhere('memo', 'like', "%{$search}%");
                });
            })
            ->orderByDesc('fx_rate_date')->orderByDesc('id')
            ->paginate(min(100, max(1, (int) $request->query('per_page', 50))));

        $documents->getCollection()->transform(fn (AccountingDocument $document) => $document->toApiArray());

        return $this->paginated($documents);
    }

    public function show(AccountingDocument $document): JsonResponse
    {
        $this->authorizeBranchId($document->branch_id);

        return $this->ok($document->load('lines.account', 'lines.taxAccount', 'journalEntry', 'reversalOf', 'reversals')->toApiArray(true));
    }

    public function store(Request $request, AccountingDocumentService $service): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(AccountingDocumentService::types())],
            'series' => ['nullable', 'string', 'max:10'],
            'party_type' => ['nullable', Rule::in(['customer', 'supplier', 'employee'])],
            'party_id' => ['nullable', 'string'],
            'booking_id' => ['nullable', 'string'],
            'cash_account_id' => ['nullable', 'string', Rule::exists('cash_accounts', 'public_id')],
            'currency' => ['nullable', 'string', 'size:3', Rule::exists('currencies', 'code')],
            'fx_rate' => ['nullable', 'numeric', 'gt:0'],
            'document_date' => ['nullable', 'date'],
            'memo' => ['nullable', 'string', 'max:255'],
            'submit' => ['nullable', 'boolean'],
            'simple_mode' => ['nullable', 'boolean'],
            'amount_minor' => ['required_if:simple_mode,true', 'nullable', 'integer', 'min:1'],
            'lines' => ['required_unless:simple_mode,true', 'prohibited_if:simple_mode,true', 'array', 'min:1'],
            'lines.*.account_id' => ['required'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
            'lines.*.debit_minor' => ['nullable', 'integer', 'min:0'],
            'lines.*.credit_minor' => ['nullable', 'integer', 'min:0'],
            'lines.*.tax_minor' => ['nullable', 'integer', 'min:0'],
            'lines.*.tax_applied' => ['sometimes', 'boolean'],
            'lines.*.tax_account_id' => ['nullable'],
            'lines.*.party_type' => ['nullable', Rule::in(['customer', 'supplier', 'employee'])],
            'lines.*.party_id' => ['nullable'],
            'idempotency_key' => ['nullable', 'string'],
        ]);

        if (isset($data['fx_rate'])) {
            $data['fx_rate'] = (string) $request->input('fx_rate');
        }

        $document = $service->create($data, $this->branchOrFail(), $this->actorId());

        return $this->created($document->toApiArray(true));
    }

    public function update(Request $request, AccountingDocument $document, AccountingDocumentService $service): JsonResponse
    {
        $this->authorizeBranchId($document->branch_id);

        $data = $request->validate([
            'document_date' => ['sometimes', 'date'],
            'currency' => ['sometimes', 'string', 'size:3', Rule::exists('currencies', 'code')],
            'fx_rate' => ['sometimes', 'numeric', 'gt:0'],
            'memo' => ['sometimes', 'nullable', 'string', 'max:255'],
            'lines' => ['sometimes', 'array', 'min:1'],
            'lines.*.account_id' => ['required'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
            'lines.*.debit_minor' => ['nullable', 'integer', 'min:0'],
            'lines.*.credit_minor' => ['nullable', 'integer', 'min:0'],
            'lines.*.tax_minor' => ['nullable', 'integer', 'min:0'],
            'lines.*.tax_applied' => ['sometimes', 'boolean'],
            'lines.*.tax_account_id' => ['nullable'],
            'lines.*.party_type' => ['nullable', Rule::in(['customer', 'supplier', 'employee'])],
            'lines.*.party_id' => ['nullable'],
        ]);

        if (isset($data['fx_rate'])) {
            $data['fx_rate'] = (string) $request->input('fx_rate');
        }

        return $this->ok($service->updateDraft($document, $data, $this->actorId())->toApiArray(true));
    }

    public function submit(AccountingDocument $document, AccountingDocumentService $service): JsonResponse
    {
        $this->authorizeBranchId($document->branch_id);

        return $this->ok($service->submit($document, $this->actorId())->toApiArray(true));
    }

    public function approve(AccountingDocument $document, AccountingDocumentService $service): JsonResponse
    {
        $this->authorizeBranchId($document->branch_id);

        return $this->ok($service->approve($document, $this->actorId())->toApiArray(true));
    }

    public function post(AccountingDocument $document, AccountingDocumentService $service): JsonResponse
    {
        $this->authorizeBranchId($document->branch_id);

        return $this->ok($service->post($document, $this->actorId())->toApiArray(true));
    }

    public function cancel(AccountingDocument $document, AccountingDocumentService $service): JsonResponse
    {
        $this->authorizeBranchId($document->branch_id);

        return $this->ok($service->cancel($document, $this->actorId())->toApiArray(true));
    }

    public function reject(Request $request, AccountingDocument $document, AccountingDocumentService $service): JsonResponse
    {
        $this->authorizeBranchId($document->branch_id);

        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return $this->ok($service->reject($document, $data['reason'], $this->actorId())->toApiArray(true));
    }

    public function reopen(AccountingDocument $document, AccountingDocumentService $service): JsonResponse
    {
        $this->authorizeBranchId($document->branch_id);

        $user = request()->user();
        abort_unless($document->created_by === $user?->id || $user?->can('documents.approve'), 403, 'Only the creator or an approver can reopen this document.');

        return $this->ok($service->reopen($document, $this->actorId())->toApiArray(true));
    }

    public function correct(Request $request, AccountingDocument $document, AccountingDocumentService $service): JsonResponse
    {
        $this->authorizeBranchId($document->branch_id);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
            'series' => ['nullable', 'string', 'max:10'],
            'memo' => ['nullable', 'string', 'max:255'],
        ]);

        $correction = $service->correct($document, $data, $this->actorId());

        return $this->created($correction->toApiArray(true));
    }
}
