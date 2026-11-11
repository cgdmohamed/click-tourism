<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountingSchedule;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Models\OpeningBalanceLine;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\Setting;
use App\Modules\Core\Services\AuditService;
use App\Modules\Core\Services\RoundingPolicy;
use App\Modules\Core\Services\SettingService;
use App\Support\Money;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * One-off migration of an existing install from the shared account 1150 to the
 * four dedicated accounts 1151-1154 (accountant decision). Balances are moved
 * with ordinary balanced entries through PostingService, per branch, keeping
 * party tags and each line's own currency. Safe to run repeatedly.
 */
class Account1150SplitService
{
    public const PARENT = '1150';

    public const SOURCE_TYPE = 'account_reclassification';

    /** @var array<string, array{name_ar: string, name_en: string, setting: string}> */
    public const CHILDREN = [
        '1151' => ['name_ar' => 'سلف الموظفين', 'name_en' => 'Employee advances', 'setting' => 'employee_advance_account_code'],
        '1152' => ['name_ar' => 'عهد الموظفين', 'name_en' => 'Employee custody', 'setting' => 'employee_custody_account_code'],
        '1153' => ['name_ar' => 'دفعات مقدمة للموردين', 'name_en' => 'Supplier advances', 'setting' => 'supplier_advance_account_code'],
        '1154' => ['name_ar' => 'مصروفات مدفوعة مقدماً', 'name_en' => 'Prepaid expenses', 'setting' => 'prepaid_expense_account_code'],
    ];

    public function __construct(
        private readonly PostingService $posting,
        private readonly SettingService $settings,
        private readonly AuditService $audit,
        private readonly RoundingPolicy $rounding,
    ) {}

    /**
     * @return array{
     *   moves: array<int, array<string, mixed>>, warnings: string[], fallbacks: array<int, array<string, mixed>>, entries: string[],
     *   created_accounts: string[], missing_accounts: string[], settings: array<string, string>,
     *   made_non_postable: bool, non_postable_blockers: string[], applied: bool
     * }
     */
    public function run(bool $apply, ?int $actorId = null): array
    {
        if (! $this->settings->bool('accounting', 'accounting_policy_approved', false)) {
            throw new DomainException('Refusing to run: accounting_policy_approved is false. The accountant must approve the policy first.');
        }

        $parent = Account::query()->where('code', self::PARENT)->first();
        if (! $parent) {
            throw new DomainException('Account 1150 does not exist; nothing to split.');
        }

        $result = [
            'moves' => [], 'warnings' => [], 'fallbacks' => [], 'entries' => [], 'created_accounts' => [],
            'missing_accounts' => [], 'settings' => [], 'made_non_postable' => false,
            'non_postable_blockers' => [], 'applied' => $apply,
        ];

        $moves = $this->plan($parent, $result['warnings'], $result['fallbacks']);
        $result['moves'] = $moves;

        foreach (self::CHILDREN as $code => $meta) {
            $child = Account::query()->where('code', $code)->first();
            if (! $child) {
                $result['missing_accounts'][] = $code;
            } elseif (! $child->is_active || ! $child->is_postable) {
                throw new DomainException("Account {$code} exists but is not active and postable; fix it before splitting.");
            }
        }

        foreach (self::CHILDREN as $code => $meta) {
            $key = $meta['setting'];
            if (Setting::query()->where('group', 'accounting')->where('key', $key)->value('value') === self::PARENT) {
                $result['settings'][$key] = $code;
            }
        }

        if (! $apply) {
            $result['non_postable_blockers'] = $this->blockers($parent, $moves, false);

            return $result;
        }

        DB::transaction(function () use (&$result, $parent, $moves, $actorId) {
            foreach (self::CHILDREN as $code => $meta) {
                if (in_array($code, $result['missing_accounts'], true)) {
                    Account::query()->create([
                        'code' => $code, 'name_ar' => $meta['name_ar'], 'name_en' => $meta['name_en'],
                        'type' => $parent->type, 'parent_id' => $parent->id, 'is_postable' => true, 'is_active' => true,
                        'classification' => Account::defaultClassification((string) $parent->type, $code),
                    ]);
                    $result['created_accounts'][] = $code;
                }
            }

            $result['entries'] = $this->postMoves($parent, $moves, $actorId);

            foreach ($result['settings'] as $key => $code) {
                $this->settings->set('accounting', $key, $code);
            }

            $unused = [];
            $unusedFallbacks = [];
            $remaining = $this->plan($parent, $unused, $unusedFallbacks);
            $result['non_postable_blockers'] = $this->blockers($parent, $remaining, true);
            if ($result['non_postable_blockers'] === [] && $parent->is_postable) {
                $parent->update(['is_postable' => false]);
                $result['made_non_postable'] = true;
            }

            $this->audit->log('account.split_1150', $parent, null, [
                'created_accounts' => $result['created_accounts'],
                'entries' => $result['entries'],
                'settings' => $result['settings'],
                'made_non_postable' => $result['made_non_postable'],
                'blockers' => $result['non_postable_blockers'],
            ], $actorId);
        });

        return $result;
    }

    /**
     * Net movements still sitting on 1150, grouped by branch, line currency,
     * party and destination account.
     *
     * @param  array<string, string>  $warnings
     * @param  array<int, array<string, mixed>>  $fallbacks  employee lines with no linked employee transaction
     * @return array<int, array{branch_id: int, currency: string, party_type: ?string, party_id: ?int, target: string, net_minor: int, base_net_minor: int}>
     */
    private function plan(Account $parent, ?array &$warnings, ?array &$fallbacks = null): array
    {
        $warnings = [];
        $fallbacks = [];
        $rows = JournalLine::query()
            ->join('journal_entries as je', 'je.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.account_id', $parent->id)
            ->where('je.status', 'posted')
            ->groupBy('journal_lines.branch_id', 'journal_lines.currency_code', 'journal_lines.party_type', 'journal_lines.party_id', 'je.source_type', 'journal_lines.description', 'je.id', 'je.number', 'je.reversal_of_id')
            ->selectRaw('journal_lines.branch_id, journal_lines.currency_code, journal_lines.party_type, journal_lines.party_id, je.source_type as src, journal_lines.description as descr, je.id as entry_id, je.number as entry_number, je.reversal_of_id as reversal_of,
                SUM(journal_lines.debit_minor - journal_lines.credit_minor) as net,
                SUM(journal_lines.base_debit_minor - journal_lines.base_credit_minor) as base_net')
            ->get();

        $groups = [];
        foreach ($rows as $row) {
            // Our own reclassification lines offset the original group; the
            // destination is recorded in the line description we wrote.
            $target = $row->src === self::SOURCE_TYPE && str_starts_with((string) $row->descr, 'Reclassify to ')
                ? substr((string) $row->descr, strlen('Reclassify to '))
                : $this->target($row, $warnings, $fallbacks);
            $key = implode('|', [$row->branch_id, $row->currency_code, $row->party_type ?? '', $row->party_id ?? '', $target]);
            $groups[$key] ??= [
                'branch_id' => (int) $row->branch_id, 'currency' => (string) $row->currency_code,
                'party_type' => $row->party_type, 'party_id' => $row->party_id !== null ? (int) $row->party_id : null,
                'target' => $target, 'net_minor' => 0, 'base_net_minor' => 0,
            ];
            $groups[$key]['net_minor'] += (int) $row->net;
            $groups[$key]['base_net_minor'] += (int) $row->base_net;
        }

        return array_values(array_filter(
            $groups,
            fn ($g) => $g['net_minor'] !== 0 || $g['base_net_minor'] !== 0,
        ));
    }

    /**
     * @param  array<string, string>  $warnings
     * @param  array<int, array<string, mixed>>  $fallbacks
     */
    private function target(object $row, array &$warnings, array &$fallbacks): string
    {
        $partyType = $row->party_type;
        $sourceType = $row->src;

        if ($partyType === 'supplier') {
            return '1153';
        }

        if ($partyType === 'employee') {
            if ($sourceType === 'employee_custody') {
                return '1152';
            }
            if ($sourceType === 'employee_advance') {
                return '1151';
            }

            // Any other source: ask the employee subledger what the entry was.
            $types = DB::table('employee_transactions')
                ->where('journal_entry_id', $row->reversal_of ?? $row->entry_id)
                ->pluck('type')->all();
            $advance = collect($types)->contains(fn ($t) => str_starts_with((string) $t, 'advance_'));
            $custody = collect($types)->contains(fn ($t) => str_starts_with((string) $t, 'custody_'));
            if ($custody && ! $advance) {
                return '1152';
            }
            if ($advance && ! $custody) {
                return '1151';
            }

            $label = $sourceType ?? 'none';
            $warnings["employee:{$label}"] = "Employee lines from source type '{$label}' without a clear advance/custody link were classified as employee advances (1151); review them.";
            $fallbacks[] = [
                'entry' => (string) $row->entry_number,
                'branch_id' => (int) $row->branch_id,
                'currency' => (string) $row->currency_code,
                'party_id' => $row->party_id !== null ? (int) $row->party_id : null,
                'source_type' => $label,
                'net_minor' => (int) $row->net,
                'target' => '1151',
            ];

            return '1151';
        }

        return '1154';
    }

    /**
     * @param  array<int, array<string, mixed>>  $moves
     * @return string[]
     */
    private function postMoves(Account $parent, array $moves, ?int $actorId): array
    {
        $base = $this->settings->baseCurrency();
        $byBranch = [];
        foreach ($moves as $move) {
            if ($move['net_minor'] === 0) {
                continue; // a base-only residual cannot be expressed as a transaction line
            }
            $byBranch[$move['branch_id']][] = $move;
        }

        $numbers = [];
        $sequence = DB::table('journal_entries')
            ->where('source_type', self::SOURCE_TYPE)
            ->where('event', 'like', 'split-1150:%')
            ->count();

        foreach ($byBranch as $branchId => $branchMoves) {
            $lines = [];
            foreach ($branchMoves as $move) {
                $child = $this->posting->account($move['target']);
                $amount = abs($move['net_minor']);
                $debitChild = $move['net_minor'] > 0;

                $childLine = $debitChild
                    ? PostingLine::debit($child, $amount, $move['party_type'], $move['party_id'], 'Reclassify from 1150')
                    : PostingLine::credit($child, $amount, $move['party_type'], $move['party_id'], 'Reclassify from 1150');
                $parentLine = $debitChild
                    ? PostingLine::credit($parent, $amount, $move['party_type'], $move['party_id'], 'Reclassify to '.$move['target'])
                    : PostingLine::debit($parent, $amount, $move['party_type'], $move['party_id'], 'Reclassify to '.$move['target']);

                if ($move['currency'] !== $base) {
                    $rate = $this->rate($move, $move['currency'], $base);
                    $childLine = $childLine->in($move['currency'], $rate);
                    $parentLine = $parentLine->in($move['currency'], $rate);
                }

                array_push($lines, $parentLine, $childLine);
            }

            $sequence++;
            $entry = $this->posting->post(PostingRequest::make(
                entryDate: now()->toDateString(),
                branch: Branch::query()->findOrFail($branchId),
                currency: $base,
                lines: $lines,
                memo: 'Reclassify account 1150 balances to 1151-1154',
                sourceType: self::SOURCE_TYPE,
                sourceId: $parent->id,
                event: 'split-1150:'.$sequence,
                actorId: $actorId,
            ));
            $numbers[] = $entry->number;
        }

        return $numbers;
    }

    /**
     * Effective rate that reproduces the original base carrying value exactly.
     * Computed at a precision far beyond the stored FX rate (the line rate is
     * only used for conversion) and verified, so no rounding residue is posted.
     *
     * @param  array<string, mixed>  $move
     */
    private function rate(array $move, string $currency, string $base): string
    {
        $net = abs((int) $move['net_minor']);
        $target = abs((int) $move['base_net_minor']);
        $shift = Money::exponent($base) - Money::exponent($currency);
        $ratio = bcdiv((string) $target, (string) $net, 30);
        $rate = $shift >= 0
            ? bcdiv($ratio, (string) (10 ** $shift), 30)
            : bcmul($ratio, (string) (10 ** -$shift), 30);

        if (bccomp($rate, '0', 30) === 0) {
            return '1';
        }

        $converted = (new Money($net, $currency))->convertTo($base, $rate, $this->rounding->mode())->amountMinor;
        if ($converted !== $target) {
            throw new DomainException("Cannot reproduce the base amount {$target} for {$currency} balance {$net}; review the 1150 lines manually.");
        }

        return $rate;
    }

    /**
     * Reasons 1150 must stay postable.
     *
     * @param  array<int, array<string, mixed>>  $moves  what is still unmoved
     * @return string[]
     */
    private function blockers(Account $parent, array $moves, bool $afterPosting): array
    {
        $blockers = [];

        if (! $afterPosting) {
            // In a dry run the transaction-level moves are still to happen.
            $moves = array_values(array_filter($moves, fn ($m) => $m['net_minor'] === 0));
        }
        if ($moves !== []) {
            $blockers[] = 'a base-currency residual remains on 1150 that cannot be reclassified';
        }

        $drafts = JournalLine::query()
            ->join('journal_entries as je', 'je.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.account_id', $parent->id)
            ->where('je.status', 'draft')
            ->exists();
        if ($drafts) {
            $blockers[] = 'draft journal entries still post to 1150';
        }

        $schedules = AccountingSchedule::query()
            ->whereIn('status', [AccountingSchedule::STATUS_DRAFT, AccountingSchedule::STATUS_ACTIVE])
            ->where(fn ($q) => $q->where('source_account_id', $parent->id)->orWhere('target_account_id', $parent->id))
            ->exists();
        if ($schedules) {
            $blockers[] = 'open accounting schedules still reference 1150';
        }

        $opening = OpeningBalanceLine::query()
            ->join('opening_balance_batches as b', 'b.id', '=', 'opening_balance_lines.opening_balance_batch_id')
            ->where('opening_balance_lines.account_id', $parent->id)
            ->where('b.status', 'draft')
            ->exists();
        if ($opening) {
            $blockers[] = 'draft opening balances still reference 1150';
        }

        return $blockers;
    }
}
