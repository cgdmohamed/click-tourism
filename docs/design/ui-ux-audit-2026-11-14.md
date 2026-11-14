# UI/UX audit and incremental redesign - 2026-11-14

Scope: the React web app (Arabic RTL first, English LTR). Constraints from the owner: keep every business and accounting rule, the existing APIs and database, authentication and permissions, and the technology stack; reuse the design system (`web/src/components/ui.tsx`, tokens in `web/src/index.css`); no decorative elements; large financial values must fit; better loading, empty and error states; check 1440, 768 and 390 px; nothing is merged into `main` automatically.

Method: a baseline of 27 screenshots at 1440, 768 and 390 px (dashboard, bookings, booking form, customers, invoices, reports, plus stress states: very large amounts, a server error, an empty list), automated measurements (page overflow, touch-target size, console errors) and a read of the code. An earlier review (10 issues) already fixed several items; this audit lists what is still open after those fixes and the new asks. "Before" images: `scratchpad/review/baseline/`.

## Prioritized findings

Severity: **P1** blocks or misleads a task, **P2** slows daily work, **P3** polish.

### 1. Booking form architecture and usability
| # | Sev | Finding | Fix |
|---|---|---|---|
| 1.1 | P1 | One long modal mixes trip, travellers, currency, notes and a repeating service table; the save button is disabled with no explanation of what is missing; the customer field and "add customer" sit in a cramped row. | Sectioned form (Customer and trip / Travellers / Services / Summary) with a sticky summary bar (total, tax, margin, what is missing), inline validation messages next to the field, a clear primary action. |
| 1.2 | P1 | Service rows are dense: 6 inputs in a grid that wraps unpredictably, especially at 768 px. | One card per service with a stable two-row layout; line total and margin visible; add/duplicate/remove actions with labels. |
| 1.3 | P2 | Margin warning only in the footer; negative margin line not marked on the line itself. | Mark the line and the summary; keep it a warning (owner decision DEC-025). |

### 2. Financial dashboard information hierarchy
| # | Sev | Finding | Fix |
|---|---|---|---|
| 2.1 | P1 | Very large amounts break the layout: the value wraps and the currency symbol drops to a second line (390 and 768 px); the number font is too large for 15-digit amounts. | A shared `Money` rule: never wrap, bidi-isolated, fluid font size that steps down for long values, full value in a tooltip/title. |
| 2.2 | P2 | All eight KPIs have the same weight; the three that decide the day (cash, receivables, payables) are not distinguished from counts such as unmatched bank lines. | Primary row for cash / receivables / payables, secondary row for the rest; the count-type KPI has its own style. |
| 2.3 | P2 | The unmatched bank movements card shows a bare `0` with no hint. | Add the hint line and the link like the others. |

### 3. Reports navigation and filtering
| # | Sev | Finding | Fix |
|---|---|---|---|
| 3.1 | P1 | A strip of filter controls plus Run, Print, CSV, XLSX and PDF buttons sits above every report; filters differ per report and are laid out as a wrapping row. | The program-wide filter drawer (one Filter button, chips for applied filters, the report runs when filters are applied) and one Actions menu (Print, CSV, XLSX, PDF); delivery options stay in their collapsed panel. |
| 3.2 | P2 | The tabs are grouped now but the active group is not obvious on 768 px. | Keep groups; make the active tab visible without scrolling at every width. |

### 4. Customer and invoice tables (and every other list)
| # | Sev | Finding | Fix |
|---|---|---|---|
| 4.1 | P1 | Every list has its own row of search/select/date controls (`Select` was a native control, inconsistent with the searchable pickers). | The same Filter button and drawer on all lists; search stays inline; one dropdown design everywhere (done in the foundation). |
| 4.2 | P2 | Dense numeric columns are left to default alignment; headers do not stay visible while scrolling; row actions differ per page. | Right-aligned tabular numbers, sticky header, one fixed actions column pattern, row opens on name/code. |
| 4.3 | P3 | Pagination shows the range but no page-size or jump. | Keep as is (API fixed); no change. |

### 5. Mobile UX and responsive density
| # | Sev | Finding | Fix |
|---|---|---|---|
| 5.1 | P1 | 768 px was never designed for: the dashboard shows two oversized cards per row, tables keep a phone-width card layout or a cut-off table depending on the page. | Tablet rules per page: 2-3 column KPI grid with fluid numbers, tables from 768 px up, cards below. Test at 1440, 768 and 390. |
| 5.2 | P2 | Lists other than bookings still render a table on a phone where columns are hidden off-screen (payments, expenses, leads, supplier settlements, documents). | Phone cards (`MobileCards`) wherever a table has more than four columns. |

### 6. RTL/LTR and financial number formatting
| # | Sev | Finding | Fix |
|---|---|---|---|
| 6.1 | P1 | Amounts mix LTR digits and the Arabic currency symbol inside RTL text with no bidi isolation, so the minus sign and symbol can jump sides. | `Money` renders in an isolated LTR run with the sign fixed; negative amounts in parentheses or with a leading minus consistently; same component in tables, cards, dialogs. |
| 6.2 | P2 | Numbers in free text (hints, labels) are formatted ad hoc in some pages. | Replace remaining ad-hoc formatting with `formatMoney`/`Money`; unit tests for large and negative values in both locales. |

### Cross-cutting: loading, empty and error states
| # | Sev | Finding | Fix |
|---|---|---|---|
| 7.1 | P2 | Loading is a centred spinner; the layout jumps when data arrives. | Skeleton rows/cards of the right shape. |
| 7.2 | P2 | Empty lists say only "No data"; no next step. Error shows the raw server message in English inside the Arabic UI. | Empty state with the reason and the primary action; error state with a translated message, a retry button and a request reference when available. |

## Delivery plan
1. Foundation (done, commit `9ce2e91`): one dropdown, the filter drawer, the actions menu; Bookings is the reference page.
2. Page by page, in parallel isolated branches, each with before/after screenshots at 1440, 768 and 390 px: booking form; dashboard and `Money`; reports; customers + invoices + suppliers + payments; the other list pages; loading/empty/error primitives.
3. Merge into the working branch `claude/clever-sagan-89spcf` only; nothing goes to `main` without the owner.

Not changed by design: business logic, accounting rules, APIs, schema, permissions. No API parameter is added for the new filters (they use the filters that already exist; a missing filter is listed, not invented).
