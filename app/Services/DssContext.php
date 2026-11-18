<?php

namespace App\Services;

use App\Models\BillingEvidence;
use App\Models\Building;
use App\Models\Meter;
use App\Models\MonthlyRecord;
use App\Models\User;
use App\Models\UtilityService;

class DssContext
{
    public function scopeHash(Building $building): string
    {
        $meters = Meter::where('building_id', $building->id)->orderBy('id')->get(['id', 'building_id', 'status']);

        return DataFingerprint::hash(['building' => $building->only(['id', 'code']), 'meters' => $meters->toArray(),
            'services' => UtilityService::whereIn('meter_id', $meters->modelKeys())->orderBy('id')->get()->map(fn ($service) => $service->getAttributes())->all(),
            'catalogue' => 'r5-review-prototype-v1']);
    }

    /**
     * Allowlisted provenance for R5 reviews, never private bill/source text.
     *
     * @return array<string, mixed>
     */
    public function evidence(Building $building, User $user): array
    {
        $inspection = app(BuildingDataset::class)->inspect($building);
        $forecast = app(ForecastRead::class)->read($building, $user)['current'];
        $records = MonthlyRecord::whereHas('meter', fn ($query) => $query->where('building_id', $building->id))->orderBy('id')->get();

        return ['datasetFingerprint' => $inspection['fingerprint'], 'forecastId' => $forecast['id'] ?? null,
            'records' => $records->map(fn ($record) => $record->only(['id', 'version', 'verification_status']))->all(),
            'billingEvidence' => BillingEvidence::whereIn('monthly_record_id', $records->modelKeys())->orderBy('id')->get()
                ->map(fn ($bill) => ['id' => $bill->id, 'version' => $bill->version, 'state' => $bill->verification_status,
                    'hash' => app(BillingEvidenceMath::class)->fingerprint($bill)])->all()];
    }
}
