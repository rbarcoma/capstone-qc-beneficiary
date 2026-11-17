<?php

namespace App\Services;

use App\Models\Action;
use App\Models\Building;
use App\Models\Dataset;
use App\Models\Recommendation;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ReportSnapshot
{
    /** @return array<string, mixed> */
    public function read(Building $building, User $user, ?string $generatedAt = null): array
    {
        return DB::transaction(function () use ($building, $user, $generatedAt) {
            $user = User::findOrFail($user->id);
            $building = Building::findOrFail($building->id);
            Gate::forUser($user)->authorize('view', $building);
            $analytics = app(BuildingAnalytics::class)->read($building, $user);
            $forecast = app(ForecastRead::class)->read($building, $user)['current'];
            $rows = array_map(fn ($row) => $analytics['canViewCost'] ? $row : Arr::except($row, ['costPhp', 'costMomPercent']), $analytics['rows']);
            $billing = [];
            $dataset = $analytics['dataset'] ? Dataset::where('id', $analytics['dataset']['id'])->firstOrFail() : null;
            foreach ($dataset?->records()->orderBy('month')->get() ?? [] as $record) {
                foreach ($record->source_snapshot as $source) {
                    $bill = ['month' => $record->month->toDateString(), 'recordId' => $source['id'], 'meterId' => $source['meter_id'],
                        'kwh' => (float) $source['kwh'], 'billedDemandKw' => $source['billed_demand_kw'] === null ? null : (float) $source['billed_demand_kw'],
                        'powerFactor' => $source['power_factor'] === null ? null : (float) $source['power_factor'], 'version' => $source['version']];
                    if ($analytics['canViewCost']) {
                        $bill['costPhp'] = $source['cost_php'] === null ? null : (float) $source['cost_php'];
                    }
                    $billing[] = $bill;
                }
            }
            $policy = app(RecommendationReadiness::class);
            $recommendations = [];
            if ($policy->policyAvailable() && $forecast !== null) {
                $recommendations = Recommendation::where('forecast_id', $forecast['id'])->where('source_fingerprint', $dataset?->fingerprint)
                    ->where('policy_fingerprint', config('recommendations.policy_fingerprint'))->where('evaluation_status', 'evaluated')
                    ->whereIn('rule_code', ['R1', 'R2', 'R3', 'R4', 'R5', 'R6', 'R7'])
                    ->when(! $analytics['canViewCost'], fn ($query) => $query->where('contains_cost_data', false)->where('rule_code', '!=', 'R1'))
                    ->orderBy('id')->get()->map(fn (Recommendation $item) => $item->only(['id', 'rule_code', 'title', 'message', 'priority', 'evidence', 'decision']))->all();
            }
            $actions = Action::query()->forUser($user)->where('status', 'open')
                ->whereHas('recommendation.decisions', fn ($query) => $query->where('to_state', 'accepted'))
                ->whereHas('recommendation.forecast', fn ($query) => $query->where('building_id', $building->id))
                ->with(['owner:id,name', 'recommendation'])->orderBy('id')->get()->map(fn (Action $action) => [
                    'id' => $action->id, 'title' => $action->title, 'owner' => $action->owner_id === null ? 'Former user' : $action->owner->name,
                    'dueDate' => $action->due_date->toDateString(), 'status' => $action->status, 'version' => $action->version,
                    'sourceForecastId' => $action->recommendation->forecast_id,
                ])->all();
            if (count($billing) > 5000 || count($actions) > 500 || count($recommendations) > 500) {
                throw ValidationException::withMessages(['report' => 'This building exceeds the synchronous export capacity (5,000 billing rows / 500 advice or action rows). No partial report was generated.']);
            }

            return ['reportVersion' => 'internal-building-v1', 'classification' => 'INTERNAL', 'building' => $building->only(['id', 'code', 'name']),
                'generatedAt' => $generatedAt ?? now()->toIso8601String(), 'canViewCost' => $analytics['canViewCost'],
                'dataset' => $analytics['dataset'], 'stale' => $analytics['stale'], 'rows' => $rows, 'billing' => $billing,
                'latestPeriod' => $rows === [] ? null : $rows[count($rows) - 1]['month'], 'forecast' => $forecast,
                'recommendations' => $recommendations, 'recommendationState' => $policy->policyAvailable() ? 'No current evaluated recommendations available unless listed.' : 'Unavailable: C5 rule policy is not approved; no rules evaluated.',
                'openActions' => $actions, 'budgetState' => 'Unavailable: approved tariff source and budget cycle are not configured.',
                'notes' => [
                    'Verified, complete-building months from a current processed dataset only. Draft/partial/superseded values are excluded.',
                    'Forecast: current issued next-eligible-month run only; empirical error band is not calibrated confidence.',
                    'Open actions are retained accepted work with historical source references, not current advice or automatically attributed savings.',
                    'Generated snapshot; subsequent source corrections may supersede this report. Internal distribution only.',
                ]];
        });
    }

    /** @param array<string, mixed> $snapshot */
    public function fingerprint(array $snapshot): string
    {
        return DataFingerprint::hash(Arr::except($snapshot, ['generatedAt']));
    }

    public function lockSources(Building $building, User $actor): void
    {
        app(BuildingDataset::class)->inspectLocked($building);
        User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
        DB::table('user_building')->where('user_id', $actor->id)->lockForUpdate()->get();
        $recommendations = Recommendation::whereHas('forecast', fn ($query) => $query->where('building_id', $building->id))->orderBy('id')->lockForUpdate()->get();
        $actions = Action::whereIn('recommendation_id', $recommendations->modelKeys())->orderBy('id')->lockForUpdate()->get();
        User::whereIn('id', $actions->pluck('owner_id')->filter())->orderBy('id')->lockForUpdate()->get();
    }
}
