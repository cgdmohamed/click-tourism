# Decisions Needed — Tourism ERP

Status: **OPEN** unless marked. Every decision here blocks or constrains implementation. `ACC` = **HUMAN/ACCOUNTANT APPROVAL REQUIRED**; `HUM` = product owner / human approval required; `ARC` = architect.

No decision may be silently assumed in code. When implementation starts, unresolved decisions must be represented by an explicit setting or a guarded default documented in the ADR.

| ID | Type | Decision | Options | Recommended | Blocks |
|---|---|---|---|---|---|
| DEC-001 | ACC | **Revenue recognition point** for bookings | A) on confirmation; B) on service start; C) on service completion; D) on invoice | **C** for services (with unearned revenue at confirmation); confirm with accountant | FIN-009, BOOK, RPT |
| DEC-002 | ACC | **Supplier cost recognition** | A) accrue at booking confirmation; B) recognize on supplier invoice; C) on payment | **A** (accrual), invoice matches obligation | FIN-009, PAY, RPT |
| DEC-003 | ACC | **Commission accounting treatment** | A) expense + payable; B) contra-revenue; C) reduce supplier cost | **A** | COMM, RPT |
| DEC-004 | ACC | **Commission accrual trigger** | A) booking confirmed; B) fully collected; C) service completed; D) configurable per rule | **D**, default **B** | COMM |
| DEC-005 | ACC | **Customer advances** | A) liability account until applied; B) offset AR immediately | **A** | PAY, RPT |
| DEC-006 | ACC | **Cancellation fee treatment** | A) revenue when non-refundable; B) always liability until earned; C) net against cost | **A** with policy flag | BOOK, PAY, RPT |
| DEC-007 | ACC | **FX policy** | Rate source (manual only vs manual + reference), rate date (document vs receipt), refund rate, realized/unrealized gain-loss accounts | Manual rate + snapshot at document; receipt rate for settlement; refund at original allocation rate | **confirm each sub-policy** | FIN, PAY, RPT |
| DEC-008 | ACC | **Period-end FX revaluation** of open AR/AP | A) none (realized only); B) revalue and auto-reverse; C) revalue without reversal | **A for MVP**, design for B | FIN-010, RPT |
| DEC-009 | ACC | **Tax point and inclusive/exclusive defaults** | Invoice date vs receipt vs service date; prices tax-inclusive vs exclusive by default; treatment on cancellation | Per-profile setting; tax point = invoice/service per jurisdiction; confirm | TAX, BOOK, RPT |
| DEC-010 | ACC | **Rounding and residual account** | Line-level half-up + rounding line vs document-level; account to absorb residual | Line-level half-up + rounding account | FIN, PAY, TAX |
| DEC-011 | ACC | **Opening balances method and cutover date** | A) single dated journal batch; B) per-account import; C) subledger-by-subledger | **A**, with AR/AP detail required | FIN-005, DATA |
| DEC-012 | ACC | **Fiscal year start and closing procedure** | Calendar vs custom year; soft-close behavior; retained earnings; reopen authority | Custom year configurable; close rolls P&L to retained earnings; reopen by chief accountant | FIN-002, FIN-010 |
| DEC-013 | ACC/HUM | **Commission rule precedence** | Specificity order, effective-date tie-break, stacking vs exclusive | Exclusive, most-specific-then-latest wins | COMM |
| DEC-014 | ACC | **Branch accounting model** | A) branch as dimension on one ledger; B) separate books per branch; C) separate legal entities | **A for MVP**; inter-branch via due-to/due-from | FIN, RPT, AUTH |
| DEC-015 | HUM | **24h reassignment definition** | Inactivity clock start, meaningful activity set, business hours/timezone, max attempts, what happens when no owner | Clock from last meaningful activity; business-calendar aware; 24h configurable; escalate to queue after N attempts | CRM-005 |
| DEC-016 | HUM | **Lead assignment strategy** | Round-robin, load-based, source/branch rules, manual override, queue | Configurable rule list with deterministic precedence | CRM-004 |
| DEC-017 | ACC/HUM | **Approval thresholds and segregation of duties** | Discount, refund, expense, commission payout, journal reversal thresholds; creator ≠ approver | Configurable; segregation on by default for refunds/payouts | FIN, PAY, COMM, AUTH |
| DEC-018 | ARC/HUM | **Document numbering** | Per branch/year reset, prefixes, gaps allowed? | Per branch + fiscal year, gapless within type, configurable prefixes | CORE-007 |
| DEC-019 | ACC | **Deletion / amendment policy** | Master data deactivation, document void vs reversal, correction of posted entries | Deactivate masters; never delete posted documents; reversal only | ALL |
| DEC-020 | HUM/ARC | **Multi-company support** | A) one legal entity per installation (MVP); B) multi-company later | **A** | architecture |
| DEC-021 | ACC | **Tax on commissions / withholding** | Whether agent commissions are taxed/withheld; reporting | Out of MVP unless accountant requires | COMM, TAX |
| DEC-022 | HUM | **Customer/supplier portal** | Needed in MVP? | **No** | scope |
| DEC-023 | HUM | **Payment methods and cash reconciliation** | Methods list; cash drawer counting; who reconciles | Configurable methods; manual reconciliation notes in MVP | PAY |
| DEC-024 | HUM | **Data retention and PII** | Retention for leads/WhatsApp/audit; redaction; export on request | Financial audit ≥7y; leads/WhatsApp per customer policy; redact secrets | CORE-006, CRM, INT |
| DEC-025 | ACC | **Negative-margin and zero-value bookings** | Allowed? Approval? | Allowed with warning + permission | BOOK, COMM |
| DEC-026 | HUM | **Notification channels** | In-app, email, WhatsApp for assignment/approval | In-app + email MVP; WhatsApp optional | CORE, CRM |
| DEC-027 | ARC | **Idempotency scope** | Header key per endpoint/user; webhook provider event ID; retention window | Key + request hash, 24h retention; webhooks permanent dedupe | ALL |
| DEC-028 | ACC | **Booking invoice document** | Is a tax invoice required separate from booking confirmation? | Confirm with accountant; template designed to serve both | BOOK, RPT, TAX |
| DEC-029 | HUM | **Language of legal documents** | Arabic only, English only, or bilingual PDFs | Bilingual, configurable per template | RPT, UI |
| DEC-030 | HUM | **Reference data seeding** | Which CoA/tax/commission defaults ship per country | Ship neutral template + country preset packs (Egypt/GCC) | FOUNDATION |
| DEC-031 | ACC | **Cash and transfer policy** | Block negative cash? In-transit account for transfers? FX treatment of cross-currency transfers (gain/loss account vs. clearing) | Allow negative (setting `treasury.block_negative_cash`); no in-transit; difference to FX gain/loss | TE-018, PAY, RPT |
| DEC-032 | ACC | **Expense approval and input tax** | Approval thresholds per amount/role; maker-checker; input-VAT account and recoverability | Maker ≠ checker on (setting `expenses.segregation`); no thresholds; input VAT to `accounting.input_tax_account_code` (1160) | TE-019, TAX, RPT |
| DEC-033 | ACC | **Opening balances cutover** | One batch per branch vs. many; AR/AP detail mandatory; correction by reversal only | One posted batch per branch and date; AR/AP lines must carry a party; reversal then new batch | TE-017, DATA |
| DEC-034 | ACC | **Year-end close and reopening** | Closing entry per branch vs. consolidated; retained-earnings account; who may reopen; reversal of closing entries on reopen | Per branch, account 3100, `periods.reopen`, closing entries reversed on reopen | TE-021, FIN, RPT |
| DEC-035 | ACC | **Employee advances & custody control accounts** | Which GL accounts carry employee advances and custody vs. a single employee control; dedicated custody account vs. shared prepaid/advance account; is the employee subledger reconciled to a control account per branch | Settings `accounting.employee_advance_account_code` (1150 prepaid expenses) / `accounting.employee_custody_account_code` (1150 shared until a dedicated account is approved); subledger reconciles to these controls — **provisional, awaiting accountant sign-off** | TE-021, PARTIES, RPT |
| DEC-036 | ACC | **Voucher tax treatment and correction policy** | Do vouchers auto-post tax lines from line tax breakdown (input/output split, inclusive vs. exclusive) or is tax recorded as document metadata only? Can cancelled approved-but-unposted documents be reopened? Which transfers require an in-transit account? | Tax recorded as document metadata only (no auto-posting) until decided; corrections via linked reversal document that goes through approval; no reopen of cancelled documents; no in-transit account (single balanced transfer voucher) — **awaiting accountant sign-off** | TE-022, TAX, FIN |

## Decision workflow

1. Owner reviews options and records the decision + rationale + date in this file (status → `DECIDED`).
2. `ACC` decisions additionally require the accountant's name and approval reference.
3. Architect converts decided items into ADRs/settings and updates `docs/architecture/` contracts.
4. Implementation may proceed on non-blocked slices only. A blocked slice must use a clearly labeled stub + feature flag, never a guessed business rule.

## Default behavior when undecided

If a decision is still open at implementation time, the system must:
- store the choice as an installation setting with the recommended default;
- surface the assumption in the UI (e.g., "recognition: completion — configure");
- emit a startup/health warning listing unresolved decisions;
- never hardcode a jurisdiction or client-specific rule.
