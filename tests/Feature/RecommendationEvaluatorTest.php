<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\DssConfiguration;
use App\Models\MonthlyRecord;
use App\Models\Recommendation;
use App\Models\User;
use App\Services\RecommendationEvaluator;
use App\Services\RecommendationPolicy;
use App\Services\ReportSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\Support\ReviewedDssData;
use Tests\TestCase;

class RecommendationEvaluatorTest extends TestCase
{
    use RefreshDatabase, ReviewedDssData {
        ReviewedDssData::beforeRefreshingDatabase insteadof RefreshDatabase;
    }

    private string $url;

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = $this->forecastFixture(multi: true);
        $this->reviewer = User::factory()->admin()->create();
        $this->url = route('decision-support.evaluate', $this->f['building']);
    }

    private function activation(array $rules, DssConfiguration ...$rows): array
    {
        return ['building' => $this->f['building']->code, 'from' => '2026-09-01', 'to' => '2026-09-01',
            'authorization' => 'Fictional integration test', 'rules' => $rules,
            'bindings' => array_map(fn ($row) => [...$row->only(['id', 'version', 'kind', 'subject_key']), 'hash' => $row->approved_hash], $rows)];
    }

    public function test_http_evaluation_persists_scoped_evidence_and_retries_preserve_decisions(): void
    {
        foreach ($this->f['meters'] as $meter) {
            MonthlyRecord::where('meter_id', $meter->id)->update(['billed_demand_kw' => 10]);
            MonthlyRecord::where('meter_id', $meter->id)->whereDate('month', '2026-08-01')->update(['billed_demand_kw' => 15]);
        }
        $this->issue();
        $cat = $this->configuration('R5-01', kind: 'catalogue');
        $activation = $this->activation(['R1', 'R2', 'R3', 'R4', 'R5'], $this->configuration('R1'), $this->configuration('R1', kind: 'tariff'),
            $this->configuration('R2', ['operator' => 'gte', 'threshold' => '5']), $this->configuration('R3', ['measure' => 'historical_consumption_difference_kwh']),
            $this->configuration('R4'), $this->configuration('R5'), $cat, $this->configuration('R5-01', ['catalogue_configuration_id' => $cat->id], 'review'));
        $this->actingAs($this->reviewer)->post($this->url, ['activation' => $activation])->assertSessionHasErrors('evaluation');
        $this->assertDatabaseCount('recommendations', 0);
        config(['recommendations.activations' => [$activation]]);
        $this->get('/decision-support')->assertInertia(fn ($p) => $p->where('evaluation.available', true)->where('evaluation.tariffActive', true)->where('evaluation.canEvaluate', true));
        $this->post($this->url)->assertSessionHasNoErrors()->assertSessionHas('decisionResult', 'Evaluation complete: 6 new, 0 previously evaluated.');
        $this->assertSame('2100.00', Recommendation::where('rule_code', 'R1')->sole()->evidence['value']);
        $this->assertStringContainsString('+5%', Recommendation::where('rule_code', 'R2')->sole()->message);
        $this->assertSame('80', Recommendation::where('rule_code', 'R3')->sole()->evidence['value']);
        foreach ($this->f['meters'] as $m) {
            $this->assertSame('50', Recommendation::where('subject_key', 'meter:'.$m->id)->sole()->evidence['value']);
        }
        $this->assertDatabaseCount('actions', 0);
        $item = Recommendation::where('subject_key', 'catalogue:R5-01')->sole();
        $this->actingAs($this->f['user'])->post(route('recommendations.decide', $item), ['decision' => 'accepted', 'version' => 1, 'note' => 'Fixture review',
            'owner_id' => $this->f['user']->id, 'due_date' => now()->addWeek()->toDateString()])->assertSessionHasNoErrors();
        $this->post($this->url)->assertSessionHasNoErrors()->assertSessionHas('decisionResult', 'Evaluation complete: 0 new, 6 previously evaluated.');
        $this->assertDatabaseCount('recommendations', 6);
        $this->assertDatabaseCount('actions', 1);
        $this->assertSame('accepted', $item->fresh()->decision);
        $this->assertSame(6, AuditLog::where('event', 'recommendation.evaluated')->count());
        $this->assertSame(2, AuditLog::where('event', 'recommendation.evaluation')->count());
        $facilities = User::factory()->role('facilities_personnel')->create();
        $facilities->buildings()->attach($this->f['building']);
        $this->actingAs($facilities)->get('/decision-support')->assertInertia(fn ($p) => $p->where('evaluation.canEvaluate', false)->where('evaluation.tariffActive', false)
            ->has('recommendations.data', 5)->where('counts.evaluated', 5))->assertDontSee('ratePhpPerKwh');
        $this->assertCount(5, app(ReportSnapshot::class)->read($this->f['building'], $facilities)['recommendations']);
        $this->transition($cat, $this->reviewer, 'revoke');
        $this->assertSame([], app(ReportSnapshot::class)->read($this->f['building'], $facilities)['recommendations']);
        $this->get('/decision-support')->assertInertia(fn ($p) => $p->where('evaluation.available', false)->where('counts.evaluated', 0)->has('recommendations.data', 5));
    }

    public function test_conflicting_malformed_out_of_scope_or_changed_activations_fail_closed(): void
    {
        $rule = $this->configuration('R2');
        $valid = $this->activation(['R2'], $rule);
        $policy = app(RecommendationPolicy::class);
        $read = fn () => $policy->resolve($this->f['building'], '2026-09-01', $this->f['user']);
        $past = [...$valid, 'from' => '2026-01-01', 'to' => '2026-08-01'];
        config(['recommendations.activations' => [$past, $valid]]);
        $this->assertSame('active', $read()['state']);
        $binding = $valid['bindings'][0];
        foreach ([[$valid, $valid], [$past], [[...$valid, 'building' => 'Different']], [[...$valid, 'from' => '2026-00-01']], [[...$valid, 'authorization' => ' ']],
            [[...$valid, 'rules' => [['R2']]]], [[...$valid, 'rules' => ['R6']]], [[...$valid, 'rules' => ['R7']]], [[...$valid, 'bindings' => []]],
            [[...$valid, 'bindings' => [$binding, $binding]]], [[...$valid, 'bindings' => [[...$binding, 'hash' => str_repeat('b', 64)]]]],
            [[...$valid, 'bindings' => [[...$binding, 'version' => 2]]]], [$this->activation(['R1'], $this->configuration('R1'))],
            [$this->activation(['R5'], $this->configuration('R5'), $this->configuration('R5-02', kind: 'catalogue'))]] as $entries) {
            config(['recommendations.activations' => $entries]);
            $this->assertNull($read()['fingerprint']);
        }
        config(['recommendations.activations' => [$valid]]);
        $this->configuration('R2', approve: false);
        $this->assertNull($read()['fingerprint']); // Even a later draft invalidates the pin; no cached fallback.
    }

    public function test_default_bare_fingerprint_stale_source_and_role_restrictions_block_requests(): void
    {
        $this->post($this->url)->assertRedirect('/login');
        foreach (['budget_officer', 'viewer', 'facilities_personnel'] as $role) {
            $this->actingAs(User::factory()->role($role)->create())->post($this->url)->assertForbidden();
        }
        $restricted = User::factory()->admin()->create();
        $restricted->moduleRestrictions()->create(['module_key' => 'decision_support']);
        $this->actingAs($restricted)->post($this->url)->assertForbidden();
        $this->actingAs($this->f['user']);
        $rule = $this->configuration('R2');
        $this->issue();
        $this->post($this->url)->assertSessionHasErrors('evaluation');
        $activation = $this->activation(['R2'], $rule);
        config(['recommendations.activations' => [$activation]]);
        $this->post($this->url)->assertSessionHasNoErrors();
        $item = Recommendation::sole();
        config(['recommendations.activations' => [], 'recommendations.policy_fingerprint' => $item->policy_fingerprint]);
        $decision = ['decision' => 'deferred', 'note' => 'Fixture', 'version' => 1];
        $this->post($this->url)->assertSessionHasErrors('evaluation');
        $this->post(route('recommendations.decide', $item), $decision)->assertSessionHasErrors('recommendation');
        config(['recommendations.activations' => [$activation]]);
        MonthlyRecord::firstOrFail()->update(['verification_status' => 'draft', 'version' => 2]);
        $this->post($this->url)->assertSessionHasErrors('evaluation');
        $this->post(route('recommendations.decide', $item), $decision)->assertSessionHasErrors('recommendation');
        $this->assertDatabaseCount('recommendation_decisions', 0);
        $this->assertDatabaseCount('actions', 0);
    }

    public function test_null_demand_and_strict_equality_do_not_trigger_and_audit_failure_rolls_back(): void
    {
        $this->issue();
        $rule = $this->configuration('R2', ['threshold' => '5']);
        $demand = $this->configuration('R4', ['operator' => 'lte']);
        config(['recommendations.activations' => [$this->activation(['R2', 'R4'], $rule, $demand)]]);
        $this->actingAs($this->f['user'])->post($this->url)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('recommendations', 0);
        $this->assertSame(0, AuditLog::where('event', 'recommendation.evaluation')->sole()->after['created']);
        config(['recommendations.activations' => [$this->activation(['R2'], $this->configuration('R2'))]]);
        Event::listen('eloquent.creating: '.AuditLog::class, function (AuditLog $log) {
            if ($log->event === 'recommendation.evaluation') {
                throw new RuntimeException('Audit unavailable');
            }
        });
        try {
            app(RecommendationEvaluator::class)->evaluate($this->f['building'], $this->f['user']);
            $this->fail('Unaudited evaluation succeeded');
        } catch (RuntimeException $e) {
            $this->assertSame('Audit unavailable', $e->getMessage());
        } finally {
            Event::forget('eloquent.creating: '.AuditLog::class);
        }
        $this->assertDatabaseCount('recommendations', 0);
        $this->assertSame(0, AuditLog::where('event', 'recommendation.evaluated')->count());
    }

    public function test_effective_pins_require_intact_content_independent_review_and_tariff_confirmation(): void
    {
        $policy = app(RecommendationPolicy::class);
        $read = fn () => $policy->resolve($this->f['building'], '2026-09-01', $this->f['user']);
        $rule = $this->configuration('R1');
        $tariff = $this->configuration('R1', kind: 'tariff');
        config(['recommendations.activations' => [$this->activation(['R1'], $rule, $tariff)]]);
        $this->assertSame('active', $read()['state']);
        DssConfiguration::whereKey($tariff->id)->update(['source_confirmation' => null]);
        $this->assertNull($read()['fingerprint']);
        $rule = $this->configuration('R3', through: '2026-08-01');
        config(['recommendations.activations' => [$this->activation(['R3'], $rule)]]);
        $this->assertNull($read()['fingerprint']);
        $rule = $this->configuration('R3');
        config(['recommendations.activations' => [$this->activation(['R3'], $rule)]]);
        $this->assertSame('active', $read()['state']);
        DssConfiguration::whereKey($rule->id)->update(['reviewer_id' => $rule->author_id]);
        $this->assertNull($read()['fingerprint']);
        DssConfiguration::whereKey($rule->id)->update(['reviewer_id' => $rule->reviewer_id, 'payload' => [...$rule->payload, 'threshold' => '-1']]);
        $this->assertNull($read()['fingerprint']);
    }
}
