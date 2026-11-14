# API filters requested by the list redesign (2026-11-14)

The list pages now use one Filter drawer. These filters would let them offer more, but each needs a backend change, so
they are proposals for the owner (architect) to approve; none is implemented. All are additive query parameters
(no response or schema change), so they are backward compatible.

| Endpoint | Filters wanted | Why |
|---|---|---|
| `GET /payments` | `status`, `payment_method_id`, `unallocated=1` | Find advances and cancelled receipts; Payments only has customer and dates today. |
| `GET /customers` | `is_active`, `type` | Separate companies from individuals and hide inactive ones. |
| `GET /suppliers` | `category`, `is_active`, `settlement_currency` | Supplier lists have search only. |
| `GET /invoices` | `due_from`, `due_to`, `overdue=1` | The dashboard shows overdue receivables/payables but the list cannot filter by them. |
| `GET /tour-packages` | `category`, `execution_type`, `search`, `status` | |
| `GET /cash-accounts` | `kind` (cash or bank), `currency`, `search` | |
| `GET /transfers` | `cash_account_id`, `status` | |
| `GET /bank-statements` | `cash_account_id`, `period` | |
| `GET /depreciation-runs` | `status`, `period` | |
| `GET /accounts` | `is_active`, `is_postable`, `classification` | `type` exists but would orphan tree parents. |
| `GET /employees` | `category` | |
| `GET /fx-rates` | `from`, `to` (dates) | |
| `GET /supplier-payments` | `status` | |
| `GET /accounting-schedules` | `search` | |
| `GET /accounting-documents` | party filter (confirm whether `party_id` takes the public id) | Documents cannot be filtered by party yet. |

Priority suggestion: invoices due/overdue, payments status/method, customers/suppliers `is_active`, then the rest.
