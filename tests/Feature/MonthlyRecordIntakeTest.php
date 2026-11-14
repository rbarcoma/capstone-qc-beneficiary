<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Building;
use App\Models\Meter;
use App\Models\MonthlyRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class MonthlyRecordIntakeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-14 12:00:00'));
    }

    private function meter(string $code = 'HIGHRISE'): Meter
    {
        return Meter::factory()->create(['building_id' => Building::factory()->create(['code' => $code])->id]);
    }

    private function upload(string $csv): TestResponse
    {
        return $this->from('/data-management')->post('/data-management/import', ['csv' => UploadedFile::fake()->createWithContent('monthly.csv', $csv)]);
    }

    public function test_valid_csv_persists_only_drafts_and_audits_every_row(): void
    {
        $meter = $this->meter();
        $user = User::factory()->admin()->create();
        $this->actingAs($user);
        $this->upload("building_code,month,kwh,billed_demand_kw,cost_php,power_factor,notes\nHIGHRISE,2026-08-01,100.125,25.5,1200.25,0.95,Checked bill\nHIGHRISE,2026-09-01,0,,,,\n")
            ->assertRedirect('/data-management')->assertSessionHasNoErrors()->assertSessionHas('intakeResult.importedCount', 2);
        $this->assertDatabaseCount('monthly_records', 2);
        $this->assertDatabaseHas('monthly_records', ['meter_id' => $meter->id, 'month' => '2026-08-01', 'kwh' => '100.125', 'verification_status' => 'draft', 'uploaded_by' => $user->id, 'verified_by' => null]);
        $this->assertSame(0, MonthlyRecord::verified()->count());
        $this->assertDatabaseCount('audit_logs', 2);
        $this->assertSame('monthly_record.uploaded', AuditLog::first()->event);
    }

    public function test_utf8_bom_reordered_headers_and_quoted_multiline_notes_are_supported(): void
    {
        $this->meter();
        $this->actingAs(User::factory()->admin()->create());
        $this->upload("\xEF\xBB\xBF\"month\",kwh,building_code,notes\r\n2026-10-01,10,HIGHRISE,\"Line one, with comma\nLine two and \"\"quotes\"\"\"\r\n")
            ->assertSessionHasNoErrors();
        $this->assertSame("Line one, with comma\nLine two and \"quotes\"", MonthlyRecord::firstOrFail()->notes);
    }

    public function test_bad_headers_empty_files_and_malformed_csv_are_rejected(): void
    {
        $this->meter();
        $this->actingAs(User::factory()->admin()->create());
        foreach (['', "month,kwh\n2026-10-01,10", "building_code,month,kwh,kwh\nHIGHRISE,2026-10-01,10,11", "building_code,month,kwh,weather\nHIGHRISE,2026-10-01,10,30", "building_code,month,kwh,notes\nHIGHRISE,2026-10-01,10,\"Unclosed", "building_code,month,kwh,notes\nHIGHRISE,2026-10-01,10,\"closed\"junk"] as $csv) {
            $this->upload($csv)->assertSessionHasErrors('csv');
        }
        $this->assertDatabaseCount('monthly_records', 0);
    }

    public function test_unequal_field_count_and_invalid_encoding_are_rejected(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $this->upload("building_code,month,kwh\nHIGHRISE,2026-10-01,10,extra")->assertSessionHasErrors('rows.2');
        $this->upload("building_code,month,kwh\nHIGHRISE,2026-10-01,\xFF")->assertSessionHasErrors('csv');
        $this->upload("building_code,month,kwh\nHIGHRISE,2026-10-01,10\0")->assertSessionHasErrors('csv');
    }

    public function test_invalid_row_rejects_the_entire_batch_with_row_level_messages(): void
    {
        $this->meter();
        $this->actingAs(User::factory()->admin()->create());
        $this->upload("building_code,month,kwh\nHIGHRISE,2026-09-01,10\nHIGHRISE,2026-10-01,-5")
            ->assertSessionHasErrors('rows.3.kwh');
        $this->assertDatabaseCount('monthly_records', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_invalid_current_future_and_non_first_day_months_are_rejected(): void
    {
        $this->meter();
        $this->actingAs(User::factory()->admin()->create());
        foreach (['2026-11-01', '2026-12-01', '2026-10-02', '2026-02-30', '2026-2-01'] as $month) {
            $this->upload("building_code,month,kwh\nHIGHRISE,$month,10")->assertSessionHasErrors('rows.2.month');
        }
        $this->assertDatabaseCount('monthly_records', 0);
    }

    public function test_numeric_limits_precision_and_power_factor_range_are_enforced(): void
    {
        $this->meter();
        $this->actingAs(User::factory()->admin()->create());
        foreach (['-1', 'NaN', '1e6', '1000000000000', '1.1234'] as $kwh) {
            $this->upload("building_code,month,kwh\nHIGHRISE,2026-10-01,$kwh")->assertSessionHasErrors('rows.2.kwh');
        }
        $this->upload("building_code,month,kwh,billed_demand_kw,cost_php,power_factor\nHIGHRISE,2026-10-01,1,-1,1.123,1.1")
            ->assertSessionHasErrors(['rows.2.billed_demand_kw', 'rows.2.cost_php', 'rows.2.power_factor']);
        $this->assertDatabaseCount('monthly_records', 0);
    }

    public function test_duplicate_file_rows_and_stored_months_never_overwrite(): void
    {
        $this->meter();
        $this->actingAs(User::factory()->admin()->create());
        $this->upload("building_code,month,kwh\nHIGHRISE,2026-10-01,10\nHIGHRISE,2026-10-01,20")->assertSessionHasErrors('rows.3.month');
        $this->assertDatabaseCount('monthly_records', 0);
        $this->upload("building_code,month,kwh\nHIGHRISE,2026-10-01,10")->assertSessionHasNoErrors();
        $this->upload("building_code,month,kwh\nHIGHRISE,2026-10-01,20")->assertSessionHasErrors('rows.2.month');
        $this->assertSame('10.000', MonthlyRecord::firstOrFail()->kwh);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_missing_history_is_flagged_and_a_late_bill_can_fill_the_gap(): void
    {
        $this->meter();
        $this->actingAs(User::factory()->admin()->create());
        $this->upload("building_code,month,kwh\nHIGHRISE,2026-08-01,10\nHIGHRISE,2026-10-01,30")
            ->assertSessionHasNoErrors()->assertSessionHas('intakeResult.warnings', fn ($warnings) => str_contains($warnings[0], '2026-09'));
        $this->assertDatabaseCount('monthly_records', 2);
        $this->upload("building_code,month,kwh\nHIGHRISE,2026-09-01,20")
            ->assertSessionHasNoErrors()->assertSessionHas('intakeResult.warnings', []);
        $this->assertDatabaseCount('monthly_records', 3);
    }

    public function test_unknown_removed_and_unassigned_buildings_are_rejected_without_partial_writes(): void
    {
        $assigned = $this->meter();
        $this->meter('TREASURY');
        Building::factory()->create(['code' => 'REMOVED'])->delete();
        $user = User::factory()->role('facilities_personnel')->create();
        $user->buildings()->attach($assigned->building_id);
        $this->actingAs($user);
        foreach (['TREASURY', 'UNKNOWN', 'REMOVED'] as $code) {
            $this->upload("building_code,month,kwh\nHIGHRISE,2026-09-01,10\n$code,2026-10-01,20")->assertSessionHasErrors('rows.3.building_code');
        }
        $this->assertDatabaseCount('monthly_records', 0);
    }

    public function test_missing_or_multiple_meters_require_explicit_manual_mapping(): void
    {
        $meter = $this->meter();
        Meter::factory()->create(['building_id' => $meter->building_id]);
        Building::factory()->create(['code' => 'TREASURY']);
        $this->actingAs(User::factory()->admin()->create());
        foreach (['HIGHRISE', 'TREASURY'] as $code) {
            $this->upload("building_code,month,kwh\n$code,2026-10-01,20")->assertSessionHasErrors('rows.2.meter');
        }
        $this->assertDatabaseCount('monthly_records', 0);
        $this->post('/monthly-records', ['meter_id' => $meter->id, 'month' => '2026-10-01', 'kwh' => '25', 'verification_status' => 'verified', 'uploaded_by' => 999])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('monthly_records', ['meter_id' => $meter->id, 'verification_status' => 'draft', 'source' => 'manual']);
    }

    public function test_manual_entry_cannot_target_unassigned_or_removed_meters(): void
    {
        $meter = $this->meter();
        $this->actingAs(User::factory()->role('facilities_personnel')->create())
            ->post('/monthly-records', ['meter_id' => $meter->id])->assertForbidden();
        $meter->delete();
        $this->actingAs(User::factory()->admin()->create())
            ->post('/monthly-records', ['meter_id' => $meter->id])->assertNotFound();
        $this->assertDatabaseCount('monthly_records', 0);
    }

    public function test_route_permissions_for_every_role_and_guests(): void
    {
        foreach (['admin', 'energy_manager', 'facilities_personnel'] as $role) {
            $this->actingAs(User::factory()->role($role)->create())->get('/data-management')->assertOk();
            $this->get('/data-management/template')->assertOk();
        }
        foreach (['viewer', 'budget_officer'] as $role) {
            $this->actingAs(User::factory()->role($role)->create())->get('/data-management')->assertForbidden();
            $this->get('/data-management/template')->assertForbidden();
            $this->upload("building_code,month,kwh\nHIGHRISE,2026-10-01,10")->assertForbidden();
            $this->post('/monthly-records', [])->assertForbidden();
        }
        auth()->logout();
        $this->get('/data-management')->assertRedirect('/login');
        $this->post('/data-management/import', [])->assertRedirect('/login');
    }

    public function test_facilities_can_upload_and_see_only_their_buildings_and_masked_meters(): void
    {
        $meter = $this->meter();
        $other = $this->meter('TREASURY');
        $user = User::factory()->role('facilities_personnel')->create();
        $user->buildings()->attach($meter->building_id);
        $this->actingAs($user);
        $this->upload("building_code,month,kwh\nHIGHRISE,2026-10-01,10")->assertSessionHasNoErrors();
        MonthlyRecord::create(['meter_id' => $other->id, 'month' => '2026-10-01', 'kwh' => 999, 'source' => 'csv']);
        $response = $this->get('/data-management');
        $response->assertInertia(fn ($page) => $page->has('buildings', 1)->has('records.data', 1)->where('records.data.0.kwh', '10.000')->where('latestVerifiedPeriod', null));
        $this->assertStringNotContainsString($meter->meralco_account_ref, $response->getContent());
        $this->assertStringNotContainsString($other->meralco_account_ref, $response->getContent());
        $this->get('/data-management?building=TREASURY')->assertNotFound();
    }

    public function test_template_has_exact_proposal_headers_and_no_example_data(): void
    {
        $response = $this->actingAs(User::factory()->admin()->create())->get('/data-management/template');
        $response->assertDownload('qc-monthly-records-template.csv');
        $this->assertSame("building_code,month,kwh,billed_demand_kw,cost_php,power_factor,notes\n", $response->streamedContent());
    }

    public function test_upload_limits_and_extension_are_enforced(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $this->post('/data-management/import', ['csv' => UploadedFile::fake()->create('large.csv', 2049, 'text/csv')])->assertSessionHasErrors('csv');
        $this->post('/data-management/import', ['csv' => UploadedFile::fake()->createWithContent('wrong.txt', 'building_code,month,kwh')])->assertSessionHasErrors('csv');
        $this->upload("building_code,month,kwh\n".str_repeat("HIGHRISE,2026-10-01,10\n", 1001))->assertSessionHasErrors('csv');
        $this->assertDatabaseCount('monthly_records', 0);
    }

    public function test_a_meter_with_monthly_history_cannot_be_removed(): void
    {
        $meter = $this->meter();
        MonthlyRecord::create(['meter_id' => $meter->id, 'month' => '2026-10-01', 'kwh' => 10, 'source' => 'csv']);
        $this->actingAs(User::factory()->admin()->create())->delete("/meters/{$meter->id}")->assertSessionHasErrors('registry');
        $this->assertNotSoftDeleted($meter);
    }

    public function test_completed_month_boundary_uses_quezon_city_timezone(): void
    {
        $this->meter();
        $this->actingAs(User::factory()->admin()->create());
        $this->travelTo(Carbon::parse('2026-10-31 15:59:00', 'UTC'));
        $this->upload("building_code,month,kwh\nHIGHRISE,2026-10-01,10")->assertSessionHasErrors('rows.2.month');
        $this->travelTo(Carbon::parse('2026-10-31 16:00:00', 'UTC'));
        $this->upload("building_code,month,kwh\nHIGHRISE,2026-10-01,10")->assertSessionHasNoErrors();
    }
}
