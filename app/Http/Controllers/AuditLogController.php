<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class AuditLogController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', AuditLog::class);
        $filters = $request->validate([
            'event' => ['nullable', 'string', 'max:255', 'regex:/^[a-z][a-z0-9_.-]*$/'],
            'actor' => ['nullable', 'string', 'regex:/^(unattributed|[1-9][0-9]{0,17})$/'],
            'from' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:9999-12-31'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:9999-12-31', Rule::when($request->filled('from'), ['after_or_equal:from'])],
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
        ]);
        $query = AuditLog::query();
        if (! empty($filters['event'])) {
            $query->where('event', $filters['event']);
        }
        if (($filters['actor'] ?? null) === 'unattributed') {
            $query->whereNull('actor_id');
        } elseif (! empty($filters['actor'])) {
            $query->where('actor_id', $filters['actor']);
        }
        // Local calendar dates are inclusive; audit timestamps are stored in UTC.
        if (! empty($filters['from'])) {
            $query->where('created_at', '>=', Carbon::parse($filters['from'], 'Asia/Manila')->startOfDay()->utc());
        }
        if (! empty($filters['to'])) {
            $query->where('created_at', '<=', Carbon::parse($filters['to'], 'Asia/Manila')->endOfDay()->utc());
        }

        $response = Inertia::render('audit-logs', [
            'filters' => ['event' => $filters['event'] ?? '', 'actor' => $filters['actor'] ?? '', 'from' => $filters['from'] ?? '', 'to' => $filters['to'] ?? ''],
            'eventOptions' => AuditLog::query()->distinct()->orderBy('event')->pluck('event'),
            'actors' => User::query()->whereIn('id', AuditLog::select('actor_id')->whereNotNull('actor_id'))->orderBy('name')->orderBy('id')->get(['id', 'name']),
            'metrics' => ['entries' => (clone $query)->count(), 'corrections' => (clone $query)->where('event', 'monthly_record.corrected')->count(), 'downloads' => (clone $query)->where('event', 'report.downloaded')->count()],
            'entries' => $query->select(['id', 'actor_id', 'event', 'monthly_record_id', 'forecast_id', 'created_at'])->with('actor:id,name')
                ->orderByDesc('created_at')->orderByDesc('id')->paginate(25)->withQueryString()->through(fn (AuditLog $entry) => $this->summary($entry)),
        ])->toResponse($request);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    public function show(AuditLog $auditLog): JsonResponse
    {
        Gate::authorize('view', $auditLog);
        $auditLog->load('actor:id,name');

        return response()->json([
            ...$this->summary($auditLog), 'reason' => $auditLog->reason,
            'before' => $auditLog->before, 'after' => $auditLog->after,
        ])->header('Cache-Control', 'private, no-store');
    }

    /** @return array<string, mixed> */
    private function summary(AuditLog $entry): array
    {
        return ['id' => $entry->id, 'event' => $entry->event, 'createdAt' => $entry->created_at?->toIso8601String(),
            // Names reflect the current account; no historical role was stored.
            'actor' => $entry->actor ? ['id' => $entry->actor->id, 'name' => $entry->actor->name] : null,
            'monthlyRecordId' => $entry->monthly_record_id, 'forecastId' => $entry->forecast_id];
    }
}
