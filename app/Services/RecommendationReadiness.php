<?php

namespace App\Services;

use App\Models\DssConfiguration;
use App\Models\Meter;
use App\Models\Recommendation;

class RecommendationReadiness
{
    public function policyAvailable(): bool
    {
        $policy = config('recommendations.policy_fingerprint');

        return is_string($policy) && preg_match('/^[a-f0-9]{64}$/', $policy) === 1;
    }

    public function reason(Recommendation $recommendation, ?int $currentForecastId): ?string
    {
        if (! $this->policyAvailable() || $recommendation->evaluation_status !== 'evaluated'
            || $recommendation->policy_fingerprint !== config('recommendations.policy_fingerprint')
            || ! in_array($recommendation->rule_code, ['R1', 'R2', 'R3', 'R4', 'R5', 'R6', 'R7'], true)) {
            return 'Rule policy unavailable or changed; an evaluated current contract is required.';
        }
        if ($currentForecastId !== $recommendation->forecast_id
            || $recommendation->source_fingerprint !== $recommendation->forecast->dataset_fingerprint) {
            return 'The source is no longer the current issued forecast. Re-evaluation is required.';
        }
        if ($recommendation->subject_key !== 'building') {
            $snapshot = $recommendation->subject_snapshot ?? [];
            if ($recommendation->rule_code === 'R4') {
                $id = $snapshot['meterId'] ?? null;
                if (! is_int($id) || $recommendation->subject_key !== 'meter:'.$id || ! Meter::whereKey($id)
                    ->where('building_id', $recommendation->forecast->building_id)->exists()) {
                    return 'The meter subject is unavailable or belongs to another building.';
                }
            } elseif ($recommendation->rule_code === 'R5') {
                $entry = $snapshot['catalogueEntry'] ?? '';
                $configuration = DssConfiguration::whereKey($snapshot['catalogueConfigurationId'] ?? 0)->first();
                if (! is_string($entry) || ! preg_match('/^R5-0[1-7]$/', $entry) || $recommendation->subject_key !== 'catalogue:'.$entry || ! $configuration
                    || $configuration->building_id !== $recommendation->forecast->building_id || $configuration->kind !== 'catalogue'
                    || $configuration->subject_key !== $entry || $configuration->status !== 'approved'
                    || $configuration->version !== ($snapshot['catalogueVersion'] ?? null)
                    || $configuration->approved_hash !== ($snapshot['catalogueHash'] ?? null)
                    || $configuration->approved_hash !== app(DssConfigurationWorkflow::class)->hash($configuration)
                    || $configuration->scope_hash !== app(DssContext::class)->scopeHash($recommendation->forecast->building)) {
                    return 'The exact independently reviewed catalogue subject/version is unavailable.';
                }
            } else {
                return 'This rule does not support the supplied recommendation subject.';
            }
        }
        if (! in_array($recommendation->decision, ['pending', 'deferred'], true)) {
            return 'Accepted and rejected decisions are final; history cannot be overwritten.';
        }

        return null;
    }
}
