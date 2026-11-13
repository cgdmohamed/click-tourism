<?php

namespace App\Modules\Assistant\Actions;

use App\Modules\Bookings\Models\Booking;
use App\Modules\Core\Models\User;
use App\Modules\CRM\Models\Lead;
use App\Modules\Expenses\Models\Expense;
use App\Modules\Invoices\Models\Invoice;
use App\Modules\Parties\Models\Customer;
use App\Modules\Parties\Models\Supplier;

/**
 * The actions the in-product assistant may PREPARE (ADR-0023). Each one is an existing API route
 * that creates a draft or a master record; nothing here can post, approve, pay, send, delete or
 * export. A tool is offered to a user only when they hold the permission of the route it calls.
 *
 * `marks` = the created record gets `created_via = assistant` (new records only).
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
