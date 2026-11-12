-- Minimal reference data for rehearsals that run on an OLDER schema (upgrade
-- simulation), where `erp:install` of the current release cannot be used.
-- Only columns that exist since the first accounting migration are written.
-- Scratch databases only (erp_rehearsal_*). Contains no secrets and no users.
BEGIN;

INSERT INTO branches (public_id, code, name_ar, name_en, accounting_mode, is_active, created_at, updated_at)
VALUES ('RHBRANCH0000000000000000HQ', 'HQ', 'HQ', 'Head Office', 'dimension', true, now(), now());

INSERT INTO accounts (public_id, code, name_ar, name_en, type, is_postable, is_active, created_at, updated_at)
VALUES
    ('RHACCT00000000000000001100', '1100', 'AR', 'Accounts Receivable', 'asset', true, true, now(), now()),
    ('RHACCT00000000000000001110', '1110', 'Cash', 'Cash on Hand', 'asset', true, true, now(), now()),
    ('RHACCT00000000000000002100', '2100', 'AP', 'Accounts Payable', 'liability', true, true, now(), now()),
    ('RHACCT00000000000000003200', '3200', 'Capital', 'Owner Capital', 'equity', true, true, now(), now()),
    ('RHACCT00000000000000004100', '4100', 'Revenue', 'Service Revenue', 'revenue', true, true, now(), now()),
    ('RHACCT00000000000000005100', '5100', 'Cost', 'Cost of Services', 'expense', true, true, now(), now());

INSERT INTO fiscal_years (name, starts_on, ends_on, created_at, updated_at)
VALUES (to_char(CURRENT_DATE, 'YYYY'), date_trunc('year', CURRENT_DATE)::date,
        (date_trunc('year', CURRENT_DATE) + interval '1 year - 1 day')::date, now(), now());

INSERT INTO fiscal_periods (fiscal_year_id, name, starts_on, ends_on, status, created_at, updated_at)
SELECT fy.id, to_char(CURRENT_DATE, 'YYYY-MM'), date_trunc('month', CURRENT_DATE)::date,
       (date_trunc('month', CURRENT_DATE) + interval '1 month - 1 day')::date, 'open', now(), now()
FROM fiscal_years fy ORDER BY fy.id DESC LIMIT 1;

COMMIT;
