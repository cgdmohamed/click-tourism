# ADR-0001 — Modular monolith and module boundaries

- Status: Proposed
- Date: 2026-11-09
- Deciders: Architect, backend lead

## Context

The product is one deployable application per customer, with a small team and a broad domain (accounting, bookings, payments, CRM). Microservices would add operational cost with no scale justification. A single unstructured Laravel app would let modules couple through models and tables, making accounting correctness and testing fragile.

## Decision

Build a **modular monolith** with explicit module namespaces under `backend/app/Modules/<Module>`:

`Core`, `Money`, `Accounting`, `Parties`, `Bookings`, `Payments`, `Tax`, `Commissions`, `Reporting`, `CRM`, `Integrations`, `DataTransfer`.

Rules:
1. A module owns its models, migrations, policies, services, controllers, jobs, and tests.
2. Cross-module calls go through a public **application service** or a **domain event**. Reading another module's Eloquent model is forbidden (enforced by review + static check).
3. Shared primitives live in `app/Support` (money, ids, pagination, responses) and require architect approval to change.
4. Accounting does not depend on commercial modules. It exposes a `PostingService` contract and consumes events; commercial modules call that contract.
5. Reporting uses query services/read models, not other modules' models.
6. Integrations depend on CRM contracts, never on ledger internals.

## Consequences

- Positive: testable ledger in isolation; clear ownership; one deployment.
- Negative: more boilerplate for contracts; risk of "shared kernel" creep. Mitigated by small `Support` surface and ADR review.
- Rejected: microservices (operational cost), single flat app (coupling), package-per-module Composer repos (overhead for a small team).
