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
