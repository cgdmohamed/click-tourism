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

Status: **stages 1 and 2 implemented** (ADR-0016): token endpoints (own and, with `mcp.admin`, all users'), the `mcp.enabled` kill switch (default off), `mcp.readonly` allowlist of 15 tool routes, per-token rate limit, report limiter, daily row budget, bulk-read flag, audit of every call, and a read-only gateway in `mcp-gateway/` with 15 tools, a second branch check and a circuit breaker. The in-product assistant (stage 3, ADR-0017) is implemented with OpenAI and is off by default; write tools (stage 4) are BLOCKED. Full detail in `mcp-ai-assistant-blueprint.md`. The gateway integrates no AI provider (the in-product assistant calls OpenAI from Laravel when an administrator enables it), no write tools exist, assistant chats are stored only as each user's own encrypted history (ADR-0022), contact details are masked by default (`mcp.mask_contacts`), and no keys are stored in the repository. Nothing is deployed unless a user creates a token and runs the gateway.

New trust boundary: assistant client <-> MCP gateway (sidecar, no DB access) <-> Laravel API using the **end user's own** read-only token (`mcp:read` ability, 8h expiry). Authorization stays in Laravel (permission middleware + `BranchScope`).

| Threat | Scenario | Mitigation |
|---|---|---|
| Indirect prompt injection | Lead notes, names, memos or bank memos contain instructions | Untrusted-field wrapping, no outbound tools in the same session, no write tools, tool calls after untrusted content need user confirmation |
| Data exfiltration | Model renders external links/images or chains to another MCP server that can send data | Same-origin links only, CSP, dedicated assistant profile, per-token row/rate budgets, contact masking by default |
| Privilege escalation / cross-branch | Tool arguments tampered, foreign branch id | Laravel authoritative; gateway drops foreign-branch rows; method fixed to GET |
| Token theft | MCP token leaked | Short expiry, read-only ability, revoke endpoints (self and `mcp.admin`), kill switch `mcp.enabled`, daily row budget, never logged |
| Provider data leakage | PII sent to cloud model | Assistant: provider named by a setting (`none` default), key env-only, `assistant.redact_pii` on by default (user text placeholders, structural masking of tool results, AT-12 test on the outbound request body), base URL env-only (not a database setting), data-processing notice in the panel; residual risk: business data and names still go to OpenAI (ADR-0017) |
| Repudiation | User denies a query | Every authenticated tool call audited by Laravel (`mcp.tool_called` / `mcp.tool_denied` with request id), no result bodies stored, the gateway has no audit write path |
| Bulk harvesting | Paging through customers, leads or reports | `per_page` <= 25, daily row budget per token (`429 mcp_budget_exceeded`), `mcp.bulk_read` flag, report limiter, contact and tax id masking, gateway circuit breaker |

Assistant-specific rows (ADR-0017):

| Threat | Scenario | Mitigation |
|---|---|---|
| Tool smuggling | Model names an unknown tool, an export route, another branch or a body parameter | Tool results `unknown_tool` / `validation_failed`; catalogue = the 15 read tools; `additionalProperties:false`; branch fixed by the session; sub-request built from validated arguments only (GET, no body) |
| Privilege escalation through the model | A user without `invoices.view` asks for invoices | Tool runs as the same user through the route middleware: `forbidden` tool result, no data |
| Rendered exfiltration | Answer contains an image, link or HTML that carries data | Output reduced to plain text; links come only from server-built citations; web renders text, internal `Link`s only |
| Denial of wallet | Loop of questions or long tool chains | Per-user rate limit, daily token budget, tool-call cap per turn, max output tokens, report limiter, row budget |
| Remote gateway exposure | The HTTP gateway (ADR-0024) is reachable from the internet | Accepts only requests with a valid MCP token (validated by the ERP on every call), POST `/mcp` only, browser Origin refused unless allowed, 1 MB body cap, no secrets or database access in the gateway, TLS at the proxy, token limited by owner permissions, read-only scope, expiry, revocation and `mcp.enabled`; residual risk: a leaked token until expiry or revocation |
| Injected action | Text inside a lead note or customer name makes the model prepare an action (ADR-0023) | Nothing runs without the user pressing Confirm on a card that shows the exact payload; the system prompt forbids acting on `<untrusted_data>`; the model cannot confirm; actions run as the user with their permissions; drafts only for invoices, expenses and bookings; branch, draft state, invoice type and revenue account are fixed by the server; every proposal, execution, failure and rejection is audited; 15-minute expiry; exactly-once under a row lock; residual risk: a user who confirms without reading |
| Injected approve, post or pay | Injected text makes the model prepare an approval, posting, payment, void, delete or export (ADR-0027) | Same card rule; one action on one named record in the active branch (no bulk); payment accounts are named and resolved server-side, never guessed; post, pay, void and delete need a second explicit confirmation on screen; the route's own permission, period, maker-checker and branch rules still refuse; deletion limited to draft journal entries; `assistant.enabled = false` stops pending cards; residual risk: a user who confirms twice without reading |
| Provenance loss | An AI-created record cannot be told from a human one | `created_via = assistant` on leads, customers, suppliers, invoices, expenses, bookings, shown as an AI badge; audit rows name the user and the action (ADR-0023) |
| Chat history exposure | A saved conversation is read by another user or leaks from a dump | Owner-only queries (every read/delete filters by the signed-in user; foreign ids are 404), listing limited to the active branch, title and text encrypted with `APP_KEY`, retention setting with a daily prune, delete one/all, audit rows never contain text (ADR-0022); residual risk: dump plus `APP_KEY` |
| Key exposure | Key stored, logged or echoed | Env only (ADR-0017); ADR-0019 (accepted) adds an encrypted, write-only, audited store in Settings; Settings API refuses unknown `assistant.*` keys; provider errors never copy bodies; test asserts the key is absent from responses, flags, settings and audit |
| Document disclosure | Another user or a guessed URL reads a passport or invoice | Private disk, random names, no public URL; every read re-checks record permission, branch and `attachments.*`; personal-data categories need `attachments.sensitive` and are hidden from lists; responses are `no-store` with nosniff and a sandbox CSP; reads are audited (ADR-0021) |
| Malicious upload | Script or polyglot file stored and served | Type from content on an allowlist (PDF, JPEG, PNG, WebP), size and count limits, images must decode, PDFs with active content refused, served only as an attachment or sandboxed inline; no malware scan (stated in `docs/deployment/attachments.md`) |
| Extraction abuse | Instructions hidden in a document steer the model, or a wrong reading is trusted | The document is declared untrusted data; only the fields of the category are kept and validated by type; unclear or missing values are flagged; the proposal creates and posts nothing; a person confirms; values are encrypted at rest and audit entries carry field names only |
| Transcript retention | Conversations kept server-side | None stored; audit rows hold counts and tool names only |

Additional abuse cases to test (blueprint AT-1..AT-14):

13. An injected lead note cannot trigger extra tool calls or leak other customers.
14. An MCP token cannot call any non-GET route or any export route.
15. Cross-branch ids requested through the assistant return 403/404 and are audited.
16. A deactivated or revoked user's MCP token fails immediately.
17. A paged bulk read is flagged (`mcp.bulk_read`) and hits the daily row budget. (Implemented and tested in stage 2.)
18. With `mcp.enabled` off, no MCP token can be created and every MCP request is refused and audited.
19. A gateway pinned to a branch the token's user does not hold refuses every call, and fails closed when the branch list is unavailable.
20. A user without `mcp.admin` cannot list or revoke another user's MCP tokens; an administrator's revocation is audited with the owner.
21. The assistant's scripted model asks for a tool outside the catalogue, for `party_type` `employee` (any spelling) or for another branch: it gets a refusal as the tool result and no data (`AssistantChatTest`).
22. Customer or lead text containing instructions, HTML or external links comes back inside the untrusted-data envelope, causes no extra tool call, and unsafe output is reduced to plain text (`AssistantChatTest`).
23. With `assistant.redact_pii` on, no raw phone number, e-mail address or tax id appears in any request body sent to the provider (AT-12, `AssistantChatTest`).
