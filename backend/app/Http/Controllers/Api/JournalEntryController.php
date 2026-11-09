<?php

namespace App\Http\Controllers\Api;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\PostingLine;
use App\Modules\Accounting\Services\PostingRequest;
use App\Modules\Accounting\Services\PostingService;
use App\Modules\Money\Services\FxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JournalEntryController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $branch = $this->branch();

        $entries = JournalEntry::query()
            ->with(['lines.account', 'reversalOf'])
            ->when($branch, fn ($query) => $query->where('branch_id', $branch->id))
            ->when($request->query('from'), fn ($query, $from) => $query->whereDate('entry_date', '>=', $from))
            ->when($request->query('to'), fn ($query, $to) => $query->whereDate('entry_date', '<=', $to))
            ->when($request->query('status'), fn ($query, $status) => $query->where('status', $status))
            ->orderByDesc('entry_date')
            ->orderByDesc('id')
            ->paginate(min(100, max(1, (int) $request->query('per_page', 50))));

        $entries->getCollection()->transform(fn (JournalEntry $entry) => $entry->toApiArray(true));

        return $this->paginated($entries);
    }

    public function show(JournalEntry $journalEntry): JsonResponse
    {
        $journalEntry->load('lines.account', 'reversalOf');

        return $this->ok($journalEntry->toApiArray(true));
    }

    public function store(Request $request, PostingService $posting, FxService $fx): JsonResponse
    {
        $data = $request->validate([
            'entry_date' => ['required', 'date'],
            'branch_id' => ['nullable', 'string'],
            'currency' => ['required', 'string', 'size:3'],
            'fx_rate' => ['nullable', 'string'],
            'fx_rate_date' => ['nullable', 'date'],
            'fx_rate_source' => ['nullable', 'string', 'max:50'],
            'memo' => ['nullable', 'string', 'max:255'],
            'post' => ['nullable', 'boolean'],
            'lines' => ['required', 'array', 'min:2'],
            'lines.*.account_id' => ['required', 'string'],
            'lines.*.debit_minor' => ['nullable', 'integer', 'min:0'],
            'lines.*.credit_minor' => ['nullable', 'integer', 'min:0'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
            'lines.*.party_type' => ['nullable', 'string', 'max:20'],
            'lines.*.party_id' => ['nullable', 'integer'],
        ]);

        $branch = $this->branchOrFail();
        $currency = strtoupper($data['currency']);
        $fxRate = (string) ($data['fx_rate'] ?? $fx->rateFor($currency, $data['entry_date']));

        $lines = [];
        foreach ($data['lines'] as $line) {
            $account = Account::query()->where('public_id', $line['account_id'])->first()
                ?? Account::query()->where('code', $line['account_id'])->first();

            if (! $account) {
                return response()->json([
                    'message' => 'Account not found: '.$line['account_id'],
                    'code' => 'account_not_found',
                ], 422);
            }

            $debit = (int) ($line['debit_minor'] ?? 0);
            $credit = (int) ($line['credit_minor'] ?? 0);

            if ($debit > 0 && $credit > 0) {
                return response()->json([
                    'message' => 'A line cannot have both debit and credit.',
                    'code' => 'invalid_line',
                ], 422);
            }

            $lines[] = new PostingLine(
                account: $account,
                debitMinor: $debit,
                creditMinor: $credit,
                partyType: $line['party_type'] ?? null,
                partyId: $line['party_id'] ?? null,
                description: $line['description'] ?? null,
            );
        }

        $postingRequest = PostingRequest::make(
            entryDate: $data['entry_date'],
            branch: $branch,
            currency: $currency,
            lines: $lines,
            memo: $data['memo'] ?? 'Manual journal entry',
            sourceType: 'manual',
            sourceId: null,
            event: null,
            fxRate: $fxRate,
            fxRateDate: $data['fx_rate_date'] ?? $data['entry_date'],
            fxRateSource: $data['fx_rate_source'] ?? 'manual',
            actorId: $this->actorId(),
        );

        $entry = ($data['post'] ?? true)
            ? $posting->post($postingRequest)
            : $posting->draft($postingRequest);

        return $this->created($entry->toApiArray(true));
    }

    public function post(JournalEntry $journalEntry, PostingService $posting): JsonResponse
    {
        return $this->ok($posting->postDraft($journalEntry, $this->actorId())->toApiArray(true));
    }

    public function reverse(Request $request, JournalEntry $journalEntry, PostingService $posting): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $reversal = $posting->reverse($journalEntry, $data['reason'], $this->actorId());

        return $this->ok($reversal->toApiArray(true));
    }
}
