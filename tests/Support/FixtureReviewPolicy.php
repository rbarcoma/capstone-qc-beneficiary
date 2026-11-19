<?php

namespace Tests\Support;

use App\Services\RecommendationPolicy;

/** Adapter for the pre-activation workflow fixtures. Never changes evaluation. */
final class FixtureReviewPolicy extends RecommendationPolicy
{
    public static function install(): void
    {
        SyntheticRecommendationEvaluator::guard();
        app()->instance(RecommendationPolicy::class, new self);
    }

    public function reviewContract(?array $activation): array
    {
        $hash = config('recommendations.policy_fingerprint');
        if (is_string($hash) && preg_match('/^[a-f0-9]{64}$/D', $hash)) {
            return ['fingerprint' => $hash, 'rules' => ['R1', 'R2', 'R3', 'R4', 'R5', 'R6', 'R7']];
        }

        return parent::reviewContract($activation);
    }
}
