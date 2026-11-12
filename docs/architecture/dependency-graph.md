# Dependency Graph — Tourism ERP

Status: Draft. Arrows mean "depends on / calls". Cycles are forbidden.

```mermaid
graph TD
  SUPPORT[Support: Money, IDs, Responses]
  CORE[Core: auth, users, branches, settings, audit, sequences]
  MONEY[Money: currencies, FX]
  ACCT[Accounting: CoA, periods, journal, posting]
  PARTY[Parties: customers, suppliers, payees]
  INVOICE[Invoices: AR/AP, credit notes, documents]
  TAX[Tax]
  BOOK[Bookings]
  PAY[Payments]
  COMM[Commissions]
  RPT[Reporting]
  CRM[CRM]
  INT[Integrations]
  DATA[DataTransfer]
  UI[Frontend SPA]

  CORE --> SUPPORT
  MONEY --> SUPPORT
  MONEY --> CORE
  ACCT --> MONEY
  ACCT --> CORE
  PARTY --> CORE
  TAX --> MONEY
  TAX --> ACCT
  BOOK --> PARTY
  BOOK --> MONEY
  BOOK --> TAX
  BOOK --> ACCT
  INVOICE --> CORE
  INVOICE --> MONEY
  INVOICE --> ACCT
  INVOICE --> PARTY
  INVOICE --> BOOK
  INVOICE --> TAX
  INVOICE --> PAY
  PAY --> BOOK
  PAY --> PARTY
  PAY --> ACCT
  COMM --> BOOK
  COMM --> PARTY
  COMM --> ACCT
  RPT --> ACCT
  RPT --> BOOK
  RPT --> PAY
  RPT --> COMM
  CRM --> CORE
  CRM --> PARTY
  CRM --> BOOK
  INT --> CRM
  INT --> CORE
  DATA --> CORE
  DATA --> PARTY
  DATA --> ACCT
  DATA --> CRM
  UI --> CORE
  UI --> ACCT
  UI --> PARTY
  UI --> BOOK
  UI --> PAY
  UI --> RPT
  UI --> CRM
  UI --> INVOICE
```

## Known coupling (not drawn as cycles)

`Payments` and `Reports` reference `Invoices` Eloquent models directly
(`InvoiceApplication` relations, invoice statements and aging). Those inbound
model references are deliberately not drawn as `PAY --> INVOICE` /
`RPT --> INVOICE` edges because that would form a cycle; moving them behind a
query service/contract is architect-approved follow-up work (rules 4 and 7).

## Rules

1. `Accounting` must **not** import `Bookings`, `Payments`, `Commissions`, or `CRM`. It exposes `PostingService` and consumes events.
2. `Bookings` must not write journals directly; it calls the posting contract.
3. `CRM` must not write booking tables; conversion calls the Bookings service.
4. `Reporting` must not use another module's Eloquent models; it uses query services/read models.
5. `Integrations` must not read the ledger.
6. `Support` must not depend on any module.
7. New edges require architect approval and an ADR update.

## Enforcement

- Review checklist per PR.
- Static check (CI) that bans cross-module namespace imports outside allowed edges.
- Contract tests for public services/events.
