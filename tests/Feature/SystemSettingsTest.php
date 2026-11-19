<?php

namespace Tests\Feature;

use App\Models\Building;
use App\Models\MonthlyRecord;
use App\Models\User;
use App\Services\ForecastEligibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Process;
use Tests\Support\ForecastData;
use Tests\TestCase;

class SystemSettingsTest extends TestCase
{
    use ForecastData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-15 12:00:00', 'UTC'));
    }

    public function test_only_admin_and_energy_manager_can_view_system_configuration(): void
    {
        Process::fake();
        $this->get('/system-settings')->assertRedirect('/login');
        foreach (['admin', 'energy_manager', 'facilities_personnel', 'budget_officer', 'viewer'] as $role) {
            $user = User::factory()->role($role)->create();
            $response = $this->actingAs($user)->get('/system-settings');
            if (in_array($role, ['admin', 'energy_manager'], true)) {
                $response->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertInertia(fn ($page) => $page->component('system-settings')->has('buildings', 0)->where('selectedBuilding', null));
            } else {
                $response->assertForbidden();
                $this->assertDatabaseHas('audit_logs', ['actor_id' => $user->id, 'event' => 'authorization.denied', 'reason' => 'GET system-settings']);
            }
        }
        Process::assertNothingRan();
    }

    public function test_explicit_allowlist_contains_real_technical_guard_and_no_fabricated_policy_defaults_or_secrets(): void
    {
        $actor = User::factory()->admin()->create();
        config(['forecasting.python' => '/private/SECRET-runtime', 'database.connections.mysql.password' => 'SECRET-db', 'mail.mailers.smtp.password' => 'SECRET-mail']);
        $response = $this->actingAs($actor)->get('/system-settings')->assertOk()->assertInertia(fn ($page) => $page
            ->where('configuration.minimumObservations', ForecastEligibility::MINIMUM_OBSERVATIONS)->where('configuration.primaryModel', 'Linear Regression')
            ->where('configuration.ruleGenerationEnabled', false)->where('configuration.tariffPhpPerKwh', null)->where('configuration.monthlyTargetKwh', null)
            ->where('configuration.changeReviewPercent', null)->where('configuration.pvCapacityKwp', null)->where('configuration.pvPerformanceRatio', null)
            ->where('configuration.weatherSource', 'NASA POWER')->has('configuration.weatherParameters', 4)->has('configuration', 14));
        $response->assertDontSee('SECRET');
        $this->assertNull(config('recommendations.policy_fingerprint'));
    }

    public function test_building_selection_uses_real_complete_verified_period_and_minimal_metadata(): void
    {
        $f = $this->forecastFixture(3);
        $f['building']->update(['notes' => 'PRIVATE-BUILDING-NOTES']);
        MonthlyRecord::where('month', '2025-07-01')->update(['verification_status' => 'draft', 'version' => 2]);
        Building::factory()->create(['name' => 'Removed building'])->delete();
        $response = $this->actingAs($f['user'])->get('/system-settings?building='.$f['building']->code)->assertOk()->assertInertia(fn ($page) => $page
            ->has('buildings', 1)->has('buildings.0', 3)->where('selectedBuilding', $f['building']->code)->where('latestVerifiedPeriod', '2025-06-01'));
        $response->assertDontSee('PRIVATE-BUILDING-NOTES')->assertDontSee($f['meters'][0]->meralco_account_ref);
        $this->getJson('/system-settings?building=NOT_REGISTERED')->assertUnprocessable();
    }

    public function test_settings_cannot_save_activate_policy_or_replace_personal_account_settings(): void
    {
        $actor = User::factory()->admin()->create();
        $this->actingAs($actor);
        foreach (['postJson', 'patchJson', 'putJson', 'deleteJson'] as $method) {
            $this->$method('/system-settings', ['threshold' => 15, 'tariff' => 10])->assertMethodNotAllowed();
        }
        $this->get('/settings')->assertRedirect('/settings/profile');
        $this->get('/settings/profile')->assertOk();
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertDatabaseCount('forecasts', 0);
        $this->assertNull(config('recommendations.policy_fingerprint'));
        config(['recommendations.policy_fingerprint' => hash('sha256', 'not approval')]);
        $this->get('/system-settings')->assertInertia(fn ($page) => $page->where('configuration.ruleGenerationEnabled', false)
            ->where('configuration.policyState', 'Review required — a configuration value is not an activation'));
    }

    public function test_fresh_role_is_checked_after_admin_or_manager_revocation(): void
    {
        foreach (['admin', 'energy_manager'] as $role) {
            $actor = User::factory()->role($role)->create();
            $this->actingAs($actor);
            User::whereKey($actor->id)->update(['role' => 'viewer']);
            $this->get('/system-settings')->assertForbidden();
        }
    }
}
