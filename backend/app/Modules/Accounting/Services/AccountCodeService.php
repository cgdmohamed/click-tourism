<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\Account;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Suggests the next free chart-of-accounts code. Accountants often choose their
 * own numbering, so the suggestion is only a default: an explicit code is always
 * accepted (and still validated by the caller).
 *
 * Under a parent the sibling pattern is followed: the highest numeric child code
 * plus the step between the last two children (1 for a single child), keeping
 * the child's width. With no numeric child the code is the parent code plus a
 * two-digit counter. Root accounts take the next hundred above the type's
 * existing roots. A taken code is always skipped.
 */
class AccountCodeService
{
    private const MAX_LENGTH = 20;

    private const MAX_DIGITS = 18;

    private const TYPE_BASE = ['asset' => 1000, 'liability' => 2000, 'equity' => 3000, 'revenue' => 4000, 'expense' => 6000];

    public function suggest(?Account $parent, ?string $type): string
    {
        $taken = array_flip(Account::query()->pluck('code')->map(fn ($code) => (string) $code)->all());

        $candidate = $parent !== null
            ? $this->underParent($parent, $taken)
            : $this->root((string) $type, $taken);

        // Never collide: walk forward (numeric codes) or bump the counter (others).
        while (isset($taken[$candidate])) {
            $candidate = $this->increment($candidate);
        }

        if (strlen($candidate) > self::MAX_LENGTH) {
            throw ValidationException::withMessages([
                'code' => ['No free code is left under this parent within 20 characters; type a code manually.'],
            ]);
        }

        return $candidate;
    }

    /**
     * Run $insert with the supplied code, or with a suggested one (retrying when a
     * concurrent request took the same code first).
     *
     * @template T
     *
     * @param  Closure(string): T  $insert
     * @return T
     */
    public function create(?string $supplied, ?Account $parent, string $type, Closure $insert): mixed
    {
        $supplied = trim((string) $supplied);
        if ($supplied !== '') {
            return $insert($supplied);
        }

        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(fn () => $insert($this->suggest($parent, $type)));
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt >= 5) {
                    throw $e;
                }
            }
        }
    }

    /** @param array<string, int> $taken */
    private function underParent(Account $parent, array $taken): string
    {
        $numeric = Account::query()->where('parent_id', $parent->id)->pluck('code')
            ->map(fn ($code) => (string) $code)
            ->filter(fn (string $code) => $this->isNumeric($code))
            ->sortBy(fn (string $code) => (int) $code)
            ->values();

        if ($numeric->isEmpty()) {
            $base = (string) $parent->code;
            for ($n = 1; $n < 100; $n++) {
                $candidate = $base.sprintf('%02d', $n);
                if (! isset($taken[$candidate])) {
                    return $candidate;
                }
            }

            return $base.'100';
        }

        $last = (string) $numeric->last();
        $step = $numeric->count() >= 2 ? (int) $last - (int) $numeric[$numeric->count() - 2] : 1;

        return str_pad((string) ((int) $last + max(1, $step)), strlen($last), '0', STR_PAD_LEFT);
    }

    /** @param array<string, int> $taken */
    private function root(string $type, array $taken): string
    {
        $roots = Account::query()->whereNull('parent_id')->where('type', $type)->pluck('code')
            ->map(fn ($code) => (string) $code)
            ->filter(fn (string $code) => $this->isNumeric($code));

        if ($roots->isEmpty()) {
            return (string) (self::TYPE_BASE[$type] ?? 1000);
        }

        $max = (int) $roots->map(fn (string $code) => (int) $code)->max();

        return (string) ((intdiv($max, 100) + 1) * 100);
    }

    private function isNumeric(string $code): bool
    {
        return preg_match('/^\d{1,'.self::MAX_DIGITS.'}$/', $code) === 1;
    }

    private function increment(string $code): string
    {
        if ($this->isNumeric($code)) {
            return str_pad((string) ((int) $code + 1), strlen($code), '0', STR_PAD_LEFT);
        }

        return $code.'1';
    }
}
