# ADR-0008 — API and error conventions

- Status: Proposed
- Date: 2026-11-09
- Deciders: Architect + frontend lead

## Context

The React SPA and future integrations consume the API. Errors must be predictable, localizable, and safe.

## Decision

1. Base path `/api/v1`. JSON only.
2. **Success:** resources use `{ "data": ... }`; collections use `{ "data": [...], "meta": { pagination } }`.
3. **Errors** use a consistent envelope:
   ```json
   { "message": "Human readable", "code": "validation_failed", "errors": { "field": ["..."] }, "requestId": "..." }
   ```
   Status codes: 400 malformed, 401 unauthenticated, 403 forbidden, 404 not found, 409 conflict/state/idempotency, 422 validation, 429 rate limited, 500 unexpected.
4. **Validation:** field-keyed messages; Arabic/English messages resolved by `Accept-Language`.
5. **Dates:** ISO-8601; dates `YYYY-MM-DD`; timestamps UTC with `Z`.
6. **Money:** decimal string + currency + authoritative `amountMinor` (ADR-0003).
7. **Pagination:** `?page=&per_page=` (max 100), response `meta.current_page/last_page/total/per_page`.
8. **Filtering/sorting:** explicit whitelisted query params; unknown params ignored or rejected per endpoint.
9. **Idempotency:** `Idempotency-Key` header on mutating money endpoints.
10. **Versioning:** additive changes within v1; breaking changes require `/api/v2` and an ADR.
11. **OpenAPI** (`openapi.yaml`) is the contract; generated clients are allowed but not authoritative.
12. **No secrets/PII** in error messages; internal exception details only in logs.

## Consequences

- Predictable frontend error handling and localization.
- Contract tests can validate response shape.
