<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Building;
use App\Models\MonthlyRecord;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class NotificationInbox
{
    /**
     * Live source-backed notices, not a claim of queued/email delivery.
     * Roles follow proposal Table12; Admin is not an implicit recipient.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function read(User $user): Collection
    {
        return DB::transaction(function () use ($user) {
            $actor = User::findOrFail($user->id);
            abort_unless($actor->canAccessModule('notifications'), 403);
            $items = collect();
            if (! $actor->hasRole(UserRole::EnergyManager, UserRole::BudgetOfficer)) {
                return $items;
            }
            if ($actor->hasRole(UserRole::EnergyManager)) {
                $records = MonthlyRecord::query()->forUser($actor)->where('verification_status', 'draft')
                    ->where('month', '<', now('Asia/Manila')->startOfMonth()->toDateString())
                    ->with('meter.building')->orderBy('id')->get();
                foreach ($records as $record) {
                    $building = $record->meter->building;
                    $items->push(['key' => DataFingerprint::hash(['kind' => 'verification', 'id' => $record->id, 'version' => $record->version]),
                        'kind' => 'verification', 'title' => 'Month awaiting verification', 'buildingCode' => $building->code, 'buildingName' => $building->name,
                        'month' => $record->month->toDateString(), 'sourceId' => $record->id, 'sourceVersion' => $record->version,
                        'sourceTime' => $record->updated_at->toIso8601String()]);
                }
            }
            foreach (Building::query()->orderBy('id')->get() as $building) {
                if (! $actor->hasBuildingAccess($building)) {
                    continue;
                }
                $forecast = app(ForecastRead::class)->read($building, $actor)['current'];
                if ($forecast === null) {
                    continue;
                }
                $items->push(['key' => DataFingerprint::hash(['kind' => 'forecast', 'id' => $forecast['id']]),
                    'kind' => 'forecast', 'title' => 'Forecast ready', 'buildingCode' => $building->code, 'buildingName' => $building->name,
                    'month' => $forecast['targetMonth'], 'sourceId' => $forecast['id'], 'sourceVersion' => null,
                    'sourceTime' => Carbon::parse($forecast['issuedAt'])->toIso8601String()]);
            }
            $receipts = DB::table('notification_receipts')->where('user_id', $actor->id)->whereIn('notice_key', $items->pluck('key'))->pluck('read_at', 'notice_key');

            return $items->map(fn ($item) => [...$item, 'readAt' => $receipts->has($item['key']) ? Carbon::parse($receipts->get($item['key']))->toIso8601String() : null])
                ->sort(fn ($a, $b) => strcmp($b['sourceTime'], $a['sourceTime']) ?: strcmp($a['key'], $b['key']))->values();
        });
    }

    /** @param list<string> $keys */
    public function markRead(User $user, array $keys): int
    {
        return DB::transaction(function () use ($user, $keys) {
            // Same source-first order as issuance/corrections; mark only fresh,
            // currently authorized identities actually reviewed by the caller.
            foreach (Building::query()->orderBy('id')->lockForUpdate()->get() as $building) {
                app(BuildingDataset::class)->inspectLocked($building);
            }
            $actor = User::lockForUpdate()->findOrFail($user->id);
            abort_unless($actor->canAccessModule('notifications'), 403);
            abort_unless($actor->hasRole(UserRole::EnergyManager, UserRole::BudgetOfficer), 403);
            $current = $this->read($actor)->keyBy('key');
            if (collect($keys)->contains(fn ($key) => ! $current->has($key))) {
                throw ValidationException::withMessages(['notifications' => 'A notice changed or is no longer available to your role. Refresh the inbox before marking it read.']);
            }
            $new = 0;
            foreach (array_unique($keys) as $key) {
                $new += DB::table('notification_receipts')->insertOrIgnore(['user_id' => $actor->id, 'notice_key' => $key, 'read_at' => now()]);
            }
            if ($new > 0) {
                AuditLog::create(['actor_id' => $actor->id, 'event' => 'notification.read', 'after' => ['notice_keys' => array_values(array_unique($keys)), 'new_receipts' => $new]]);
            }

            return $new;
        }, 3);
    }
}
