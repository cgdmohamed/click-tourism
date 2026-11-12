<?php

namespace App\Http\Controllers\Api;

use App\Modules\Core\Models\AuditEvent;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Services\AuditService;
use App\Modules\Core\Services\BranchScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * GAP-CORE-02: browse and export the audit trail.
 *
 * The trail was write-only for operators. This endpoint exposes the existing
 * append-only `audit_events` rows with actor/action/entity/date/branch filters,
 * scoped to the caller's branches unless they hold `branches.view_all`.
 */
class AuditEventController extends ApiController
{
    private const EXPORT_LIMIT = 50000;

    public function index(Request $request): JsonResponse
    {
        $events = $this->filtered($request)
            ->with(['actor', 'branch'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        $events->getCollection()->transform(fn (AuditEvent $event) => $event->toApiArray());

        return $this->paginated($events);
    }

    public function export(Request $request, AuditService $audit): Response
    {
        $query = $this->filtered($request);
        $count = (clone $query)->count();

        if ($count > self::EXPORT_LIMIT) {
            throw ValidationException::withMessages([
                'from' => 'The export is limited to 50,000 rows; narrow the filters.',
            ]);
        }

        $events = $query
            ->with(['actor', 'branch'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        $rows = [[
            'ID', 'Created at', 'Action', 'Actor', 'Entity type', 'Entity id',
            'Branch', 'IP', 'Request id', 'Before', 'After',
        ]];

        foreach ($events as $event) {
            $rows[] = [
                $event->id,
                $event->created_at?->toIso8601String(),
                $event->action,
                $event->actor?->name,
                $event->entity_type,
                $event->entity_id,
                $event->branch?->name_en ?: $event->branch?->name_ar,
                $event->ip,
                $event->request_id,
                $event->before === null ? '' : json_encode($event->before, JSON_UNESCAPED_UNICODE),
                $event->after === null ? '' : json_encode($event->after, JSON_UNESCAPED_UNICODE),
            ];
        }

        $handle = fopen('php://temp', 'r+');
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        $audit->log('audit.exported', null, null, [
            'filters' => $this->filters($request),
            'rows' => count($events),
        ]);

        return response("\xEF\xBB\xBF".$csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="audit-events.csv"',
            'Cache-Control' => 'no-store',
        ]);
    }

    /** @return Builder<AuditEvent> */
    private function filtered(Request $request): Builder
    {
        $request->validate([
            'actor' => ['nullable', 'string', 'max:255'],
            'action' => ['nullable', 'string', 'max:100'],
            'entity_type' => ['nullable', 'string', 'max:100'],
            'entity_id' => ['nullable', 'integer', 'min:1'],
            'branch' => ['nullable', 'string', 'max:40'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $user = $request->user();
        $allowed = app(BranchScope::class)->allowedBranchIds($user);

        $query = AuditEvent::query();

        if ($branchPublicId = $request->query('branch')) {
            $branch = Branch::query()->where('public_id', $branchPublicId)->first();

            if (! $branch || ! in_array($branch->id, $allowed, true)) {
                throw new AuthorizationException('You are not assigned to the requested branch.');
            }

            $query->where('branch_id', $branch->id);
        } elseif (! $user->can('branches.view_all')) {
            $query->whereIn('branch_id', $allowed);
        }

        if ($actor = $request->query('actor')) {
            $query->whereHas('actor', function (Builder $query) use ($actor) {
                $query->where('public_id', $actor)
                    ->orWhere('name', 'like', "%{$actor}%")
                    ->orWhere('email', 'like', "%{$actor}%");
            });
        }

        if ($action = $request->query('action')) {
            $query->where('action', 'like', $action.'%');
        }

        if ($entityType = $request->query('entity_type')) {
            $query->where('entity_type', $entityType);
        }

        if ($entityId = $request->query('entity_id')) {
            $query->where('entity_id', (int) $entityId);
        }

        if ($from = $request->query('from')) {
            $query->where('created_at', '>=', Carbon::parse($from)->startOfDay());
        }

        if ($to = $request->query('to')) {
            $query->where('created_at', '<=', Carbon::parse($to)->endOfDay());
        }

        return $query;
    }

    private function perPage(Request $request): int
    {
        return min(100, max(1, (int) $request->query('per_page', 50)));
    }

    /** @return array<string, mixed> */
    private function filters(Request $request): array
    {
        return array_filter([
            'actor' => $request->query('actor'),
            'action' => $request->query('action'),
            'entity_type' => $request->query('entity_type'),
            'entity_id' => $request->query('entity_id'),
            'branch' => $request->query('branch'),
            'from' => $request->query('from'),
            'to' => $request->query('to'),
        ], fn ($value) => $value !== null && $value !== '');
    }
}
