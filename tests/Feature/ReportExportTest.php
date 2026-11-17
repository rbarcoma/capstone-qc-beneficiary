<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Building;
use App\Models\MonthlyRecord;
use App\Models\User;
use App\Services\BuildingDataset;
use App\Services\ForecastIssuance;
use App\Services\ReportDownload;
use App\Services\ReportRenderer;
use App\Services\ReportSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use RuntimeException;
use Tests\Support\ActionData;
use Tests\TestCase;

class ReportExportTest extends TestCase
{
    use ActionData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-15 12:00:00', 'UTC'));
    }

    public function test_workspace_is_scoped_and_guest_downloads_are_denied(): void
    {
        $building = Building::factory()->create();
        $this->get('/reports')->assertRedirect('/login');
        $this->post('/reports/'.$building->id.'/summary')->assertRedirect('/login');
        foreach (['admin', 'energy_manager', 'budget_officer', 'viewer', 'facilities_personnel'] as $role) {
            $actor = User::factory()->role($role)->create();
            $this->actingAs($actor)->get('/reports')->assertOk()->assertHeader('Cache-Control', 'no-store, private')
                ->assertInertia(fn ($page) => $page->component('reports')->has('buildings', $role === 'facilities_personnel' ? 0 : 1)
                    ->missing('report.billing')->missing('report.forecast')->missing('report.openActions'));
        }
        $this->assertDatabaseMissing('audit_logs', ['event' => 'report.downloaded']);
    }

    public function test_real_pdf_and_excel_downloads_are_internal_scoped_and_audited(): void
    {
        $f = $this->forecastFixture(3);
        MonthlyRecord::where('meter_id', $f['meters'][0]->id)->update(['cost_php' => 1234.50, 'billed_demand_kw' => 10, 'power_factor' => 0.9]);
        app(BuildingDataset::class)->build($f['building'], $f['user']);
        $snapshot = app(ReportSnapshot::class)->read($f['building'], $f['user']);
        $fingerprint = app(ReportSnapshot::class)->fingerprint($snapshot);
        $this->actingAs($f['user']);
        $pdf = $this->postJson('/reports/'.$f['building']->id.'/summary', compact('fingerprint'))->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
        $this->assertStringContainsString('/Type /Page', $pdf->getContent());
        $this->assertGreaterThan(5000, strlen($pdf->getContent()));
        $xlsx = $this->postJson('/reports/'.$f['building']->id.'/billing', compact('fingerprint'))->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $book = $this->workbook($xlsx->getContent());
        $this->assertSame('INTERNAL', $book->getSheetByName('Summary')->getCell('B1')->getValue());
        $billing = $book->getSheetByName('Verified billing');
        $this->assertSame(4, $billing->getHighestRow());
        $this->assertSame(100.0, $billing->getCell('D2')->getValue());
        $this->assertSame(10.0, $billing->getCell('E2')->getValue());
        $this->assertEquals(0.9, $billing->getCell('F2')->getValue());
        $this->assertEquals(1234.5, $billing->getCell('H2')->getValue());
        $this->assertStringNotContainsString($f['meters'][0]->meralco_account_ref, json_encode($book->getAllSheets()[0]->toArray()));
        $book->disconnectWorksheets();
        $audit = AuditLog::where('event', 'report.downloaded')->latest('id')->firstOrFail();
        $this->assertSame(hash('sha256', $xlsx->getContent()), $audit->after['sha256']);
        $this->assertSame(strlen($xlsx->getContent()), $audit->after['bytes']);
        $this->assertSame($fingerprint, $audit->after['snapshot_fingerprint']);
        $this->assertSame(3, $audit->after['billing_rows']);
        $this->assertSame($f['user']->id, $audit->actor_id);
        $this->assertSame(2, AuditLog::where('event', 'report.downloaded')->count());
        $this->assertNull(config('recommendations.policy_fingerprint'));
    }

    public function test_current_issued_forecast_metrics_and_evidence_map_exact_worker_fields(): void
    {
        $f = $this->forecastFixture();
        $run = app(ForecastIssuance::class)->issue($f['building'], $f['user'])['forecast']->fresh();
        $before = $run->getRawOriginal();
        $snapshot = app(ReportSnapshot::class)->read($f['building'], $f['user']);
        $file = app(ReportDownload::class)->generate($f['building'], $f['user'], 'evidence', app(ReportSnapshot::class)->fingerprint($snapshot));
        $book = $this->workbook($file['bytes']);
        $metrics = $book->getSheetByName('Model metrics');
        $actual = $metrics->rangeToArray('A2:E3', null, true, false);
        $expected = [];
        foreach ($run->worker_result['metrics'] as $method => $values) {
            $expected[] = [$method, $values['mae'], $values['rmse'], $values['r2'], $values['test_months']];
        }
        // XLSX serializes numeric cells at Excel's decimal precision.
        $this->assertEqualsWithDelta($expected, $actual, 1e-9);
        $evidence = $book->getSheetByName('Walk-forward evidence');
        foreach ($run->worker_result['evidence'] as $i => $row) {
            $this->assertEqualsWithDelta([$row['month'], $row['actual_kwh'], $row['linear_kwh'], $row['baseline_kwh'], $row['train_end_month'], $row['training_rows']],
                $evidence->rangeToArray('A'.($i + 2).':F'.($i + 2), null, true, false)[0], 1e-9);
        }
        $this->assertNull($book->getSheetByName('Verified billing'));
        $book->disconnectWorksheets();
        $this->assertSame($before, $run->fresh()->getRawOriginal());
        $this->assertSame($run->id, AuditLog::where('event', 'report.downloaded')->firstOrFail()->forecast_id);
    }

    public function test_facilities_exports_remove_cost_and_private_account_fields(): void
    {
        $f = $this->forecastFixture(2);
        MonthlyRecord::query()->update(['cost_php' => 987654.32]);
        app(BuildingDataset::class)->build($f['building'], $f['user']);
        $actor = User::factory()->role('facilities_personnel')->create();
        $actor->buildings()->attach($f['building']);
        $report = app(ReportSnapshot::class)->read($f['building'], $actor);
        $this->assertArrayNotHasKey('costPhp', $report['rows'][0]);
        $this->assertArrayNotHasKey('costPhp', $report['billing'][0]);
        $this->assertArrayNotHasKey('costMomPercent', $report['rows'][0]);
        $file = app(ReportDownload::class)->generate($f['building'], $actor, 'billing', app(ReportSnapshot::class)->fingerprint($report));
        $book = $this->workbook($file['bytes']);
        $cells = [];
        foreach ($book->getAllSheets() as $sheet) {
            $cells[] = $sheet->toArray();
        }
        $text = json_encode($cells, JSON_THROW_ON_ERROR);
        foreach (['987654.32', 'Actual cost PHP', $f['meters'][0]->meralco_account_ref, 'uploaded_by', 'notes'] as $private) {
            $this->assertStringNotContainsString($private, $text);
        }
        $book->disconnectWorksheets();
        $this->assertFalse(AuditLog::where('event', 'report.downloaded')->firstOrFail()->after['can_view_cost']);
        $other = Building::factory()->create();
        $this->actingAs($actor)->postJson('/reports/'.$other->id.'/summary', ['fingerprint' => str_repeat('0', 64)])->assertForbidden();
        $this->assertDatabaseHas('audit_logs', ['event' => 'authorization.denied', 'reason' => 'report.download']);
        $this->assertSame(1, AuditLog::where('event', 'report.downloaded')->count());
    }

    public function test_corrected_draft_or_partial_sources_cannot_export_stale_evidence(): void
    {
        $f = $this->forecastFixture();
        app(ForecastIssuance::class)->issue($f['building'], $f['user']);
        $reader = app(ReportSnapshot::class);
        $old = $reader->fingerprint($reader->read($f['building'], $f['user']));
        MonthlyRecord::firstOrFail()->update(['verification_status' => 'draft', 'version' => 2]);
        $this->actingAs($f['user'])->postJson('/reports/'.$f['building']->id.'/billing', ['fingerprint' => $old])->assertUnprocessable()->assertJsonValidationErrors('report');
        $current = $reader->read($f['building'], $f['user']);
        $this->assertTrue($current['stale']);
        $this->assertSame([], $current['rows']);
        $this->assertSame([], $current['billing']);
        $this->assertNull($current['forecast']);
        $fingerprint = $reader->fingerprint($current);
        foreach (['billing', 'evidence'] as $type) {
            $this->postJson('/reports/'.$f['building']->id.'/'.$type, compact('fingerprint'))->assertUnprocessable();
        }
        $this->assertDatabaseMissing('audit_logs', ['event' => 'report.downloaded']);
        app(BuildingDataset::class)->build($f['building'], $f['user']);
        $this->assertCount(15, $reader->read($f['building'], $f['user'])['billing']);
    }

    public function test_policy_disabled_advice_is_absent_but_retained_open_accepted_actions_remain_labeled(): void
    {
        $f = $this->actionFixture();
        $reader = app(ReportSnapshot::class);
        $report = $reader->read($f['building'], $f['user']);
        $this->assertSame([], $report['recommendations']);
        $this->assertStringContainsString('C5', $report['recommendationState']);
        $this->assertSame($f['action']->id, $report['openActions'][0]['id']);
        MonthlyRecord::firstOrFail()->update(['verification_status' => 'draft', 'version' => 2]);
        $historical = $reader->read($f['building'], $f['user']);
        $this->assertNull($historical['forecast']);
        $this->assertSame($report['openActions'], $historical['openActions']);
        $f['action']->update(['status' => 'completed', 'version' => 2, 'completed_at' => now()]);
        $this->assertSame([], $reader->read($f['building'], $f['user'])['openActions']);
        $this->assertNull(config('recommendations.policy_fingerprint'));
    }

    public function test_render_time_source_change_is_rejected_before_audit_or_response(): void
    {
        $f = $this->forecastFixture(2);
        $fingerprint = app(ReportSnapshot::class)->fingerprint(app(ReportSnapshot::class)->read($f['building'], $f['user']));
        $this->mock(ReportRenderer::class)->shouldReceive('render')->once()->andReturnUsing(function () {
            MonthlyRecord::firstOrFail()->update(['kwh' => 999, 'version' => 2]);

            return 'rendered bytes';
        });
        $this->actingAs($f['user'])->postJson('/reports/'.$f['building']->id.'/billing', compact('fingerprint'))->assertUnprocessable()->assertJsonValidationErrors('report');
        $this->assertDatabaseMissing('audit_logs', ['event' => 'report.downloaded']);
    }

    public function test_render_time_access_revocation_prevents_release(): void
    {
        $f = $this->forecastFixture(2);
        $actor = User::factory()->role('facilities_personnel')->create();
        $actor->buildings()->attach($f['building']);
        $fingerprint = app(ReportSnapshot::class)->fingerprint(app(ReportSnapshot::class)->read($f['building'], $actor));
        $this->mock(ReportRenderer::class)->shouldReceive('render')->once()->andReturnUsing(function () use ($actor) {
            $actor->buildings()->detach();

            return 'must not be released';
        });
        $this->actingAs($actor)->postJson('/reports/'.$f['building']->id.'/billing', compact('fingerprint'))->assertForbidden();
        $this->assertDatabaseMissing('audit_logs', ['event' => 'report.downloaded']);
    }

    public function test_renderer_or_audit_failure_never_returns_a_successful_download(): void
    {
        $f = $this->forecastFixture(2);
        $reader = app(ReportSnapshot::class);
        $fingerprint = $reader->fingerprint($reader->read($f['building'], $f['user']));
        $this->mock(ReportRenderer::class)->shouldReceive('render')->once()->andThrow(new RuntimeException('Renderer unavailable'));
        try {
            app(ReportDownload::class)->generate($f['building'], $f['user'], 'billing', $fingerprint);
            $this->fail('Renderer failure released a report.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Renderer unavailable', $exception->getMessage());
        }
        $this->app->instance(ReportRenderer::class, new ReportRenderer);
        Event::listen('eloquent.creating: '.AuditLog::class, function (AuditLog $audit) {
            if ($audit->event === 'report.downloaded') {
                throw new RuntimeException('Audit unavailable');
            }
        });
        try {
            app(ReportDownload::class)->generate($f['building'], $f['user'], 'billing', $fingerprint);
            $this->fail('Audit failure released a report.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Audit unavailable', $exception->getMessage());
        } finally {
            Event::forget('eloquent.creating: '.AuditLog::class);
        }
        $this->assertDatabaseMissing('audit_logs', ['event' => 'report.downloaded']);
    }

    public function test_spreadsheet_text_is_not_executable_and_null_r2_is_not_zero(): void
    {
        $f = $this->forecastFixture();
        $run = app(ForecastIssuance::class)->issue($f['building'], $f['user'])['forecast'];
        $report = app(ReportSnapshot::class)->read($f['building'], $f['user']);
        $report['building']['name'] = '=HYPERLINK("https://example.invalid")';
        $report['forecast']['metrics']['linear_regression']['r2'] = null;
        $book = $this->workbook(app(ReportRenderer::class)->render($report, 'evidence'));
        $cell = $book->getSheetByName('Summary')->getCell('B2');
        $this->assertSame(DataType::TYPE_STRING, $cell->getDataType());
        $this->assertSame($report['building']['name'], $cell->getValue());
        $this->assertNull($book->getSheetByName('Model metrics')->getCell('D2')->getValue());
        $book->disconnectWorksheets();
        $report['building']['name'] = '<script>alert(1)</script>';
        $html = view('reports.summary', compact('report'))->render();
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('Undefined', $html);
        $this->assertSame($run->id, $report['forecast']['id']);
    }

    public function test_invalid_types_fingerprints_and_empty_evidence_do_not_create_download_audits(): void
    {
        $building = Building::factory()->create();
        $actor = User::factory()->admin()->create();
        $this->actingAs($actor)->postJson('/reports/'.$building->id.'/budget', [])->assertNotFound();
        $this->get('/reports/'.$building->id.'/summary')->assertMethodNotAllowed();
        $this->postJson('/reports/'.$building->id.'/summary', ['fingerprint' => []])->assertUnprocessable();
        $report = app(ReportSnapshot::class)->read($building, $actor);
        $fingerprint = app(ReportSnapshot::class)->fingerprint($report);
        foreach (['billing', 'evidence'] as $type) {
            $this->postJson('/reports/'.$building->id.'/'.$type, compact('fingerprint'))->assertUnprocessable();
        }
        $this->assertDatabaseMissing('audit_logs', ['event' => 'report.downloaded']);
        $this->postJson('/reports/'.$building->id.'/summary', compact('fingerprint'))->assertOk();
    }

    public function test_old_engine_runs_are_not_current_report_evidence(): void
    {
        $f = $this->forecastFixture();
        $run = app(ForecastIssuance::class)->issue($f['building'], $f['user'])['forecast'];
        config(['forecasting.python' => '/changed/runtime']);
        $report = app(ReportSnapshot::class)->read($f['building'], $f['user']);
        $this->assertNull($report['forecast']);
        $this->assertCount(16, $report['billing']);
        $this->assertSame('420.000', $run->fresh()->predicted_kwh);
    }

    public function test_current_policy_recommendations_are_scoped_and_cost_marked_advice_is_excluded_for_facilities(): void
    {
        $f = $this->actionFixture();
        $policy = $f['recommendation']->policy_fingerprint;
        config(['recommendations.policy_fingerprint' => $policy]);
        $actor = User::factory()->role('facilities_personnel')->create();
        $actor->buildings()->attach($f['building']);
        $reader = app(ReportSnapshot::class);
        $this->assertCount(1, $reader->read($f['building'], $actor)['recommendations']);
        $f['recommendation']->update(['contains_cost_data' => true]);
        $report = $reader->read($f['building'], $actor);
        $this->assertSame([], $report['recommendations']);
        $this->assertSame([], $report['openActions']);
        $this->assertCount(1, $reader->read($f['building'], $f['user'])['recommendations']);
        config(['recommendations.policy_fingerprint' => null]);
        $this->assertSame([], $reader->read($f['building'], $f['user'])['recommendations']);
    }

    public function test_cost_permission_change_during_render_requires_refresh(): void
    {
        $f = $this->forecastFixture(2);
        $actor = User::factory()->role('budget_officer')->create();
        $actor->buildings()->attach($f['building']);
        $reader = app(ReportSnapshot::class);
        $fingerprint = $reader->fingerprint($reader->read($f['building'], $actor));
        $this->mock(ReportRenderer::class)->shouldReceive('render')->once()->andReturnUsing(function () use ($actor) {
            User::whereKey($actor->id)->update(['role' => 'facilities_personnel']);

            return 'cost must not be released';
        });
        $this->actingAs($actor)->postJson('/reports/'.$f['building']->id.'/billing', compact('fingerprint'))->assertUnprocessable()->assertJsonValidationErrors('report');
        $this->assertDatabaseMissing('audit_logs', ['event' => 'report.downloaded']);
    }

    private function workbook(string $bytes): Spreadsheet
    {
        $path = tempnam(sys_get_temp_dir(), 'qc-report-');
        if ($path === false) {
            throw new RuntimeException('Cannot allocate test file.');
        }
        try {
            file_put_contents($path, $bytes);

            return IOFactory::load($path);
        } finally {
            unlink($path);
        }
    }
}
