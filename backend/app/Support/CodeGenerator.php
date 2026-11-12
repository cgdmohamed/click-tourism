<?php

namespace App\Support;

use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Next readable code for master data that has no branch/year of its own
 * (EMP-0001, CC-001, BR-01, ...). Document numbers keep using SequenceService.
 *
 * The next value is max(existing numeric suffix) + 1, so codes typed earlier
 * (or imported) are never reused and gaps left by deletes are not refilled.
 * Race safety: the whole "pick a code and insert" step runs in a transaction
 * holding a PostgreSQL advisory lock for that table/column, and a unique
 * violation (the schema's unique index is the final authority) is retried.
 */
final class CodeGenerator
{
    private const ATTEMPTS = 5;

    /**
     * Insert a row with the caller's supplied code, or with a generated one
     * when the supplied code is blank. $insert receives the final code.
     *
     * @template T
     *
     * @param  Closure(string): T  $insert
     * @return T
     */
    public static function create(?string $supplied, string $table, string $column, string $prefix, int $width, Closure $insert): mixed
    {
        $supplied = $supplied === null ? '' : trim($supplied);
        if ($supplied !== '') {
            return DB::transaction(fn () => $insert($supplied));
        }

        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(fn () => $insert(self::next($table, $column, $prefix, $width)));
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt >= self::ATTEMPTS) {
                    throw $e;
                }
            }
        }
    }

    /** The next free code, e.g. next('employees', 'internal_code', 'EMP', 4) => EMP-0001. */
    public static function next(string $table, string $column, string $prefix, int $width): string
    {
        $prefix = strtoupper($prefix);

        if (DB::getDriverName() === 'pgsql' && DB::transactionLevel() > 0) {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ["codegen:{$table}.{$column}"]);
        }

        $taken = [];
        $max = 0;
        foreach (DB::table($table)->whereRaw("upper({$column}) like ?", [$prefix.'-%'])->pluck($column) as $existing) {
            $existing = strtoupper((string) $existing);
            $taken[$existing] = true;
            if (preg_match('/^'.preg_quote($prefix, '/').'-(\d{1,9})$/', $existing, $m) === 1) {
                $max = max($max, (int) $m[1]);
            }
        }

        $number = $max + 1;
        do {
            $code = sprintf('%s-%0'.$width.'d', $prefix, $number++);
        } while (isset($taken[$code]));

        return $code;
    }
}
