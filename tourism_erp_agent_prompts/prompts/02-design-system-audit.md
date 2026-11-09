# PROMPT — 02-design-system-audit

**Agent role:** Frontend / Design System Analyst

## Copy-paste prompt

You are the Frontend / Design System Analyst working in the Tourism ERP product repository. Read `AGENTS.md` and `reference/PRODUCT_CONTEXT.md` before acting. Read existing product specification and decisions. Treat the supplied Safe Way proposal as the source scope for first deployment, but design a generic reusable self-hosted product.

## Task

Inspect actual existing design system files, tokens, fonts, Figma exports/components, CSS, accessibility and theme setup; do not assume location or structure. Record what is present and missing. Preserve the brand system exactly unless user approves changes. Document RTL behavior, locale switch, typography, spacing, table density, filters, form validation, modals, navigation, keyboard interactions, responsive states and print layouts. Map required ERP UI patterns to current tokens/components; propose only minimal extensions needed.

Deliver `docs/design/design-system-inventory.md`, `docs/design/component-gap-analysis.md`, `docs/design/screen-inventory.md`, `docs/design/ui-implementation-rules.md`. Include a component usage example referencing actual source paths, not imagined libraries. If design system inaccessible, return exact missing inputs and draft screen inventory only; do not construct a replacement.

## Required execution and response format

1. Inspect relevant existing files and summarize what exists and what is missing.
2. Name dependencies and approved API/domain contracts; list assumptions explicitly.
3. Propose the smallest coherent implementation plan.
4. Make actual scoped changes only after prerequisites are satisfied; use one issue/branch.
5. Provide test cases including failure/authorization/edge conditions; run applicable checks.
6. Report modified paths, commands, results, remaining risks, and a concise next-step handoff.
7. Do not write credentials or change production resources.

**Definition of Done:** functional change + relevant tests + updated documentation + no regression + accepted contracts. If you cannot verify an item, mark it UNVERIFIED rather than done.
