<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Shared API/schema contract changes need recorded approval: AGENTS.md ("Avoid changing shared
 * schemas/contracts without architect approval") and docs/architecture/README.md ("Approval
 * required"). An ADR that only says "Proposed" is not that approval.
 *
 * Every ADR listed in CONTRACT_ADRS ships additive or changed contract and must carry a filled
 * "Approval record" table before its branch may merge. This test is the CI gate:
 *  - an ADR that is not accepted fails here, so a pull request cannot go green while the
 *    contract change is unapproved;
 *  - an ADR that claims to be accepted but still carries a placeholder row also fails, so editing
 *    the status line is not a way around the rule.
 *
 * Approving means editing the ADR (its "How to record an approval" steps), not this test.
 */
class ContractApprovalTest extends TestCase
{
    /**
     * ADRs that change the shared contract, keyed by id.
     *
     * @var array<string, string>
     */
    private const CONTRACT_ADRS = [
        'ADR-0018' => 'docs/architecture/ADRs/0018-assistant-enablement-and-mcp-status.md',
    ];

    /** Cells that mean "nobody signed this yet". */
    private const PLACEHOLDERS = ['', '—', '-', '_pending_', 'pending', 'tbd', 'n/a'];

    public function test_contract_changing_adrs_record_their_approval(): void
    {
        $problems = [];

        foreach (self::CONTRACT_ADRS as $id => $relative) {
            $path = $this->repoPath($relative);
            $this->assertFileExists($path, "{$id} is missing at {$relative}.");

            $problems = [...$problems, ...$this->approvalProblems($id, (string) file_get_contents($path))];
        }

        $this->assertSame(
            [],
            $problems,
            "Shared API/schema contract changes are NOT approved (AGENTS.md). Do not merge, publish openapi.yaml or\n".
            "tag a release from this branch until an architect has signed every row of the ADR's approval record and\n".
            "the status line quotes that grant. Filling it in the ADR is the fix; editing this test is not.\n\n".
            implode("\n", $problems),
        );
    }

    /**
     * The gate must be satisfiable, and it must not be satisfiable by only editing the status line.
     * Runs the same rules against in-memory ADRs so the check above cannot rot silently.
     */
    public function test_the_gate_accepts_a_recorded_approval_and_rejects_a_status_only_edit(): void
    {
        $this->assertSame([], $this->approvalProblems('ADR-9999', $this->approvedAdr()));

        $problems = $this->approvalProblems('ADR-9999', $this->approvedAdr('_pending_'));
        $this->assertNotEmpty($problems, 'A placeholder row must still fail even when the status says Accepted.');
        $this->assertStringContainsString('approver is empty', implode(' ', $problems));

        $notAccepted = $this->approvalProblems('ADR-9999', $this->approvedAdr(null, 'Proposed - MERGE BLOCKED'));
        $this->assertStringContainsString('is not accepted', implode(' ', $notAccepted));
    }

    /** @return list<string> Human-readable reasons the ADR may not merge; empty means approved. */
    private function approvalProblems(string $id, string $contents): array
    {
        $rows = $this->approvalRows($contents);
        if ($rows === []) {
            return ["{$id}: no '## Approval record' table found."];
        }

        $problems = [];
        if (! $this->isAccepted($contents)) {
            $problems[] = "{$id} is not accepted: its status line still says Proposed/blocked.";
        }

        foreach ($rows as $index => $row) {
            $missing = $this->rowProblems($row);
            if ($missing !== []) {
                $problems[] = sprintf('%s row %d [%s]: %s.', $id, $index + 1, $this->shorten($row['change']), implode(', ', $missing));
            }
        }

        return $problems;
    }

    private function repoPath(string $relative): string
    {
        return dirname(base_path()).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }

    /**
     * The status line's verdict is its first word, as in "Accepted for stages 1 and 2 (...)".
     * Later clauses may legitimately mention blocked or proposed scope (stage 4), so only the
     * verdict is tested.
     */
    private function isAccepted(string $contents): bool
    {
        if (preg_match('/^-\s*\*\*Status:\*\*\s*(.+)$/m', $contents, $m) !== 1) {
            return false;
        }

        return preg_match('/^accepted\b/i', trim($m[1])) === 1;
    }

    /**
     * Data rows of the approval table: number, change, approver, role, date, where.
     *
     * @return list<array{change: string, approver: string, role: string, date: string, where: string}>
     */
    private function approvalRows(string $contents): array
    {
        $section = $this->section($contents, 'Approval record');
        if ($section === null) {
            return [];
        }

        $rows = [];
        foreach (preg_split('/\R/', $section) ?: [] as $line) {
            $line = trim($line);
            // A data row looks like: | 1 | change | approver | role | date | where |
            if (! str_starts_with($line, '|') || preg_match('/^\|[\s:|-]+\|$/', $line) === 1) {
                continue;
            }
            $cells = array_map('trim', explode('|', trim($line, '|')));
            if (count($cells) < 6 || ! ctype_digit($cells[0])) {
                continue;
            }
            $rows[] = [
                'change' => $cells[1],
                'approver' => $cells[2],
                'role' => $cells[3],
                'date' => $cells[4],
                'where' => $cells[5],
            ];
        }

        return $rows;
    }

    /** @param  array{change: string, approver: string, role: string, date: string, where: string}  $row */
    private function rowProblems(array $row): array
    {
        $missing = [];
        foreach (['approver', 'role', 'date', 'where'] as $field) {
            if (in_array(mb_strtolower(trim($row[$field])), self::PLACEHOLDERS, true)) {
                $missing[] = "{$field} is empty";
            }
        }
        if ($missing === [] && preg_match('/\d{4}-\d{2}-\d{2}/', $row['date']) !== 1) {
            $missing[] = 'date is not YYYY-MM-DD';
        }

        return $missing;
    }

    /** The text under a `## <title>` heading, up to the next level-2 heading. */
    private function section(string $contents, string $title): ?string
    {
        $pattern = '/^##\s*'.preg_quote($title, '/').'\s*$(.*?)(?=^##\s|\z)/ms';

        return preg_match($pattern, $contents, $m) === 1 ? $m[1] : null;
    }

    private function shorten(string $change): string
    {
        $change = trim($change);

        return mb_strlen($change) <= 60 ? $change : mb_substr($change, 0, 57).'...';
    }

    /** A minimal ADR that satisfies the rules, used to prove the gate can pass. */
    private function approvedAdr(?string $approver = 'A. Architect', string $status = 'Accepted for the rows below (2026-11-13)'): string
    {
        return implode("\n", [
            '# ADR-9999 - Fixture',
            '',
            '- **Status:** '.$status,
            '- **Date:** 2026-11-13',
            '',
            '## Decision',
            '',
            'Fixture only.',
            '',
            '## Approval record',
            '',
            '| # | Contract change | Approver (name) | Role | Date | Where the approval was given |',
            '|---|---|---|---|---|---|',
            '| 1 | `GET /api/v1/example` (new route) | '.$approver.' | architect | 2026-11-13 | PR #1 comment |',
            '',
            '## Consequences',
            '',
            'Fixture only.',
            '',
        ]);
    }
}
