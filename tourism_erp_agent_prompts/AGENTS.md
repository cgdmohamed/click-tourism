# Agent Repository Rules

Read `reference/PRODUCT_CONTEXT.md` before editing. Follow approved `docs/architecture/` ADRs and API contracts. Design system is source of truth for UI. Application is self-hosted per client, never multi-tenant. PR per scoped task, tests required, financial postings must be balanced and traceable. Avoid changing shared schemas/contracts without architect approval. Verify branches, migrations, rollback, and backward compatibility. Never store secrets, never force push, never deploy without human approval.
