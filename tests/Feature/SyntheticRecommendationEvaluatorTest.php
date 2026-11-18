<?php

namespace Tests\Feature;

use App\Models\Action;
use App\Models\AuditLog;
use App\Models\DssConfiguration;
use App\Models\MonthlyRecord;
use App\Models\Recommendation;
use App\Models\User;
use App\Services\BuildingDataset;
use App\Services\DssConfigurationWorkflow;
use App\Services\ForecastIssuance;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\ForecastData;
use Tests\Support\SyntheticRecommendationEvaluator;
use Tests\TestCase;

class SyntheticRecommendationEvaluatorTest extends TestCase
{
    use ForecastData, RefreshDatabase;

    private array $f;

    private User $reviewer;

    private SyntheticRecommendationEvaluator $engine;

    protected function beforeRefreshingDatabase(): void
    {
        SyntheticRecommendationEvaluator::guard();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = $this->forecastFixture(multi: true);
        $this->reviewer = User::factory()->admin()->create();
        $this->engine = new SyntheticRecommendationEvaluator;
    }

    private function issue(): int
    {
        app(BuildingDataset::class)->build($this->f['building'], $this->f['user']);

        return app(ForecastIssuance::class)->issue($this->f['building'], $this->f['user'])['forecast']->id;
    }

    private function configuration(string $key, array $overrides = [], string $kind = 'rule', string $through = '2026-09-01', bool $approve = true): DssConfiguration
    {
        $workflow = app(DssConfigurationWorkflow::class);
        $payload = ['title' => 'Fictional test case', 'rationale' => SyntheticRecommendationEvaluator::FIXTURE,
            'reference_method' => 'Synthetic boundary exercise only', 'mode' => 'conditional_proposal', 'measure' => DssConfigurationWorkflow::MEASURES[$key][0] ?? null,
            'operator' => 'gt', 'threshold' => '0', 'priority' => 'low', 'source_reference' => 'Fictional energy-only source',
            'source_document_hash' => hash('sha256', 'synthetic'), 'tariff_basis' => 'energy_only', 'rate_php_per_kwh' => '2.5',
            'included_charges' => 'Fixture energy', 'excluded_charges' => 'All other charges', 'conditions' => 'Fixture case review',
            'limitations' => 'Synthetic only', 'responsible_office' => 'Fixture office', 'scope' => 'Fixture building', 'operational_evidence' => 'Fictional observation'];
        $item = $workflow->draft($this->f['building'], $this->f['user'], ['kind' => $kind, 'subject_key' => $key,
            'previous_version' => (int) DssConfiguration::where('building_id', $this->f['building']->id)->where('kind', $kind)->where('subject_key', $key)->max('version'),
            'valid_from' => '2026-01-01', 'valid_to' => $through, 'payload' => [...$payload, ...$overrides]]);
        if ($approve) {
            $this->transition($item, $this->f['user'], 'submit');
            if ($kind === 'tariff') {
                $this->transition($item, User::factory()->role('budget_officer')->create(), 'confirm_source');
            }
            $this->transition($item, $this->reviewer, 'approve');
        }

        return $item->fresh();
    }

    private function transition(DssConfiguration $item, User $actor, string $operation): void
    {
        $item->refresh();
        app(DssConfigurationWorkflow::class)->transition($item, $actor, ['operation' => $operation, 'state_version' => $item->state_version,
            'content_hash' => $item->content_hash, 'reference' => 'Synthetic review']);
    }

    private function evaluate(?array $solar = null): array
    {
        return $this->engine->evaluate($this->f['building'], $this->f['user'], $solar);
    }

    public function test_numeric_results_use_reviewed_versions_and_independent_meter_and_catalogue_subjects(): void
    {
        foreach ($this->f['meters'] as $meter) {
            MonthlyRecord::where('meter_id', $meter->id)->update(['billed_demand_kw' => 10]);
            MonthlyRecord::where('meter_id', $meter->id)->whereDate('month', '2026-08-01')->update(['billed_demand_kw' => 15]);
        }
        $run = $this->issue();
        $this->configuration('R1', kind: 'tariff');
        $this->configuration('R1', ['operator' => 'gte', 'threshold' => '2100']);
        $this->configuration('R2', ['measure' => 'forecast_change_kwh', 'operator' => 'gte', 'threshold' => '40']);
        $this->configuration('R3', ['measure' => 'historical_consumption_difference_kwh', 'operator' => 'lte', 'threshold' => '80']);
        $this->configuration('R4', ['measure' => 'meter_demand_difference_kw', 'operator' => 'abs_gt', 'threshold' => '4']);
        $this->configuration('R5');
        foreach (['R5-01', 'R5-02'] as $key) {
            $entry = $this->configuration($key, kind: 'catalogue');
            $this->configuration($key, ['catalogue_configuration_id' => $entry->id], 'review');
        }
        $this->configuration('R6');
        $this->configuration('R7');
        $first = $this->evaluate(['expected_forecast_id' => $run, 'reduction_percent' => 0, 'capacity_kwp' => '2.5', 'performance_ratio' => '0.8']);
        $this->assertSame(240.0, $first['solar']['solar']['generationKwh']);
        $this->assertSame('current_forecast', $first['readiness']['state']);
        $this->assertCount(7, $first['ids']);
        $this->assertEqualsCanonicalizing($first['ids'], $this->evaluate()['ids']);
        $this->assertDatabaseCount('recommendations', 7);
        $this->assertSame(7, AuditLog::where('event', 'recommendation.synthetic_evaluated')->count());
        $this->assertDatabaseCount('actions', 0);
        foreach (['R1' => '2100.0', 'R2' => '40', 'R3' => '80'] as $code => $value) {
            $item = Recommendation::where('rule_code', $code)->sole();
            $this->assertSame($value, $item->evidence['value']);
        }
        $this->assertTrue((bool) Recommendation::where('rule_code', 'R1')->sole()->contains_cost_data);
        foreach ($this->f['meters'] as $meter) {
            $item = Recommendation::where('subject_key', 'meter:'.$meter->id)->sole();
            $this->assertSame('5', $item->evidence['value']);
            $this->assertSame($meter->id, $item->subject_snapshot['meterId']);
        }
        $this->assertEqualsCanonicalizing(['catalogue:R5-01', 'catalogue:R5-02'], Recommendation::where('rule_code', 'R5')->pluck('subject_key')->all());
        $item = Recommendation::where('subject_key', 'catalogue:R5-02')->sole();
        $decision = ['decision' => 'accepted', 'note' => 'Fictional decision', 'version' => 1, 'owner_id' => $this->reviewer->id, 'due_date' => now()->addWeek()->toDateString()];
        try {
            $this->engine->decide($item, $this->reviewer, $decision);
            $this->fail('Admin must not decide');
        } catch (AuthorizationException) {
            $this->assertNull(config('recommendations.policy_fingerprint'));
        }
        $this->engine->decide($item, $this->f['user'], $decision);
        try {
            $this->engine->decide($item->fresh(), $this->f['user'], [...$decision, 'version' => 2]);
            $this->fail('A final decision cannot create another action');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('recommendation', $e->errors());
        }
        $this->assertSame($item->id, Action::sole()->recommendation_id);
        $this->assertEqualsCanonicalizing($first['ids'], $this->evaluate()['ids']);
        $this->assertSame('accepted', $item->fresh()->decision);
        $this->assertDatabaseCount('recommendation_decisions', 1);
        $this->actingAs($this->f['user'])->get('/action-tracker')->assertInertia(fn ($p) => $p->where('actions.data.0.subjectKey', 'catalogue:R5-02'));
        $this->assertNull(config('recommendations.policy_fingerprint'));
    }

    public function test_superseding_one_catalogue_version_withholds_only_its_subject_even_on_the_same_run(): void
    {
        $this->issue();
        $this->configuration('R5');
        foreach (['R5-01', 'R5-02'] as $key) {
            $catalogue = $this->configuration($key, kind: 'catalogue');
            $this->configuration($key, ['catalogue_configuration_id' => $catalogue->id], 'review');
        }
        $this->assertCount(2, $this->evaluate()['ids']);
        $unaffected = Recommendation::where('subject_key', 'catalogue:R5-01')->sole();
        $this->configuration('R5-02', kind: 'catalogue');
        $this->assertSame([$unaffected->id], $this->evaluate()['ids']);
        $this->assertDatabaseCount('recommendations', 2);
        $this->assertDatabaseCount('actions', 0);
    }

    public function test_missing_inputs_non_trigger_equality_expiry_and_unapproved_dependencies_produce_no_advice(): void
    {
        $this->configuration('R1'); // No tariff: never use historical bills as a substitute.
        $this->configuration('R2', ['threshold' => '5']); // Exactly 5%, strict >.
        $this->configuration('R3', through: '2026-08-01');
        $this->configuration('R4', ['operator' => 'lte', 'threshold' => '10']); // Null meter demand is not zero.
        $this->configuration('R5');
        $this->configuration('R5-01', kind: 'catalogue'); // No approved case review.
        $result = $this->evaluate();
        $this->assertSame('eligible_not_issued', $result['readiness']['state']);
        $this->assertSame([], $result['ids']);
        $this->issue();
        $this->assertSame([], $this->evaluate()['ids']);
        $this->assertDatabaseCount('recommendations', 0);
    }

    public function test_latest_draft_tampering_revocation_and_stale_forecast_withhold_recommendations_and_decisions(): void
    {
        $this->issue();
        $rule = $this->configuration('R2');
        $rows = $this->evaluate()['ids'];
        $this->assertCount(1, $rows);
        $this->transition($rule, $this->reviewer, 'revoke');
        $this->assertSame([], $this->evaluate()['ids']);
        try {
            $this->engine->decide(Recommendation::findOrFail($rows[0]), $this->f['user'], ['version' => 1, 'decision' => 'rejected', 'note' => 'Revoked fixture']);
            $this->fail('Revoked policy cannot decide');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('recommendation', $e->errors());
        }
        $this->configuration('R2');
        $this->configuration('R2', approve: false);
        $this->configuration('R1', kind: 'tariff');
        $cost = $this->configuration('R1');
        DssConfiguration::whereKey($cost->id)->update(['approved_hash' => str_repeat('a', 64)]);
        $this->assertSame([], $this->evaluate()['ids']);
        $this->configuration('R3');
        $this->assertCount(1, $this->evaluate()['ids']);
        MonthlyRecord::where('meter_id', $this->f['meters'][0]->id)->whereDate('month', '2026-08-01')->update(['verification_status' => 'draft', 'version' => 2]);
        $result = $this->evaluate();
        $this->assertFalse($result['readiness']['eligible']);
        $this->assertSame([], $result['ids']);
        $this->assertDatabaseCount('recommendations', 2); // Retained as history.
        $this->assertDatabaseCount('actions', 0);
    }

    public function test_evaluation_requires_isolation_and_a_fresh_authorized_actor(): void
    {
        foreach (['budget_officer', 'viewer', 'facilities_personnel'] as $role) {
            try {
                $this->engine->evaluate($this->f['building'], User::factory()->role($role)->create());
                $this->fail('Unauthorized evaluator');
            } catch (HttpException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
        }
        $this->f['user']->moduleRestrictions()->create(['module_key' => 'decision_support']);
        try {
            $this->evaluate();
            $this->fail('Module denied');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->app->instance('env', 'local');
        try {
            $this->expectException(\LogicException::class);
            $this->expectExceptionMessage('isolated testing database');
            $this->evaluate();
        } finally {
            $this->app->instance('env', 'testing');
        }
    }
}
