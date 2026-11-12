# Web frontend

React 19 + TypeScript + Vite + Tailwind v4 single-page app for the Tourism ERP.
The API contract is [`../docs/architecture/openapi.yaml`](../docs/architecture/openapi.yaml);
the backend lives in [`../backend`](../backend).

## Commands

```powershell
npm install
npm run dev -- --host 127.0.0.1 --port 5173   # proxies /api/v1 to 127.0.0.1:8000
npm run build                                  # tsc -b && vite build
npm run lint
```

The dev server proxies `/api/v1` to `http://127.0.0.1:8000` (see
`vite.config.ts`). Start the Laravel API first.

## Structure

```
src/auth/        AuthProvider, token and active-branch state
src/components/  Layout and shared UI primitives (ui.tsx)
src/i18n/        Arabic/English dictionary and RTL direction
src/lib/         axios client, money/date formatting, shared types
src/pages/       Login, Dashboard, Accounts, Journal, Customers, Suppliers,
                 Bookings, Payments, Reports, Leads, Settings
```

- Money is handled as integer minor units in API payloads and converted only at
  the display edge (`src/lib/format.ts`). Never introduce floating-point money.
- Lists expect `{ data: [...] }`. Paginated responses may include `meta`, which
  the UI currently ignores.
- The active branch is sent as `X-Branch-Id`; the server validates it against
  the user's assignments. The UI never decides access on its own.
- Default locale is Arabic (`tourism_erp_locale`), default token key is
  `tourism_erp_token`, default branch key is `tourism_erp_branch`.

## Design system replacement point

The approved design system has not been supplied yet. A temporary neutral token
layer lives in [`src/index.css`](src/index.css) (`@theme` block) and the shared
primitives in [`src/components/ui.tsx`](src/components/ui.tsx). Replace those
two places when the design system arrives; do not add a parallel UI kit.

## Browser E2E, accessibility and coverage

`npm run test:e2e` needs a migrated, seeded **disposable** installation
(`php artisan migrate:fresh --seed` with `DEMO_PASSWORD`; set the same value as
`E2E_ADMIN_PASSWORD`). Playwright starts the API and Vite itself, or reuses
servers when `E2E_BASE_URL` is set.

- Specs: invoice PDF, report export, lead conversion, payment refund request.
  The refund spec approves the accounting policy to be able to post a receipt,
  so it only runs with `E2E_APPROVE_POLICY=1` (CI sets it). Never set it against
  a real client installation.
- `e2e/accessibility.spec.ts` runs axe (WCAG 2 A/AA) on 8 key pages and fails on
  any serious or critical violation not listed in `KNOWN_VIOLATIONS`. It runs
  last (separate `a11y` project) because `auth/login` shares a 10/min throttle
  counter with the `demo/status` polls of every page load.
- `npm run test:coverage` gates Vitest coverage on `src/lib` (thresholds in
  `vitest.config.ts`; raise them, never lower them).
