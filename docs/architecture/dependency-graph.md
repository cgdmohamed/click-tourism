# Dependency Graph — Tourism ERP

Status: Draft. Arrows mean "depends on / calls". Cycles are forbidden.

```mermaid
graph TD
  SUPPORT[Support: Money, IDs, Responses]
  CORE[Core: auth, users, branches, settings, audit, sequences]
  MONEY[Money: currencies, FX]
  ACCT[Accounting: CoA, periods, journal, posting]
  PARTY[Parties: customers, suppliers, payees]
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
```

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
