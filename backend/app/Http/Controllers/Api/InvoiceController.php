<?php

namespace App\Http\Controllers\Api;

use App\Modules\Accounting\Models\Account;
use App\Modules\Invoices\Models\Invoice;
use App\Modules\Invoices\Services\InvoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class InvoiceController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $branch = $this->branch();

        $invoices = Invoice::query()->with('lines.account', 'party', 'journalEntry')
            ->when($branch, fn ($query) => $query->where('branch_id', $branch->id))
            ->when($request->query('type'), fn ($query, $type) => $query->where('type', $type))
            ->when($request->query('status'), fn ($query, $status) => $query->where('status', $status))
            ->when($request->query('party_type'), fn ($query, $partyType) => $query->where('party_type', $partyType))
            ->when($request->query('party_id'), fn ($query, $partyId) => $query->where('party_id', $partyId))
            ->when($request->query('from'), fn ($query, $from) => $query->whereDate('issue_date', '>=', $from))
            ->when($request->query('to'), fn ($query, $to) => $query->whereDate('issue_date', '<=', $to))
            ->when($request->query('search'), function ($query, $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('number', 'like', "%{$search}%")
                        ->orWhereHas('party', fn ($q) => $q->where('name_ar', 'like', "%{$search}%")
                            ->orWhere('name_en', 'like', "%{$search}%"));
                });
            })
            ->orderByDesc('issue_date')->orderByDesc('id')
            ->paginate(min(100, max(1, (int) $request->query('per_page', 50))));

        $invoices->getCollection()->transform(fn (Invoice $invoice) => $invoice->toApiArray());

        return $this->paginated($invoices);
    }

    public function show(Invoice $invoice): JsonResponse
    {
        $this->authorizeBranchId($invoice->branch_id);

        return $this->ok($invoice->load('lines.account', 'party', 'journalEntry', 'creditNoteOf', 'creditNotes')->toApiArray(true));
    }

    public function store(Request $request, InvoiceService $invoices): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['customer_invoice', 'supplier_invoice'])],
            'series' => ['nullable', 'string', 'max:10'],
            'party_type' => ['required', Rule::in(['customer', 'supplier'])],
            'party_id' => ['required', 'string'],
            'booking_id' => ['nullable', 'string'],
            'issue_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date'],
            'currency' => ['nullable', 'string', 'size:3', Rule::exists('currencies', 'code')],
            'fx_rate' => ['nullable', 'numeric', 'gt:0'],
            'subtotal_minor' => ['nullable', 'integer'],
            'tax_minor' => ['nullable', 'integer', 'min:0'],
            'memo' => ['nullable', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.account_id' => ['required', 'string'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
            'lines.*.quantity' => ['nullable', 'integer', 'min:1'],
            'lines.*.unit_price_minor' => ['nullable', 'integer', 'min:1'],
            'lines.*.debit_minor' => ['nullable', 'integer', 'min:0'],
            'lines.*.credit_minor' => ['nullable', 'integer', 'min:0'],
            'lines.*.tax_minor' => ['nullable', 'integer', 'min:0'],
            'lines.*.tax_account_id' => ['nullable', 'string'],
            'lines.*.party_type' => ['nullable', Rule::in(['customer', 'supplier'])],
            'lines.*.party_id' => ['nullable', 'string'],
            'submit' => ['nullable', 'boolean'],
        ]);

        if (isset($data['fx_rate'])) {
            $data['fx_rate'] = (string) $request->input('fx_rate');
        }

        $invoice = $invoices->create($data, $this->branchOrFail(), $this->actorId());

        return $this->created($invoice->toApiArray(true));
    }

    public function update(Request $request, Invoice $invoice, InvoiceService $invoices): JsonResponse
    {
        $this->authorizeBranchId($invoice->branch_id);

        if ($invoice->status !== 'draft') {
            throw new \DomainException('Only draft invoices can be edited.');
        }

        $data = $request->validate([
            'issue_date' => ['sometimes', 'date'],
            'due_date' => ['sometimes', 'nullable', 'date'],
            'currency' => ['sometimes', 'string', 'size:3', Rule::exists('currencies', 'code')],
            'fx_rate' => ['sometimes', 'numeric', 'gt:0'],
            'memo' => ['sometimes', 'string', 'max:255'],
            'lines' => ['sometimes', 'array', 'min:1'],
            'lines.*.account_id' => ['sometimes', 'required', 'string'],
            'lines.*.description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'lines.*.quantity' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'lines.*.unit_price_minor' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'lines.*.debit_minor' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'lines.*.credit_minor' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'lines.*.tax_minor' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'lines.*.tax_account_id' => ['sometimes', 'nullable', 'string'],
            'lines.*.party_type' => ['sometimes', 'nullable', Rule::in(['customer', 'supplier'])],
            'lines.*.party_id' => ['sometimes', 'nullable', 'string'],
        ]);

        if (isset($data['fx_rate'])) {
            $data['fx_rate'] = (string) $request->input('fx_rate');
        }

        // Rebuild lines for a draft invoice.
        $invoice->lines()->delete();
        foreach ($data['lines'] ?? [] as $line) {
            $account = Account::query()->where('public_id', $line['account_id'])->firstOrFail();
            $invoice->lines()->create([
                'account_id' => $account->id,
                'description' => $line['description'] ?? null,
                'quantity' => (int) ($line['quantity'] ?? 1),
                'unit_price_minor' => $line['unit_price_minor'] ?? null,
                'debit_minor' => (int) ($line['debit_minor'] ?? 0),
                'credit_minor' => (int) ($line['credit_minor'] ?? 0),
                'tax_minor' => (int) ($line['tax_minor'] ?? 0),
                'tax_account_id' => $line['tax_account_id'] ?? null,
                'party_type' => $line['party_type'] ?? null,
                'party_id' => $line['party_id'] ?? null,
            ]);
        }

        $invoice->update(array_filter([
            'issue_date' => $data['issue_date'],
            'due_date' => $data['due_date'] ?? null,
            'currency_code' => $data['currency'] ?? null,
            'fx_rate' => $data['fx_rate'] ?? null,
            'memo' => $data['memo'] ?? null,
        ]));

        return $this->ok($invoice->refresh()->load('lines.account')->toApiArray(true));
    }

    public function submit(Invoice $invoice, InvoiceService $invoices): JsonResponse
    {
        $this->authorizeBranchId($invoice->branch_id);

        return $this->ok($invoices->submit($invoice, $this->actorId())->toApiArray(true));
    }

    public function approve(Invoice $invoice, InvoiceService $invoices): JsonResponse
    {
        $this->authorizeBranchId($invoice->branch_id);

        return $this->ok($invoices->approve($invoice, $this->actorId())->toApiArray(true));
    }

    public function issue(Invoice $invoice, InvoiceService $invoices): JsonResponse
    {
        $this->authorizeBranchId($invoice->branch_id);

        return $this->ok($invoices->issue($invoice, $this->actorId())->toApiArray(true));
    }

    public function void(Invoice $invoice, InvoiceService $invoices): JsonResponse
    {
        $this->authorizeBranchId($invoice->branch_id);

        return $this->ok($invoices->void($invoice, $this->actorId())->toApiArray(true));
    }

    public function creditNote(Request $request, Invoice $invoice, InvoiceService $invoices): JsonResponse
    {
        $this->authorizeBranchId($invoice->branch_id);

        $data = $request->validate([
            'series' => ['nullable', 'string', 'max:10'],
            'issue_date' => ['nullable', 'date'],
            'total_minor' => ['nullable', 'integer'],
            'memo' => ['nullable', 'string', 'max:255'],
        ]);

        return $this->created($invoices->creditNote($invoice, $data, $this->actorId())->toApiArray(true));
    }
}
