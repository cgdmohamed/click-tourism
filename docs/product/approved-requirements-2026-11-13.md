# Approved business requirements (2026-11-13) - implementation status

Source: the owner's "Approved Business Requirements" of 2026-11-13. This page records what is built, how it was verified and what is still open. It does not replace `decisions-closed-2026-11-11.md`.

| Requirement | Status | What exists | Open |
|---|---|---|---|
| DEC-002 Supplier costs | Built (PR 1) | Booking view shows estimated cost, recognized cost, amount still expected, projected profit and recognized profit (`cost_summary`, read from the same posted lines as the profitability report). Supplier invoice lines can name the booking service they pay for. Cost is recognized when the supplier invoice is issued; drafts post nothing; a supplier payment settles the payable and never posts the expense. Period-end accrual (`POST /fiscal-periods/{id}/cost-accrual`, Dr cost / Cr accrued supplier costs 2170) reverses itself when the next period opens, so the later invoice is not counted twice. The ledger supplier filter finds cost lines (GAP-RPT-05). | Accrued-costs account 2170 must exist (`accounting:ensure-accounts --apply` on an existing installation). Entries posted before this change keep their old supplier attribution. |
| DEC-004 Commissions | Built | Accrual only at 100% collection, once per booking and beneficiary, approval and posting as before. Statements and the management report now show expected (not payable), payable, paid and adjusted separately. A refund or cancellation reverses the commission and keeps the history. | Expected is computed for the newest 500 open bookings per request. |
| DEC-008 FX differences | Built behind the existing switches | Realized difference at settlement (existing). Period close: `GET/POST /fiscal-periods/{id}/revaluation` and a review dialog on Periods show the estimated unrealized gain and loss before confirming; confirming twice posts once; auto-reversal into 7901/6901. | **Business setup required:** approve the source of exchange rates and the closing-date rate policy before enabling `accounting.fx_revaluation_enabled` and `accounting.accounting_policy_approved` in production. Not decided here. |
| DEC-014 Branches | Built | A supplier payment from another branch's registered cash or bank account is refused with an explanation that points to a transfer. Obligations of another branch were already refused. Transfers between branches stay a separate workflow. | |
| DEC-028 Commercial invoices | Built | Customer invoices are titled "Invoice" / "فاتورة"; the draft note no longer mentions tax-document validity; single tax setting unchanged. | Compliance note in the requirement stands: this is a product-scope decision, not legal advice. |
| DEC-025 Internal reports | Built | Existing reports unchanged; every report PDF states it is an internal management report; commissions report separates expected and adjusted. | |
| GAP-CORE-03 Attachments and AI extraction | **Not started** (PR 2) | | Everything. |

## Verified by tests

`SupplierCostRecognitionTest` (estimate vs recognized, drafts, payment, accrual and reversal, no double count, ledger supplier filter), `FxRevaluationTest` (review and confirm endpoints), `SupplierSettlementTest` (cross-branch payment), `CommissionTest` (expected/payable/adjusted), `InvoiceDocumentTest` (titles), `OpenApiCoverageTest`.

## Not verified

End-to-end journeys 1-7 of the requirement in a browser against a real server; the accrual and revaluation dialogs in Playwright/axe (they use the shared modal and table components); behaviour on a long-lived database with entries posted before this change.
