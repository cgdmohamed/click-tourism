# Threat Model — Tourism ERP

Status: Draft. Scope: self-hosted single-tenant installation. Method: asset inventory + STRIDE + abuse cases.

## 1. Assets

| Asset | Impact if compromised |
|---|---|
| Financial ledger (journals, balances) | Fraud, false reporting, legal exposure. |
| Customer PII (names, phones, emails, travel) | Privacy breach, regulatory exposure. |
| Supplier and commission data | Commercial harm, disputes. |
| Credentials (Meta, WhatsApp, DB, SMTP) | Account takeover, messaging abuse. |
| Audit trail | Loss of accountability. |
| Backups | Full data compromise. |
| Availability of the installation | Business stoppage. |

## 2. Trust boundaries

- Browser/SPA ↔ reverse proxy (TLS) ↔ Laravel API.
- API ↔ PostgreSQL / Redis.
- API ↔ Meta / WhatsApp (outbound HTTPS + inbound signed webhooks).
- Operators ↔ server (SSH, deployment).
- Queue/scheduler ↔ external services.

## 3. STRIDE analysis

| Threat | Scenario | Mitigation |
|---|---|---|
| **Spoofing** | Stolen token; forged webhook | Sanctum tokens with expiry/revocation; webhook signature verification; rate limiting; optional 2FA later. |
| **Tampering** | Editing posted journal; changing amount after approval | Immutable posted rows; policy checks; audit; optimistic locking; DB constraints. |
| **Repudiation** | User denies creating/approving a refund | Append-only audit with actor, IP, request ID; approval records. |
| **Information disclosure** | Branch A reads branch B; PII in logs/exports | Branch scope server-side; redaction; export permissions; no secrets/PII in logs; encrypted secrets. |
| **Denial of service** | Login brute force; expensive report; webhook flood | Rate limiting, queue for heavy reports, request size limits, webhook dedupe. |
| **Elevation of privilege** | Missing policy on an endpoint; role escalation | Deny-by-default; route coverage test; role-change permission + audit; separation of duties; `accounts.post` required for all journal posting; `journal.reverse` required for reversals. |
| **SQL injection** | Unsafe query filters | Eloquent/query builder bindings; whitelisted sort/filter columns; validation. |
| **XSS** | Stored lead notes rendered in UI | Output encoding; CSP; sanitize rich text; React escaping. |
| **CSRF** | State-changing request from another origin | Token auth (no cookie-based session for API) + CORS allowlist; SameSite if cookies used. |
| **SSRF** | User-supplied webhook URL | No arbitrary URL fetching; allowlist providers. |
| **Insecure deserialization** | Malicious webhook JSON | Validate/schema-check; no PHP object unserialize. |
| **Supply chain** | Malicious Composer/npm package | Lockfiles, audit in CI, pinned versions, no auto-upgrade. |
| **Backup theft** | Unencrypted dump | Encrypted backups, restricted storage, restore access controlled. |
| **Insider fraud** | Accountant creates and approves own refund | Segregation of duties; approval thresholds; audit; reviewer role. |

## 4. Abuse cases to test

1. User with `payments.create` but not `refunds.approve` cannot self-approve a refund.
2. Branch-scoped user cannot export another branch's ledger by changing a filter.
3. Replaying a payment request cannot double-post.
4. Forged Meta webhook signature is rejected and logged.
5. Deactivated user's token stops working immediately.
6. Posted journal cannot be edited via mass assignment, direct route, or bulk action.
7. Export/download URLs are authorized and expire.
8. Secrets never appear in API responses, audit snapshots, or logs.
9. Actor who created a draft journal cannot post it (maker-checker).
10. Actor who posted a journal cannot reverse it (maker-checker).
11. Auto-posting is disabled until `accounting_policy_approved` is set to true.
12. System jobs (actorId=null) can post only when policy is approved; all system postings are audited.

## 5. Residual risks / decisions

- No 2FA in MVP → require strong passwords + rate limiting; plan TOTP.
- Single-server deployment → backup/RTO depends on customer infrastructure.
- Meta/WhatsApp credentials are customer-managed → compromise is outside product control; provide rotation guidance.
- No formal penetration test in MVP → schedule before first production if required.

## 6. AI assistant / MCP gateway (read-only gateway implemented, assistant not started)

Status: **stages 1 and 2 implemented** (ADR-0016): token endpoints (own and, with `mcp.admin`, all users'), the `mcp.enabled` kill switch (default off), `mcp.readonly` allowlist of 15 tool routes, per-token rate limit, report limiter, daily row budget, bulk-read flag, audit of every call, and a read-only gateway in `mcp-gateway/` with 15 tools, a second branch check and a circuit breaker. The in-product assistant (stage 3) is not started; write tools (stage 4) are BLOCKED. Full detail in `mcp-ai-assistant-blueprint.md`. No AI provider is integrated, no write tools exist, no transcripts are stored, contact details are masked by default (`mcp.mask_contacts`), and no keys are stored in the repository. Nothing is deployed unless a user creates a token and runs the gateway.

New trust boundary: assistant client <-> MCP gateway (sidecar, no DB access) <-> Laravel API using the **end user's own** read-only token (`mcp:read` ability, 8h expiry). Authorization stays in Laravel (permission middleware + `BranchScope`).

| Threat | Scenario | Mitigation |
|---|---|---|
| Indirect prompt injection | Lead notes, names, memos or bank memos contain instructions | Untrusted-field wrapping, no outbound tools in the same session, no write tools, tool calls after untrusted content need user confirmation |
| Data exfiltration | Model renders external links/images or chains to another MCP server that can send data | Same-origin links only, CSP, dedicated assistant profile, per-token row/rate budgets, contact masking by default |
| Privilege escalation / cross-branch | Tool arguments tampered, foreign branch id | Laravel authoritative; gateway drops foreign-branch rows; method fixed to GET |
| Token theft | MCP token leaked | Short expiry, read-only ability, revoke endpoints (self and `mcp.admin`), kill switch `mcp.enabled`, daily row budget, never logged |
| Provider data leakage | PII sent to cloud model | Customer-chosen provider (cloud or local), PII redaction on by default, provider allowlist |
| Repudiation | User denies a query | Every authenticated tool call audited by Laravel (`mcp.tool_called` / `mcp.tool_denied` with request id), no result bodies stored, the gateway has no audit write path |
| Bulk harvesting | Paging through customers, leads or reports | `per_page` <= 25, daily row budget per token (`429 mcp_budget_exceeded`), `mcp.bulk_read` flag, report limiter, contact and tax id masking, gateway circuit breaker |

Additional abuse cases to test (blueprint AT-1..AT-14):

13. An injected lead note cannot trigger extra tool calls or leak other customers.
14. An MCP token cannot call any non-GET route or any export route.
15. Cross-branch ids requested through the assistant return 403/404 and are audited.
16. A deactivated or revoked user's MCP token fails immediately.
17. A paged bulk read is flagged (`mcp.bulk_read`) and hits the daily row budget. (Implemented and tested in stage 2.)
18. With `mcp.enabled` off, no MCP token can be created and every MCP request is refused and audited.
19. A gateway pinned to a branch the token's user does not hold refuses every call, and fails closed when the branch list is unavailable.
20. A user without `mcp.admin` cannot list or revoke another user's MCP tokens; an administrator's revocation is audited with the owner.
