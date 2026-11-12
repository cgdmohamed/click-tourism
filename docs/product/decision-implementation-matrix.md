# Decision Implementation Matrix — 2026-11-10

| Decision | Previous state | Required change | Final implementation status | Accountant approval | Related files |
|---|---|---|---|---|---|
| DEC-009/021/032/036 (tax) | Per-item tax profiles with effective dates | One tax per installation: settings `tax.enabled`, `tax.default_rate`, `tax.inclusive`; profiles page/endpoint removed, `tax_profile_id` ignored; effective-dating dropped | Implemented (agent/single-tax-setting); posting accounts unchanged | Pending (rate/inclusive policy) | `TaxPolicy.php`, `BookingService.php`, `SettingController.php` |
| DEC-004 | No guarded trigger default | Configurable trigger, fully-collected default | Implemented (agent/commissions): rules, accrual on full collection, approval, posting behind `accounting_policy_approved` and `commission_auto_post=false`, reversal, payout | Pending for production posting | `docs/architecture/commissions.md` |
| DEC-008 | No explicit revaluation guard | Revaluation/reversal configuration | Disabled settings only; no posting | Pending | `SettingService.php` |
| DEC-014 | Branch dimension and access existed | Record selected one-ledger model without activating clearing | Existing separation retained; inter-branch posting limited to fixed-asset transfers (clearing accounts 1180/2160, see fixed-assets audit item 18) | Pending | ADR-0005, `BranchScope` |
| DEC-015 | Elapsed-hour reassignment | Business calendar, meaningful activity, retries and escalation | Implemented and tested | Not required | `LeadService.php`, decision-support migration |
| DEC-016 | Least-loaded fallback; rules unused | Prioritized rules, category, availability, manual and queue fallback | Backend rule engine implemented and tested; management UI remains | Not required | `LeadService.php`, `AssignmentRule.php` |
| DEC-018 | Calendar-year atomic sequence | Resolve containing fiscal year | Implemented without renumbering history | Statutory review only | `SequenceService.php` |
| DEC-020 | Self-hosted single installation | Record one legal entity per installation | Confirmed; no tenant architecture introduced | Not required | `PRODUCT_CONTEXT.md`, ADR-0011 |
| DEC-022 | No external portals | Keep portals out of MVP | Confirmed; no portal code added | Not required | Product decision records |
| DEC-025 | Margin calculated but not gated | Request/reason/approver/time and maker-checker guard | Operational guard implemented and audited | Pending for accounting effects | Booking service/model/controller/migration |
| DEC-028 | Separate booking and invoice | Guard unified mode | Separate default and disabled unified flag; no posting change | Pending | `SettingService.php` |
| DEC-029 | Bilingual UI and CSV | Select output language and RTL/LTR PDF | Implemented for native report PDF; document-template persistence remains | Statutory layouts pending | `ReportDocument.php`, report UI |
| DEC-040 | CSV and browser print | Native PDF and actual XLSX with authorization parity | Implemented on all existing report export endpoints; 50,000-row synchronous cap retained | Statutory layouts pending | `ReportController.php`, `ReportDocument.php`, `Reports.tsx` |

## Blocked accounting actions

- Commission journal posting stays disabled until the accounting policy is approved (DEC-004/013); accrual, approval and rules work without it.
- Period-end FX revaluation and reversing journals (DEC-008).
- Inter-branch due-to/due-from posting activation (DEC-014).
- Negative/zero-margin commission and accounting interpretation (DEC-025).
- Unified booking confirmation as a tax invoice and any resulting tax posting (DEC-028).
- Fixed statutory PDF layouts or signatures without accountant/legal approval (DEC-029/040).

## Final gaps and next steps

1. Obtain accountant approvals for the blocked actions above; then add balanced, idempotent posting services and closed-period tests as separate scoped work.
2. Add an authorized assignment-rule management screen; backend rules and fields are ready.
3. Extend per-document template/language persistence when the statutory templates are approved.
4. Queue exports above 50,000 rows if a demonstrated operational requirement exceeds the current guarded synchronous limit.
5. All other decisions in `decisions-needed.md` remain in their prior state unless separately recorded as decided.
