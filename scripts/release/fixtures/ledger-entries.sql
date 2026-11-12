-- Three balanced, posted journal entries (capital, credit sale, supplier cost)
-- written straight into a scratch database so that backup/restore and upgrade
-- rehearsals have a non-empty ledger to compare. Requires branch HQ, the
-- accounts 1100/1110/2100/3200/4100/5100 and an open period covering today
-- (created by `erp:install` or by reference-data.sql). Base currency EGP.
BEGIN;

INSERT INTO journal_entries
    (public_id, number, entry_date, branch_id, fiscal_period_id, currency_code, fx_rate, fx_rate_date,
     fx_rate_source, status, memo, source_type, source_id, event, posted_at, created_at, updated_at)
SELECT v.public_id, v.number, CURRENT_DATE, b.id, p.id, 'EGP', 1, CURRENT_DATE,
       'manual', 'posted', v.memo, 'rehearsal', v.source_id, 'fixture', now(), now(), now()
FROM (VALUES
    ('RHENTRY0000000000000000001', 'RH-0001', 'Rehearsal: owner capital', 1),
    ('RHENTRY0000000000000000002', 'RH-0002', 'Rehearsal: credit sale', 2),
    ('RHENTRY0000000000000000003', 'RH-0003', 'Rehearsal: supplier cost', 3)
) AS v(public_id, number, memo, source_id)
CROSS JOIN (SELECT id FROM branches WHERE code = 'HQ') b
CROSS JOIN (
    SELECT id FROM fiscal_periods
    WHERE starts_on <= CURRENT_DATE AND ends_on >= CURRENT_DATE
    ORDER BY id LIMIT 1
) p;

INSERT INTO journal_lines
    (journal_entry_id, account_id, branch_id, debit_minor, credit_minor, base_debit_minor, base_credit_minor,
     currency_code, description, created_at, updated_at)
SELECT e.id, a.id, e.branch_id, l.dr, l.cr, l.dr, l.cr, 'EGP', l.memo, now(), now()
FROM (VALUES
    ('RH-0001', '1110', 500000000::bigint, 0::bigint, 'Cash in'),
    ('RH-0001', '3200', 0::bigint, 500000000::bigint, 'Capital'),
    ('RH-0002', '1100', 120005025::bigint, 0::bigint, 'Receivable'),
    ('RH-0002', '4100', 0::bigint, 120005025::bigint, 'Revenue'),
    ('RH-0003', '5100', 70002575::bigint, 0::bigint, 'Cost'),
    ('RH-0003', '2100', 0::bigint, 70002575::bigint, 'Payable')
) AS l(number, code, dr, cr, memo)
JOIN journal_entries e ON e.number = l.number
JOIN accounts a ON a.code = l.code;

COMMIT;
