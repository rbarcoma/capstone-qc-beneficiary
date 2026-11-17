<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Building;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ReportDownload
{
    /** @return array{bytes: string, filename: string, mime: string} */
    public function generate(Building $building, User $user, string $type, string $expectedFingerprint): array
    {
        abort_unless(in_array($type, ['summary', 'billing', 'evidence'], true), 404);
        $reader = app(ReportSnapshot::class);
        $snapshot = $reader->read($building, $user);
        $fingerprint = $reader->fingerprint($snapshot);
        $this->checkFingerprint($fingerprint, $expectedFingerprint);
        if (($type === 'billing' && $snapshot['billing'] === []) || ($type === 'evidence' && $snapshot['forecast'] === null)) {
            throw ValidationException::withMessages(['report' => 'Current verified billing data or a current issued forecast is required for this export.']);
        }
        $bytes = app(ReportRenderer::class)->render($snapshot, $type);
        if ($bytes === '') {
            throw new \RuntimeException('Report renderer produced no content.');
        }

        return DB::transaction(function () use ($building, $user, $type, $reader, $snapshot, $fingerprint, $bytes) {
            $reader->lockSources($building, $user);
            $actor = User::findOrFail($user->id);
            abort_unless($actor->canAccessModule('reports'), 403);
            $currentBuilding = Building::findOrFail($building->id);
            Gate::forUser($actor)->authorize('view', $currentBuilding);
            $current = $reader->read($currentBuilding, $actor, $snapshot['generatedAt']);
            $this->checkFingerprint($reader->fingerprint($current), $fingerprint);
            $extension = $type === 'summary' ? 'pdf' : 'xlsx';
            // Logs response preparation, not proof that a client received the bytes.
            AuditLog::create(['actor_id' => $actor->id, 'forecast_id' => $snapshot['forecast']['id'] ?? null, 'event' => 'report.downloaded',
                'after' => ['classification' => 'INTERNAL', 'building_id' => $building->id, 'type' => $type, 'format' => $extension,
                    'dataset_id' => $snapshot['dataset']['id'] ?? null, 'dataset_fingerprint' => $snapshot['dataset']['fingerprint'] ?? null,
                    'snapshot_fingerprint' => $fingerprint, 'sha256' => hash('sha256', $bytes), 'bytes' => strlen($bytes),
                    'billing_rows' => count($snapshot['billing']), 'can_view_cost' => $snapshot['canViewCost']]]);

            return ['bytes' => $bytes, 'filename' => 'qc-energy-internal-'.$currentBuilding->code.'-'.$type.'.'.$extension,
                'mime' => $type === 'summary' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
        }, 3);
    }

    private function checkFingerprint(string $actual, string $expected): void
    {
        if (! hash_equals($actual, $expected)) {
            throw ValidationException::withMessages(['report' => 'Report sources or permissions changed. Refresh the Reports page before downloading.']);
        }
    }
}
