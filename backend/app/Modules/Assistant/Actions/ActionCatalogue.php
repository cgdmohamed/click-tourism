<?php

namespace App\Modules\Assistant\Actions;

use App\Modules\Accounting\Models\AccountingDocument;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Commissions\Models\Commission;
use App\Modules\Core\Models\User;
use App\Modules\CRM\Models\Lead;
use App\Modules\Expenses\Models\Expense;
use App\Modules\Invoices\Models\Invoice;
use App\Modules\Parties\Models\Customer;
use App\Modules\Parties\Models\Supplier;
use App\Modules\Payments\Models\Refund;

/**
 * The actions the in-product assistant may PREPARE (ADR-0023, widened by ADR-0027). Each one is an
 * existing API route; the user confirms every one on a card before it runs, as themselves. A tool is
 * offered to a user only when they hold the permission of the route it calls.
 *
 * `marks` = the created record gets `created_via = assistant` (new records only).
 * `kind`  = `record` for an action on an existing record (id or number); absent for create actions.
 * `risk`  = create | approve | send | post | pay | void | delete | export (drives the card's warning).
 */
final class ActionCatalogue
{
    private const STR = ['type' => 'string'];

    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        $str = fn (int $max) => ['type' => 'string', 'maxLength' => $max];
        $date = ['type' => 'string', 'format' => 'date'];
        $currency = ['type' => 'string', 'pattern' => '^[A-Za-z]{3}$'];

        return $this->base($str, $date, $currency) + $this->records() + $this->money($str, $date, $currency);
    }

    /** @return array<string, array<string, mixed>> */
    private function base(callable $str, array $date, array $currency): array
    {
        return [
            'erp_lead_create' => [
                'permission' => 'leads.create', 'method' => 'POST', 'route' => '/leads', 'entity' => 'lead', 'model' => Lead::class, 'marks' => true,
                'description' => 'Prepare a NEW lead (potential customer) for the user to confirm. Nothing is saved until the user presses Confirm.',
                'schema' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['name'], 'properties' => [
                    'name' => $str(255), 'phone' => $str(30), 'email' => $str(255), 'source' => $str(40), 'notes' => $str(2000), 'service_category' => $str(40),
                ]],
            ],
            'erp_lead_update' => [
                'permission' => 'leads.update', 'method' => 'PATCH', 'route' => '/leads/{id}', 'entity' => 'lead', 'model' => Lead::class, 'marks' => false,
                'description' => 'Prepare a change to an EXISTING lead: its stage and/or a note to add. The user must confirm. `id` is the lead public id from a read tool.',
                'schema' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['id'], 'properties' => [
                    'id' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9_-]{8,64}$'], 'stage' => $str(40), 'note' => $str(2000),
                ]],
            ],
            'erp_customer_create' => [
                'permission' => 'customers.create', 'method' => 'POST', 'route' => '/customers', 'entity' => 'customer', 'model' => Customer::class, 'marks' => true,
                'description' => 'Prepare a NEW customer for the user to confirm. Both Arabic and English names are required: ask the user if one is missing.',
                'schema' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['name_ar', 'name_en'], 'properties' => [
                    'type' => ['type' => 'string', 'enum' => ['individual', 'company']], 'name_ar' => $str(255), 'name_en' => $str(255),
                    'phone' => $str(30), 'email' => $str(255), 'tax_id' => $str(50), 'currency' => $currency,
                ]],
            ],
            'erp_supplier_create' => [
                'permission' => 'suppliers.create', 'method' => 'POST', 'route' => '/suppliers', 'entity' => 'supplier', 'model' => Supplier::class, 'marks' => true,
                'description' => 'Prepare a NEW supplier for the user to confirm. Both Arabic and English names are required: ask the user if one is missing.',
                'schema' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['name_ar', 'name_en'], 'properties' => [
                    'category' => $str(30), 'name_ar' => $str(255), 'name_en' => $str(255), 'phone' => $str(30), 'email' => $str(255), 'settlement_currency' => $currency,
                ]],
            ],
            'erp_invoice_draft_create' => [
                'permission' => 'invoices.create', 'method' => 'POST', 'route' => '/invoices', 'entity' => 'invoice', 'model' => Invoice::class, 'marks' => true,
                'description' => 'Prepare a DRAFT customer invoice. It stays a draft: it is not issued or posted. `customer_id` is the customer public id from a read tool. Amounts are integer minor units (cents/piasters).',
                'schema' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['customer_id', 'issue_date', 'lines'], 'properties' => [
                    'customer_id' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9_-]{8,64}$'], 'booking_id' => $str(30), 'issue_date' => $date, 'due_date' => $date,
                    'currency' => $currency, 'memo' => $str(255),
                    'lines' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 30, 'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['description', 'quantity', 'unit_price_minor'], 'properties' => [
                        'description' => $str(255), 'quantity' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100000], 'unit_price_minor' => ['type' => 'integer', 'minimum' => 1],
                    ]]],
                ]],
            ],
            'erp_expense_draft_create' => [
                'permission' => 'expenses.create', 'method' => 'POST', 'route' => '/expenses', 'entity' => 'expense', 'model' => Expense::class, 'marks' => true,
                'description' => 'Prepare a DRAFT expense. It stays a draft until a person submits and approves it. `category` is the expense category code or name. Give EITHER `payment_account` (name of the cash/bank account that pays) OR `supplier_id` (supplier public id, to book it on account). Amount in integer minor units.',
                'schema' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['category', 'expense_date', 'amount_minor', 'description'], 'properties' => [
                    'category' => $str(255), 'expense_date' => $date, 'amount_minor' => ['type' => 'integer', 'minimum' => 1], 'currency' => $currency,
                    'description' => $str(255), 'reference' => $str(255), 'payee_name' => $str(255),
                    'payment_account' => $str(255), 'supplier_id' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9_-]{8,64}$'],
                ]],
            ],
            'erp_booking_draft_create' => [
                'permission' => 'bookings.create', 'method' => 'POST', 'route' => '/bookings', 'entity' => 'booking', 'model' => Booking::class, 'marks' => true,
                'description' => 'Prepare a DRAFT booking (status quoted) with its items. `customer_id` is the customer public id from a read tool. Amounts are integer minor units.',
                'schema' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['customer_id', 'start_date', 'currency', 'items'], 'properties' => [
                    'customer_id' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9_-]{8,64}$'], 'start_date' => $date, 'end_date' => $date,
                    'pax_adults' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 500], 'pax_children' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 500],
                    'currency' => $currency, 'notes' => $str(1000),
                    'items' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 30, 'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['service_type', 'description', 'quantity', 'unit_cost_minor', 'unit_sell_minor'], 'properties' => [
                        'service_type' => ['type' => 'string', 'enum' => ['flight', 'hotel', 'transport', 'visa', 'program', 'other']], 'description' => $str(255),
                        'quantity' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100000], 'unit_cost_minor' => ['type' => 'integer', 'minimum' => 0],
                        'unit_sell_minor' => ['type' => 'integer', 'minimum' => 0], 'supplier_id' => $str(30),
                    ]]],
                ]],
            ],
        ];
    }

    /**
     * Actions on an existing record, found by public id or by its number (INV-..., EX-...).
     *
     * @return array<string, array<string, mixed>>
     */
    private function records(): array
    {
        $schema = ['type' => 'object', 'additionalProperties' => false, 'properties' => [
            'id' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9_-]{8,64}$'],
            'number' => ['type' => 'string', 'maxLength' => 40],
        ]];
        $rec = fn (string $permission, string $method, string $route, string $entity, string $model, string $risk, string $description, array $extra = [], array $required = []) => [
            'permission' => $permission, 'method' => $method, 'route' => $route, 'entity' => $entity, 'model' => $model, 'marks' => false,
            'kind' => 'record', 'risk' => $risk,
            'description' => $description.' The user must confirm; nothing runs before. Give `id` (public id from a read tool) or `number` (the document number the user mentioned).',
            'schema' => ['properties' => $schema['properties'] + $extra, 'required' => $required] + $schema,
        ];

        return [
            'erp_invoice_submit' => $rec('invoices.create', 'POST', '/invoices/{id}/submit', 'invoice', Invoice::class, 'send', 'Prepare SENDING a draft invoice for approval.'),
            'erp_invoice_approve' => $rec('invoices.approve', 'POST', '/invoices/{id}/approve', 'invoice', Invoice::class, 'approve', 'Prepare APPROVING a submitted invoice.'),
            'erp_invoice_issue' => $rec('invoices.approve', 'POST', '/invoices/{id}/issue', 'invoice', Invoice::class, 'post', 'Prepare ISSUING an approved invoice (this posts it to the ledger).'),
            'erp_invoice_void' => $rec('invoices.create', 'POST', '/invoices/{id}/void', 'invoice', Invoice::class, 'void', 'Prepare VOIDING an invoice.'),
            'erp_expense_submit' => $rec('expenses.create', 'POST', '/expenses/{id}/submit', 'expense', Expense::class, 'send', 'Prepare SENDING a draft expense for approval.'),
            'erp_expense_approve' => $rec('expenses.approve', 'POST', '/expenses/{id}/approve', 'expense', Expense::class, 'approve', 'Prepare APPROVING a submitted expense.'),
            'erp_document_submit' => $rec('documents.create', 'POST', '/accounting-documents/{id}/submit', 'document', AccountingDocument::class, 'send', 'Prepare SENDING a draft voucher for approval.'),
            'erp_document_approve' => $rec('documents.approve', 'POST', '/accounting-documents/{id}/approve', 'document', AccountingDocument::class, 'approve', 'Prepare APPROVING a submitted voucher.'),
            'erp_document_post' => $rec('documents.approve', 'POST', '/accounting-documents/{id}/post', 'document', AccountingDocument::class, 'post', 'Prepare POSTING an approved voucher to the ledger.'),
            'erp_commission_approve' => $rec('commissions.approve', 'POST', '/commissions/{id}/approve', 'commission', Commission::class, 'approve', 'Prepare APPROVING a commission.'),
            'erp_commission_post' => $rec('commissions.post', 'POST', '/commissions/{id}/post', 'commission', Commission::class, 'post', 'Prepare POSTING an approved commission to the ledger.'),
            'erp_commission_pay' => $rec('commissions.pay', 'POST', '/commissions/{id}/pay', 'commission', Commission::class, 'pay', 'Prepare PAYING a posted commission out of a cash or bank account. `payment_account` is the account name.', [
                'payment_account' => ['type' => 'string', 'maxLength' => 255], 'reference' => ['type' => 'string', 'maxLength' => 255],
            ], ['payment_account']),
            'erp_refund_approve' => $rec('refunds.approve', 'POST', '/refunds/{id}/approve', 'refund', Refund::class, 'approve', 'Prepare APPROVING a refund request.'),
            'erp_journal_post' => $rec('journal.create', 'POST', '/journal-entries/{id}/post', 'journal_entry', JournalEntry::class, 'post', 'Prepare POSTING a draft journal entry (it can only be reversed afterwards).'),
            'erp_journal_draft_delete' => $rec('journal.delete_drafts', 'DELETE', '/journal-entries/{id}', 'journal_entry', JournalEntry::class, 'delete', 'Prepare DELETING a draft journal entry for good.'),
        ];
    }

    /**
     * Actions that move money out or in, or queue an export. They create something, so they take
     * their own arguments (accounts are named, never guessed).
     *
     * @return array<string, array<string, mixed>>
     */
    private function money(callable $str, array $date, array $currency): array
    {
        $publicId = ['type' => 'string', 'pattern' => '^[A-Za-z0-9_-]{8,64}$'];

        return [
            'erp_payment_record' => [
                'permission' => 'payments.create', 'method' => 'POST', 'route' => '/payments', 'entity' => 'payment', 'model' => null, 'marks' => false, 'risk' => 'pay',
                'description' => 'Prepare RECORDING a payment received from a customer into a cash or bank account. `customer_id` is the customer public id from a read tool, `payment_account` the account name. Amount in integer minor units. It is posted to the ledger once confirmed.',
                'schema' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['customer_id', 'payment_account', 'amount_minor'], 'properties' => [
                    'customer_id' => $publicId, 'payment_account' => $str(255), 'amount_minor' => ['type' => 'integer', 'minimum' => 1], 'currency' => $currency,
                    'booking_id' => $str(30), 'paid_at' => $date, 'method' => ['type' => 'string', 'enum' => ['cash', 'bank_transfer', 'card', 'wallet']],
                ]],
            ],
            'erp_supplier_payment_record' => [
                'permission' => 'supplier_payments.create', 'method' => 'POST', 'route' => '/supplier-payments', 'entity' => 'supplier_payment', 'model' => null, 'marks' => false, 'risk' => 'pay',
                'description' => 'Prepare PAYING a supplier out of a cash or bank account. `supplier_id` is the supplier public id from a read tool, `payment_account` the account name. Amount in integer minor units. It is posted to the ledger once confirmed.',
                'schema' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['supplier_id', 'payment_account', 'amount_minor'], 'properties' => [
                    'supplier_id' => $publicId, 'payment_account' => $str(255), 'amount_minor' => ['type' => 'integer', 'minimum' => 1], 'currency' => $currency, 'paid_at' => $date,
                ]],
            ],
            'erp_export_queue' => [
                'permission' => 'reports.export', 'method' => 'POST', 'route' => '/exports', 'entity' => 'export', 'model' => null, 'marks' => false, 'risk' => 'export',
                'description' => 'Prepare EXPORTING a report to a file (runs in the background and appears in the export list). `params` are the report filters such as from, to, as_of.',
                'schema' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['report', 'format'], 'properties' => [
                    'report' => ['type' => 'string', 'enum' => config('exports.reports', [])], 'format' => ['type' => 'string', 'enum' => ['csv', 'xlsx', 'pdf']],
                    'language' => ['type' => 'string', 'enum' => ['ar', 'en', 'bilingual']],
                    'params' => ['type' => 'object', 'additionalProperties' => ['type' => 'string', 'maxLength' => 200]],
                ]],
            ],
        ];
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->all());
    }

    /** @return array<string, mixed>|null */
    public function find(string $name): ?array
    {
        return $this->all()[$name] ?? null;
    }

    /** OpenAI-style definitions of the actions this user may prepare. @return list<array<string, mixed>> */
    public function modelTools(User $user): array
    {
        $tools = [];
        foreach ($this->all() as $name => $tool) {
            if ($user->can($tool['permission'])) {
                $tools[] = ['type' => 'function', 'function' => ['name' => $name, 'description' => $tool['description'], 'parameters' => $tool['schema']]];
            }
        }

        return $tools;
    }
}
