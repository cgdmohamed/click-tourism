# Click Tourism ERP — Stabilization & Next-Phase Prompts

**Target:** `cgdmohamed/click-tourism` — one independent self-hosted installation per customer, one legal entity per installation.  
**Prepared:** 2026-11-10.  
**Purpose:** close the gaps identified during code review **before** starting MCP, AI Assistant, deeper multi-user workflows and UX expansion.  
**Important:** these are task instructions, not claims that code has been changed or tests executed. Each agent MUST re-audit the latest branch because the repo may have changed.

## Run order

| Order | Prompt | Dependency | Output |
|---|---|---|---|
| 01 | Repair CI and Frontend Quality Gate | none | green CI |
| 02 | Guard Unapproved Accounting Auto Posting | 01 | financial posting safety |
| 03 | Prevent Double Revenue Booking Invoice | 02 | unified economic-event recognition |
| 04 | Close AR AP Payment Allocation and Subledger Gaps | 03 | statement/invoice reconciliation |
| 05 | Authorization SoD and Financial Threat Model | 02–04 | service-level permissions |
| 06 | PostgreSQL Accounting Integration and Concurrency Tests | 01–05 | real DB validation |
| 07 | Cost Center Precision and Report Reconciliation | 04, 06 | exact financial reports |
| 08 | Finish CRM Assignment Rules UI | 01 | configuration from UI |
| 09 | Self Hosted Upgrade Backup Restore Release Gate | 06 | safe customer installation |
| 10 | Financial Documents Templates and Export Reliability | 01,04,07 | reliable exports |
| 11 | MCP Tooling and AI Assistant Security Blueprint | 01–07 | design only, no risky write tools |
| 12 | Multi User Collaboration and Frontend UX Roadmap | 01,05 | implementation backlog |

### Safe parallelism
- Run **01 alone first**.
- Run **02 → 03 → 04** sequentially because all touch financial recognition/ledger workflows.
- **05** can begin after posting contracts stabilize; **06** then validates cross-module behavior; **07** follows DB tests.
- **08** can run alongside **05–07**, on a separate worktree/branch.
- **09** and **10** after the relevant tests are green.
- **11** and **12** are *design-only* preparation tasks; they can run parallel when prerequisites are understood. Do not activate financially consequential AI actions.

## Recommended coordinator command

```text
You are the lead engineer for cgdmohamed/click-tourism. Read AGENTS.md, docs/product/decisions-needed.md, docs/product/decision-implementation-matrix.md, ADRs and OpenAPI. Use the numbered prompts in this folder as separate GitHub issues/PRs. Re-audit the current implementation first, then execute in the dependency order specified by README. Never modify main directly. Preserve production data and posted journals. Keep accountant-dependent policies pending, never silently authorize automated posting, and never combine PRs touching the same financial contracts. For each issue provide baseline, files changed, tests, CI URL, risks, and rollback instructions. Complete financial integrity tasks before coding MCP, AI Assistant, multi-user expansions or UX redesigns. The last two prompts are only architecture/backlog specifications.
```

## Main findings behind the tasks (read-only audit, recheck live)
- Latest observed GitHub Actions run on `main` failed frontend lint; backend job succeeded. The repo changes frequently: verify current status.
- `BookingService::postRevenue` and `InvoiceService::issue` both post entries for potentially the same economic event; requires explicit double-post regression test and reconciliation design.
- `SettingService` previously defaulted `accounting.auto_post_bookings=true` and `schedule_auto_post=true` despite unresolved accountant approval.
- Party statements and invoice open balances can diverge when receipts are not allocated; discrepancy was detected in current tests, but a complete allocation workflow is needed.
- `PostingService::assertActorMayPost` checked branch access; service-level permissions need a deeper audit.
- Cost-center report uses ratio/ROUND computations that deserve decimal-safe reconciliation tests.
- The repo has unit/feature tests, but test claims in README are not a substitute for a green PostgreSQL CI and live/UAT verification.

## Non-goals
- No SaaS or multi-tenancy.
- No new product-wide UI framework or unnecessary microservices.
- No automatic production deployment, no accounting decisions invented by an AI agent.
- No MCP/AI write access to accounts, money, bookings or people until domain authorization and human-approval controls pass security review.
