<?php

namespace App\Modules\Parties\Services;

use App\Modules\Bookings\Models\Booking;
use App\Modules\Core\Models\Branch;
use App\Modules\Invoices\Models\Invoice;
use App\Modules\Parties\Models\Customer;
use App\Modules\Parties\Models\Employee;
use App\Modules\Parties\Models\EmployeeAdvance;
use App\Modules\Parties\Models\EmployeeCustody;
use App\Modules\Parties\Models\EmployeeTransaction;
use App\Modules\Parties\Models\Supplier;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\SupplierObligation;
use App\Modules\Payments\Models\SupplierPayment;
use App\Modules\Reports\Services\PartyStatementService;

/**
 * Read-only 360° party profiles (Prompt 27).
 *
 * Profiles compose existing operational records with the party's genuine
 * subsidiary-ledger statement. Nothing here posts, mutates balances or
 * duplicates the ledger: the statement remains the single balance truth and
 * operational documents are shown next to it for reconciliation.
 */
class PartyProfileService
{
    public function __construct(
        private readonly PartyStatementService $statements,
        private readonly CreditLimitService $creditLimits,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function customer(Customer $customer, ?Branch $branch, string $from, string $to): array
    {
        $customer->load('branch');

        $bookingQuery = Booking::query()
            ->where('customer_id', $customer->id)
            ->when($branch, fn ($query) => $query->where('branch_id', $branch->id));

        $bookings = (clone $bookingQuery)
            ->with('customer', 'branch')
            ->orderByDesc('start_date')->orderByDesc('id')
            ->limit(25)->get()
            ->map(fn (Booking $booking) => $booking->toApiArray())
            ->values()->all();

        $invoiceQuery = Invoice::query()
            ->where('party_type', 'customer')
            ->where('party_id', $customer->id)
            ->when($branch, fn ($query) => $query->where('branch_id', $branch->id));

        $invoices = (clone $invoiceQuery)
            ->with('lines.account', 'party', 'journalEntry')
            ->orderByDesc('issue_date')->orderByDesc('id')
            ->limit(25)->get()
            ->map(fn (Invoice $invoice) => $invoice->toApiArray())
            ->values()->all();

        $paymentQuery = Payment::query()
            ->where('customer_id', $customer->id)
            ->when($branch, fn ($query) => $query->where('branch_id', $branch->id));

        $payments = (clone $paymentQuery)
            ->with('customer', 'booking')
            ->orderByDesc('paid_at')->orderByDesc('id')
            ->limit(25)->get()
            ->map(fn (Payment $payment) => $payment->toApiArray())
            ->values()->all();

        return [
            'party_type' => 'customer',
            'party' => $customer->toApiArray(),
            'metrics' => [
                'bookings_count' => (clone $bookingQuery)->count(),
                'open_bookings_count' => (clone $bookingQuery)
                    ->whereIn('status', ['draft', 'quoted', 'confirmed', 'in_progress'])->count(),
                'invoices_count' => (clone $invoiceQuery)->count(),
                'open_invoices_count' => (clone $invoiceQuery)
                    ->where('status', Invoice::STATUS_ISSUED)
                    ->where('outstanding_minor', '>', 0)->count(),
                'payments_count' => (clone $paymentQuery)->count(),
                'last_activity_on' => $this->lastActivity(
                    (clone $bookingQuery)->max('start_date'),
                    (clone $invoiceQuery)->max('issue_date'),
                    (clone $paymentQuery)->max('paid_at'),
                ),
            ],
            'credit' => $this->creditLimits->summary($customer),
            'bookings' => $bookings,
            'invoices' => $invoices,
            'payments' => $payments,
            'statement' => $this->statements->statement('customer', $customer->id, $from, $to, $branch),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function supplier(Supplier $supplier, ?Branch $branch, string $from, string $to): array
    {
        $supplier->load('branch');

        $invoiceQuery = Invoice::query()
            ->where('party_type', 'supplier')
            ->where('party_id', $supplier->id)
            ->when($branch, fn ($query) => $query->where('branch_id', $branch->id));

        $invoices = (clone $invoiceQuery)
            ->with('lines.account', 'party', 'journalEntry')
            ->orderByDesc('issue_date')->orderByDesc('id')
            ->limit(25)->get()
            ->map(fn (Invoice $invoice) => $invoice->toApiArray())
            ->values()->all();

        $obligationQuery = SupplierObligation::query()
            ->where('supplier_id', $supplier->id)
            ->when($branch, fn ($query) => $query->where('branch_id', $branch->id));

        $obligations = (clone $obligationQuery)
            ->with('supplier', 'branch')
            ->orderByDesc('id')
            ->limit(25)->get()
            ->map(fn (SupplierObligation $obligation) => $obligation->toApiArray())
            ->values()->all();

        $paymentQuery = SupplierPayment::query()
            ->where('supplier_id', $supplier->id)
            ->when($branch, fn ($query) => $query->where('branch_id', $branch->id));

        $payments = (clone $paymentQuery)
            ->with('supplier', 'branch', 'obligation')
            ->orderByDesc('paid_at')->orderByDesc('id')
            ->limit(25)->get()
            ->map(fn (SupplierPayment $payment) => $payment->toApiArray())
            ->values()->all();

        return [
            'party_type' => 'supplier',
            'party' => $supplier->toApiArray(),
            'metrics' => [
                'invoices_count' => (clone $invoiceQuery)->count(),
                'open_invoices_count' => (clone $invoiceQuery)
                    ->where('status', Invoice::STATUS_ISSUED)
                    ->where('outstanding_minor', '>', 0)->count(),
                'obligations_count' => (clone $obligationQuery)->count(),
                'open_obligations_count' => (clone $obligationQuery)->where('status', '!=', 'paid')->count(),
                'payments_count' => (clone $paymentQuery)->count(),
                'last_activity_on' => $this->lastActivity(
                    (clone $invoiceQuery)->max('issue_date'),
                    (clone $obligationQuery)->max('created_at'),
                    (clone $paymentQuery)->max('paid_at'),
                ),
            ],
            'invoices' => $invoices,
            'obligations' => $obligations,
            'payments' => $payments,
            'statement' => $this->statements->statement('supplier', $supplier->id, $from, $to, $branch),
        ];
    }

    /**
     * Employee financial profile. Identity and financial activity only — this
     * is not an HR or payroll suite.
     *
     * @return array<string, mixed>
     */
    public function employee(Employee $employee, ?Branch $branch, string $from, string $to): array
    {
        $employee->load('branch', 'user');

        $advanceQuery = EmployeeAdvance::query()
            ->where('employee_id', $employee->id)
            ->when($branch, fn ($query) => $query->where('branch_id', $branch->id));

        $advances = (clone $advanceQuery)
            ->with('employee', 'branch', 'cashAccount')
            ->orderByDesc('request_date')->orderByDesc('id')
            ->limit(25)->get()
            ->map(fn (EmployeeAdvance $advance) => $advance->toApiArray())
            ->values()->all();

        $custodyQuery = EmployeeCustody::query()
            ->where('employee_id', $employee->id)
            ->when($branch, fn ($query) => $query->where('branch_id', $branch->id));

        $custodies = (clone $custodyQuery)
            ->with('employee', 'branch', 'cashAccount')
            ->orderByDesc('issue_date')->orderByDesc('id')
            ->limit(25)->get()
            ->map(fn (EmployeeCustody $custody) => $custody->toApiArray())
            ->values()->all();

        $transactionQuery = EmployeeTransaction::query()
            ->where('employee_id', $employee->id)
            ->when($branch, fn ($query) => $query->where('branch_id', $branch->id));

        $transactions = (clone $transactionQuery)
            ->with('employee', 'branch', 'journalEntry')
            ->orderByDesc('transaction_date')->orderByDesc('id')
            ->limit(30)->get()
            ->map(fn (EmployeeTransaction $transaction) => $transaction->toApiArray())
            ->values()->all();

        return [
            'party_type' => 'employee',
            'party' => $employee->toApiArray(),
            'employee' => $employee->toApiArray(true),
            'user' => $employee->user ? [
                'id' => $employee->user->public_id,
                'name' => $employee->user->name,
                'email' => $employee->user->email,
            ] : null,
            'metrics' => [
                'advances_count' => (clone $advanceQuery)->count(),
                'open_advances_count' => (clone $advanceQuery)
                    ->whereIn('status', [EmployeeAdvance::STATUS_APPROVED, EmployeeAdvance::STATUS_DISBURSED])->count(),
                'custodies_count' => (clone $custodyQuery)->count(),
                'open_custodies_count' => (clone $custodyQuery)
                    ->whereIn('status', [EmployeeCustody::STATUS_ISSUED, EmployeeCustody::STATUS_CLEARED])->count(),
                'transactions_count' => (clone $transactionQuery)->count(),
                'advances_outstanding_minor' => (int) (clone $advanceQuery)
                    ->whereIn('status', [EmployeeAdvance::STATUS_APPROVED, EmployeeAdvance::STATUS_DISBURSED])
                    ->sum('outstanding_minor'),
                'custody_outstanding_minor' => (int) (clone $custodyQuery)
                    ->whereIn('status', [EmployeeCustody::STATUS_ISSUED, EmployeeCustody::STATUS_CLEARED])
                    ->sum('outstanding_minor'),
                'last_activity_on' => $this->lastActivity(
                    (clone $transactionQuery)->max('transaction_date'),
                    (clone $advanceQuery)->max('request_date'),
                    (clone $custodyQuery)->max('issue_date'),
                ),
            ],
            'advances' => $advances,
            'custodies' => $custodies,
            'transactions' => $transactions,
            'statement' => $this->statements->statement('employee', $employee->id, $from, $to, $branch),
        ];
    }

    private function lastActivity(mixed ...$dates): ?string
    {
        $values = array_values(array_filter(
            array_map(fn ($value) => $value !== null ? substr((string) $value, 0, 10) : null, $dates),
            fn ($value) => $value !== null && $value !== '',
        ));

        return $values === [] ? null : max($values);
    }
}
