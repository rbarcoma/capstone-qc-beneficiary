<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Dataset;
use App\Models\Forecast;
use App\Models\MonthlyRecord;
use App\Models\User;
use App\Models\WeatherRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Tests\Support\ForecastData;
use Tests\TestCase;

class MonthlyCycleIntegrationTest extends TestCase
{
    use ForecastData, RefreshDatabase;

    public function test_technical_monthly_cycle_and_correction_retraction_with_c5_disabled_throughout(): void
    {
        $this->travelTo(Carbon::parse('2026-11-15 12:00:00', 'UTC'));
        // Synthetic history/weather in the isolated testing DB only. The live
        // NASA transport is tested separately; this flow performs no network sync.
        $f = $this->forecastFixture(15);
        $augustWeather = WeatherRecord::whereDate('month', '2026-07-01')->firstOrFail()->replicate();
        $augustWeather->month = '2026-08-01';
        $augustWeather->save();
        $facilities = User::factory()->role('facilities_personnel')->create();
        $facilities->buildings()->attach($f['building']);
        $budget = User::factory()->role('budget_officer')->create();
        $viewer = User::factory()->role('viewer')->create();
        $admin = User::factory()->admin()->create();
        $code = $f['building']->code;
        $buildingId = $f['building']->id;
        $this->assertNull(config('recommendations.policy_fingerprint'));

        // Facilities upload -> Draft. Neither forecasts nor receipt acknowledgments
        // can silently verify this new sixteenth month.
        $csv = "building_code,month,kwh\n{$code},2026-08-01,400\n";
        $this->actingAs($facilities)->from('/data-management')->post('/data-management/import', ['csv' => UploadedFile::fake()->createWithContent('synthetic-month.csv', $csv)])
            ->assertRedirect('/data-management')->assertSessionHasNoErrors();
        $record = MonthlyRecord::where('meter_id', $f['meters'][0]->id)->whereDate('month', '2026-08-01')->firstOrFail();
        $this->assertSame('draft', $record->verification_status);
        $this->post('/monthly-records/'.$record->id.'/verify', ['version' => 1, 'confirmed_against_bill' => true])->assertForbidden();
        $this->actingAs($f['user'])->get('/forecasting?building='.$code)->assertInertia(fn ($page) => $page->where('data.current', null)->where('data.canRun', true));
        $notice = $this->get('/notifications')->assertOk()->inertiaProps('notices.data.0');
        $this->assertSame('verification', $notice['kind']);
        $this->post('/notifications/read', ['keys' => [$notice['key']]])->assertSessionHasNoErrors();
        $this->assertSame('draft', $record->fresh()->verification_status);

        // Independent bill confirmation -> current dataset -> real worker issuance.
        $this->post('/monthly-records/'.$record->id.'/verify', ['version' => 1, 'confirmed_against_bill' => true])->assertSessionHasNoErrors();
        $this->get('/notifications')->assertInertia(fn ($page) => $page->has('notices.data', 0));
        $this->post('/preprocessing/build', ['building_id' => $buildingId])->assertSessionHasNoErrors();
        $dataset = Dataset::where('building_id', $buildingId)->latest('version')->firstOrFail();
        $this->assertSame(16, $dataset->rows_count);
        $this->post('/forecasting/issue', ['building_id' => $buildingId, 'expected_fingerprint' => $dataset->fingerprint])->assertSessionHasNoErrors();
        $run = Forecast::firstOrFail();
        $immutableRun = $run->getRawOriginal();
        $this->assertSame('2026-09-01', $run->target_month->toDateString());
        $this->assertSame('420.000', $run->predicted_kwh);
        $this->post('/forecasting/issue', ['building_id' => $buildingId, 'expected_fingerprint' => $dataset->fingerprint])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('forecasts', 1);
        $this->get('/decision-support?building='.$code)->assertInertia(fn ($page) => $page->where('policyAvailable', false)->where('currentForecastId', $run->id)->has('recommendations.data', 0));
        $this->get('/action-tracker')->assertInertia(fn ($page) => $page->where('metrics.tracked', 0));
        $this->assertNull(config('recommendations.policy_fingerprint'));

        // Budget receives only current forecast notices and exports actual evidence;
        // Viewer reads an INTERNAL summary. Tariff/C5 outputs remain unavailable.
        $this->actingAs($budget)->get('/notifications')->assertInertia(fn ($page) => $page->has('notices.data', 1)->where('notices.data.0.kind', 'forecast'));
        $report = $this->get('/reports?building='.$code)->assertOk()->inertiaProps('report');
        $this->assertSame($run->id, $report['forecastId']);
        $this->assertStringContainsString('Unavailable', $report['budgetState']);
        $this->assertStringContainsString('C5', $report['recommendationState']);
        $this->postJson('/reports/'.$buildingId.'/evidence', ['fingerprint' => $report['fingerprint']])->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->actingAs($viewer)->postJson('/reports/'.$buildingId.'/summary', ['fingerprint' => $report['fingerprint']])->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertSame(2, AuditLog::where('event', 'report.downloaded')->count());
        $this->get('/audit-logs')->assertForbidden();

        // Later actual remains Draft until independently verified; the original
        // issued run stays immutable while current error state is reconciled.
        $this->actingAs($facilities)->post('/monthly-records', ['meter_id' => $f['meters'][0]->id, 'month' => '2026-09-01', 'kwh' => '450'])->assertSessionHasNoErrors();
        $actual = MonthlyRecord::where('meter_id', $f['meters'][0]->id)->whereDate('month', '2026-09-01')->firstOrFail();
        $this->assertFalse($run->actualError()->firstOrFail()->valid);
        $this->actingAs($f['user'])->post('/monthly-records/'.$actual->id.'/verify', ['version' => 1, 'confirmed_against_bill' => true])->assertSessionHasNoErrors();
        $error = $run->actualError()->firstOrFail();
        $this->assertTrue($error->valid);
        $this->assertSame('450.000', $error->actual_kwh);
        $this->assertEquals(30, $error->absolute_error);
        $this->get('/model-evaluation?building='.$code.'&run='.$run->id)->assertInertia(fn ($page) => $page->where('data.evaluation.actual.valid', true)->where('data.evaluation.actual.absoluteError', 30));

        // Correction of a training bill retracts the dependent run/error/report.
        $this->patch('/monthly-records/'.$record->id.'/correct', ['version' => 2, 'kwh' => '405', 'reason' => 'Synthetic integration correction to test retraction.'])->assertSessionHasNoErrors();
        $this->assertSame('draft', $record->fresh()->verification_status);
        $this->assertFalse($run->actualError()->firstOrFail()->valid);
        $this->assertSame('training_source_changed', $run->actualError()->firstOrFail()->reason);
        $this->assertSame($immutableRun, $run->fresh()->getRawOriginal());
        $this->assertDatabaseCount('monthly_record_revisions', 1);
        $this->get('/model-evaluation?building='.$code.'&run='.$run->id)->assertInertia(fn ($page) => $page->where('data.evaluation.sourceCurrent', false)->where('data.evaluation.predictionKwh', null));
        $this->actingAs($budget)->postJson('/reports/'.$buildingId.'/evidence', ['fingerprint' => $report['fingerprint']])->assertUnprocessable();
        $this->get('/reports?building='.$code)->assertInertia(fn ($page) => $page->where('report.stale', true)->where('report.forecastId', null)->where('report.billingRows', 0));
        $this->get('/notifications')->assertInertia(fn ($page) => $page->has('notices.data', 0));
        $this->actingAs($admin)->post('/monthly-records/'.$record->id.'/verify', ['version' => 3, 'confirmed_against_bill' => true])->assertSessionHasNoErrors();
        $this->get('/audit-logs?event=report.downloaded')->assertInertia(fn ($page) => $page->where('metrics.downloads', 2));
        foreach (['monthly_record.uploaded', 'monthly_record.verified', 'dataset.built', 'forecast.issued', 'notification.read', 'report.downloaded', 'monthly_record.corrected', 'forecast.actual_reconciled'] as $event) {
            $this->assertTrue(AuditLog::where('event', $event)->exists(), $event.' was not audited.');
        }
        $this->assertDatabaseCount('recommendations', 0);
        $this->assertDatabaseCount('recommendation_decisions', 0);
        $this->assertDatabaseCount('actions', 0);
        $this->assertNull(config('recommendations.policy_fingerprint'));
    }
}
