<?php

namespace App\Modules\CRM\Services;

use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\User;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Models\LeadSource;
use App\Modules\CRM\Models\LeadStage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Pipeline metrics for leads captured in a period (GAP-CRM-05).
 *
 * The report is cohort-based: every lead created inside [from, to] is counted
 * once, using its current stage/status/owner/source. Sums therefore always
 * reconcile with the lead table for the same branch and period.
 */
class PipelineReportService
{
    public function report(?Branch $branch, array $filters = []): array
    {
        [$from, $to] = $this->range($filters);

        $base = Lead::query()
            ->when($branch, fn ($query) => $query->where('branch_id', $branch->id))
            ->whereBetween('created_at', [$from, $to]);

        $statusCounts = (clone $base)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $captured = (int) $statusCounts->sum();
        $open = (int) ($statusCounts['open'] ?? 0);
        $won = (int) ($statusCounts['won'] ?? 0);
        $lost = (int) ($statusCounts['lost'] ?? 0);
        $converted = (clone $base)->whereNotNull('converted_customer_id')->count();
        $unassigned = (clone $base)->whereNull('owner_user_id')->count();

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'branch_id' => $branch?->public_id,
            'totals' => [
                'captured' => $captured,
                'open' => $open,
                'won' => $won,
                'lost' => $lost,
                'converted' => $converted,
                'unassigned' => $unassigned,
                'conversion_rate' => $this->rate($won, $captured),
            ],
            'by_stage' => $this->byStage($base, $captured),
            'by_source' => $this->bySource($base, $captured),
            'by_owner' => $this->byOwner($base),
        ];
    }

    /**
     * @param  Builder<Lead>  $base
     * @return array<int, array<string, mixed>>
     */
    private function byStage($base, int $captured): array
    {
        $counts = (clone $base)
            ->selectRaw('stage_id, COUNT(*) as total')
            ->groupBy('stage_id')
            ->pluck('total', 'stage_id');

        return LeadStage::query()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(function (LeadStage $stage) use ($counts, $captured) {
                $count = (int) ($counts[$stage->id] ?? 0);

                return [
                    'stage' => $stage->code,
                    'name_ar' => $stage->name_ar,
                    'name_en' => $stage->name_en,
                    'is_won' => (bool) $stage->is_won,
                    'is_lost' => (bool) $stage->is_lost,
                    'count' => $count,
                    'share' => $this->rate($count, $captured),
                ];
            })
            ->all();
    }

    /**
     * @param  Builder<Lead>  $base
     * @return array<int, array<string, mixed>>
     */
    private function bySource($base, int $captured): array
    {
        $rows = (clone $base)
            ->selectRaw('source_id, status, COUNT(*) as total')
            ->groupBy('source_id', 'status')
            ->get();

        $sources = LeadSource::query()->orderBy('name_en')->orderBy('id')->get();

        $bySource = $sources
            ->map(function (LeadSource $source) use ($rows) {
                $sourceRows = $rows->where('source_id', $source->id);
                $count = (int) $sourceRows->sum('total');
                $won = (int) $sourceRows->where('status', 'won')->sum('total');

                return [
                    'source' => $source->code,
                    'name_ar' => $source->name_ar,
                    'name_en' => $source->name_en,
                    'count' => $count,
                    'won' => $won,
                    'conversion_rate' => $this->rate($won, $count),
                ];
            })
            ->filter(fn (array $row) => $row['count'] > 0)
            ->sortByDesc('count')
            ->values();

        $unspecified = $rows->whereNull('source_id');
        if ($unspecified->isNotEmpty()) {
            $count = (int) $unspecified->sum('total');
            $won = (int) $unspecified->where('status', 'won')->sum('total');
            $bySource->push([
                'source' => null,
                'name_ar' => 'غير محدد',
                'name_en' => 'Unspecified',
                'count' => $count,
                'won' => $won,
                'conversion_rate' => $this->rate($won, $count),
            ]);
        }

        return $bySource->all();
    }

    /**
     * @param  Builder<Lead>  $base
     * @return array<int, array<string, mixed>>
     */
    private function byOwner($base): array
    {
        $rows = (clone $base)
            ->selectRaw('owner_user_id, status, COUNT(*) as total')
            ->groupBy('owner_user_id', 'status')
            ->get();

        $users = User::query()
            ->whereIn('id', $rows->pluck('owner_user_id')->filter()->unique()->values())
            ->get()
            ->keyBy('id');

        $byOwner = [];

        foreach ($rows as $row) {
            $key = $row->owner_user_id === null ? 'unassigned' : (string) $row->owner_user_id;
            $byOwner[$key] ??= [
                'owner' => null,
                'name' => null,
                'count' => 0,
                'open' => 0,
                'won' => 0,
                'lost' => 0,
                'conversion_rate' => 0.0,
            ];

            $byOwner[$key]['count'] += (int) $row->total;
            if (in_array($row->status, ['open', 'won', 'lost'], true)) {
                $byOwner[$key][$row->status] += (int) $row->total;
            }
        }

        foreach ($byOwner as $key => &$owner) {
            if ($key !== 'unassigned') {
                $user = $users->get((int) $key);
                $owner['owner'] = $user?->public_id;
                $owner['name'] = $user?->name;
            }
            $owner['conversion_rate'] = $this->rate($owner['won'], $owner['count']);
        }
        unset($owner);

        usort($byOwner, function (array $a, array $b) {
            return [$b['count'], $a['name'] ?? ''] <=> [$a['count'], $b['name'] ?? ''];
        });

        return $byOwner;
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function range(array $filters): array
    {
        $period = $filters['period'] ?? 'thisMonth';
        $today = now()->toDateString();

        [$from, $to] = match ($period) {
            'today' => [$today, $today],
            'lastMonth' => [
                now()->subMonthNoOverflow()->startOfMonth()->toDateString(),
                now()->subMonthNoOverflow()->endOfMonth()->toDateString(),
            ],
            'customRange' => [$filters['from'] ?? $today, $filters['to'] ?? $today],
            default => [now()->startOfMonth()->toDateString(), $today],
        };

        return [Carbon::parse($from)->startOfDay(), Carbon::parse($to)->endOfDay()];
    }

    private function rate(int $part, int $total): float
    {
        return $total > 0 ? round($part * 100 / $total, 1) : 0.0;
    }
}
