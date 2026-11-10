<?php

namespace App\Modules\Reports\Services;

use App\Modules\Parties\Models\Customer;
use App\Modules\Parties\Models\Employee;
use App\Modules\Parties\Models\Supplier;
use Illuminate\Support\Facades\DB;

/**
 * Resolves journal-entry source documents and party display names for report
 * drilldowns (Prompt 28). Resolving in batches keeps the ledger query itself
 * untouched and avoids N+1 lookups on paginated statements.
 */
class ReportReferences
{
    /**
     * Source type => [table, human reference column]. Source ids in the journal
     * point at the module's internal id, so the table is the module table.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const SOURCES = [
        'invoice' => ['invoices', 'number'],
        'payment' => ['payments', 'number'],
        'refund' => ['refunds', 'number'],
        'supplier_payment' => ['supplier_payments', 'number'],
        'expense' => ['expenses', 'number'],
        'accounting_document' => ['accounting_documents', 'number'],
        'accounting_schedule' => ['accounting_schedules', 'number'],
        'booking' => ['bookings', 'number'],
        'transfer' => ['transfers', 'number'],
        'fixed_asset' => ['fixed_assets', 'code'],
        'fixed_asset_depreciation_run' => ['fixed_asset_depreciation_runs', 'number'],
    ];

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, array<string, mixed>> lines with source and party fields
     */
    public function enrich(array $lines): array
    {
        $references = $this->resolveSources(array_column($lines, 'source_type'), array_column($lines, 'source_id'));
        $parties = $this->resolveParties(array_column($lines, 'party_type'), array_column($lines, 'party_id'));

        foreach ($lines as $index => $line) {
            $sourceKey = $this->key($line['source_type'] ?? null, $line['source_id'] ?? null);
            $line['source_reference'] = $references[$sourceKey]['reference'] ?? null;
            $line['source_public_id'] = $references[$sourceKey]['public_id'] ?? null;

            $partyKey = $this->key($line['party_type'] ?? null, $line['party_id'] ?? null);
            $line['party'] = $parties[$partyKey] ?? null;

            $lines[$index] = $line;
        }

        return $lines;
    }

    /**
     * @param  array<int, string|null>  $types
     * @param  array<int, int|string|null>  $ids
     * @return array<string, array{reference: ?string, public_id: ?string}>
     */
    public function resolveSources(array $types, array $ids): array
    {
        $grouped = [];
        foreach ($types as $index => $type) {
            $id = $ids[$index] ?? null;
            if (empty($type) || $id === null || $id === '') {
                continue;
            }

            if (! isset(self::SOURCES[$type])) {
                continue;
            }

            $grouped[$type][] = (int) $id;
        }

        $result = [];
        foreach ($grouped as $type => $sourceIds) {
            [$table, $column] = self::SOURCES[$type];
            $rows = DB::table($table)
                ->whereIn('id', array_values(array_unique($sourceIds)))
                ->get(['id', 'public_id', $column]);

            foreach ($rows as $row) {
                $result[$type.':'.$row->id] = [
                    'reference' => (string) ($row->{$column} ?? ''),
                    'public_id' => (string) ($row->public_id ?? ''),
                ];
            }
        }

        return $result;
    }

    /**
     * @param  array<int, string|null>  $types
     * @param  array<int, int|string|null>  $ids
     * @return array<string, array{name_en: ?string, name_ar: ?string, public_id: ?string}>
     */
    public function resolveParties(array $types, array $ids): array
    {
        $grouped = [];
        foreach ($types as $index => $type) {
            $id = $ids[$index] ?? null;
            if (empty($type) || $id === null || $id === '') {
                continue;
            }
            if (! in_array($type, ['customer', 'supplier', 'employee'], true)) {
                continue;
            }
            $grouped[$type][] = (int) $id;
        }

        $models = [
            'customer' => Customer::class,
            'supplier' => Supplier::class,
            'employee' => Employee::class,
        ];

        $result = [];
        foreach ($grouped as $type => $partyIds) {
            $rows = $models[$type]::query()
                ->whereIn('id', array_values(array_unique($partyIds)))
                ->get(['id', 'public_id', 'name_en', 'name_ar']);

            foreach ($rows as $row) {
                $result[$type.':'.$row->id] = [
                    'name_en' => $row->name_en,
                    'name_ar' => $row->name_ar,
                    'public_id' => (string) $row->public_id,
                ];
            }
        }

        return $result;
    }

    private function key(?string $type, int|string|null $id): string
    {
        return ($type ?? '').':'.($id ?? '');
    }
}
