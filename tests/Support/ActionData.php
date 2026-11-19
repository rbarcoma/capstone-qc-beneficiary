<?php

namespace Tests\Support;

use App\Models\Action;
use App\Models\Building;
use App\Models\Forecast;
use App\Models\Recommendation;
use App\Models\User;
use App\Services\ForecastIssuance;
use App\Services\RecommendationWorkflow;

trait ActionData
{
    use ForecastData;

    /** @return array{action: Action, recommendation: Recommendation, forecast: Forecast, user: User, building: Building} */
    private function actionFixture(?User $owner = null): array
    {
        $f = $this->forecastFixture();
        $forecast = app(ForecastIssuance::class)->issue($f['building'], $f['user'])['forecast'];
        // Synthetic evaluator contract for test setup only, never production policy.
        $policy = hash('sha256', 'synthetic action lifecycle fixture contract');
        FixtureReviewPolicy::install();
        config(['recommendations.policy_fingerprint' => $policy]);
        $recommendation = Recommendation::create(['forecast_id' => $forecast->id, 'rule_code' => 'R5', 'policy_fingerprint' => $policy,
            'source_fingerprint' => $forecast->dataset_fingerprint, 'title' => 'Synthetic accepted action', 'message' => 'Test fixture, not official advice.',
            'evidence' => ['fixture' => true, 'forecast_kwh' => 420], 'evaluation_status' => 'evaluated']);
        try {
            app(RecommendationWorkflow::class)->decide($recommendation, $f['user'], ['version' => 1, 'decision' => 'accepted', 'note' => 'Synthetic acceptance for lifecycle tests.',
                'owner_id' => ($owner ?? $f['user'])->id, 'due_date' => now('Asia/Manila')->addWeek()->toDateString()]);
        } finally {
            config(['recommendations.policy_fingerprint' => null]);
        }

        return ['action' => $recommendation->action()->firstOrFail(), 'recommendation' => $recommendation->fresh(), 'forecast' => $forecast->fresh(), 'user' => $f['user'], 'building' => $f['building']];
    }
}
