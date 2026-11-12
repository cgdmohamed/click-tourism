<?php

namespace App\Http\Controllers\Api;

use App\Modules\Bookings\Models\Booking;
use App\Modules\Core\Services\AuditService;
use App\Modules\Invoices\Models\Invoice;
use App\Modules\Invoices\Services\InvoiceDocument;
use App\Modules\Invoices\Services\InvoiceService;
use App\Modules\Parties\Models\Customer;
use App\Modules\Parties\Models\Supplier;
use App\Support\Like;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
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
            ->when($request->query('booking_id'), function ($query, $reference) {
                $bookingId = Booking::query()
                    ->where('public_id', $reference)->value('id');

                return $query->where('booking_id', $bookingId ?? 0);
            })
            ->when($request->query('party_type'), fn ($query, $partyType) => $query->where('party_type', $partyType))
            ->when($request->query('party_id'), function ($query, $partyId) use ($request) {
                // Accept the public id as well as the internal one.
                if (! ctype_digit((string) $partyId)) {
                    $model = match ($request->query('party_type')) {
                        'supplier' => Supplier::class,
                        default => Customer::class,
                    };
                    $partyId = $model::query()->where('public_id', $partyId)->value('id') ?? 0;
                }

                return $query->where('party_id', $partyId);
            })
            ->when($request->query('currency'), fn ($query, $currency) => $query->where('currency_code', strtoupper((string) $currency)))
            ->when($request->boolean('open'), fn ($query) => $query->where('status', 'issued')->where('outstanding_minor', '>', 0))
            ->when($request->query('from'), fn ($query, $from) => $query->whereDate('issue_date', '>=', $from))
            ->when($request->query('to'), fn ($query, $to) => $query->whereDate('issue_date', '<=', $to))
            ->when(Like::term($request->query('search')), function ($query, $search) {
                $query->where(function ($query) use ($search) {
                    Like::contains($query, ['number'], $search);
                    $query->orWhereHas('party', fn ($party) => Like::contains($party, ['name_ar', 'name_en'], $search));
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

    /**
     * Server-rendered PDF of one invoice or credit note (ADR-0014).
     *
     * The document is generated from stored data only. It never posts,
     * revalues or mutates the invoice or its journal; the PDF is a rendering
     * of the immutable issue-time snapshot already in the database.
     */
    public function pdf(Request $request, Invoice $invoice, InvoiceDocument $documents, AuditService $audit): Response
    {
        $this->authorizeBranchId($invoice->branch_id);

        $data = $request->validate([
            'language' => ['nullable', Rule::in(['ar', 'en', 'bilingual'])],
        ]);
        $language = $data['language']
            ?? (string) $this->settings()->get('documents', 'default_language', 'bilingual');

        $pdf = $documents->pdf($invoice, $language);

        // Provenance only: no party names, amounts or line content.
        $audit->log('invoice.exported', $invoice, null, [
            'type' => $invoice->type,
            'number' => $invoice->number,
            'language' => $language,
            'branch' => $invoice->branch?->public_id,
        ], $this->actorId());

        $filename = preg_replace('/[^A-Za-z0-9._-]/', '_', 'invoice-'.$invoice->number).'.pdf';

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
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
            'lines.*.tax_applied' => ['sometimes', 'boolean'],
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
            'lines.*.tax_applied' => ['sometimes', 'boolean'],
            'lines.*.tax_account_id' => ['sometimes', 'nullable', 'string'],
            'lines.*.party_type' => ['sometimes', 'nullable', Rule::in(['customer', 'supplier'])],
            'lines.*.party_id' => ['sometimes', 'nullable', 'string'],
        ]);

        if (isset($data['fx_rate'])) {
            $data['fx_rate'] = (string) $request->input('fx_rate');
        }

        return $this->ok($invoices->updateDraft($invoice, $data, $this->actorId())->toApiArray(true));
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

    public function issue(Request $request, Invoice $invoice, InvoiceService $invoices): JsonResponse
    {
        $this->authorizeBranchId($invoice->branch_id);

        $data = $request->validate(['override_reason' => ['nullable', 'string', 'max:500']]);
        $issued = $invoices->issue(
            $invoice, $this->actorId(), $data['override_reason'] ?? null,
            (bool) $request->user()?->can('bookings.override_credit_limit'),
        );

        return $this->ok($issued->toApiArray(true));
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
