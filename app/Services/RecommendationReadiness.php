<?php

namespace App\Services;

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
        if (! in_array($recommendation->decision, ['pending', 'deferred'], true)) {
            return 'Accepted and rejected decisions are final; history cannot be overwritten.';
        }

        return null;
    }
}
