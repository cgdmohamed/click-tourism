# ADR-0015 — Party subledger accounts

- **Status:** Accepted (project owner decision, 2026-11-10); accountant validation pending.
- **Context:** Receivables and payables were posted three different ways (control account with a party tag, per-party subaccount for invoices, untagged control for supplier cost). Control-account totals, party statements and invoice open balances could not be reconciled.
- **Decision:** Every customer has one receivable subaccount under the receivables control account and every supplier one payable subaccount under the payables control account. They are created on first use by `PartyLedgerService`, are currency-agnostic (the journal line carries the currency), and are the only accounts receivable/payable movements post to:
  - booking revenue, cancellation fees, receipts allocated to bookings, refunds → customer receivable subaccount
  - booking cost → one credit per supplier on the supplier payable subaccount (items without a supplier stay on the control account)
  - supplier payments, applied advances, expenses booked on account → supplier payable subaccount
  - invoices and credit notes (unchanged) → the same subaccounts
- **Advances** stay on the customer-advance (2300) and supplier-advance (1150) control accounts, tagged with the party, until applied.
- **Invoice settlement:** `invoice_applications` moves part of a receipt/advance to the party subaccount and reduces `invoices.outstanding_minor`. A credit note reduces the original invoice's outstanding on issue. A refund reduces the receipt's booking allocation or unallocated advance.
- **Consequences:** the control account is the sum of its subaccounts; reports already roll descendants up. Postings made before this decision stay on the control account with their party tag; party statements read both. Opening balances may still target the control account with a party.
- **Open points for the accountant:** whether supplier obligations should also post (today booking cost credits AP, so an obligation is a tracking document); realized FX on settlement; unapplying an invoice application.
