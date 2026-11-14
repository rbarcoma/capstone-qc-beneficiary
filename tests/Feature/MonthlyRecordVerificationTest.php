<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Building;
use App\Models\Meter;
use App\Models\MonthlyRecord;
use App\Models\MonthlyRecordRevision;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MonthlyRecordVerificationTest extends TestCase
{
    use RefreshDatabase;

    private function record(?User $uploader = null, ?Meter $meter = null): MonthlyRecord
    {
        $uploader ??= User::factory()->role('facilities_personnel')->create();
        $meter ??= Meter::factory()->create();

        return MonthlyRecord::create([
            'meter_id' => $meter->id, 'month' => '2026-10-01', 'kwh' => '100',
            'billed_demand_kw' => '10', 'cost_php' => '500.25', 'power_factor' => '0.95',
            'notes' => 'Original bill entry', 'source' => 'manual', 'uploaded_by' => $uploader->id,
            'last_edited_by' => $uploader->id,
        ])->refresh();
    }

    public function test_a_different_admin_or_energy_manager_can_verify_with_bill_confirmation(): void
    {
        foreach (['admin', 'energy_manager'] as $role) {
            $record = $this->record();
            $reviewer = User::factory()->role($role)->create();
            $this->actingAs($reviewer)->post("/monthly-records/{$record->id}/verify", ['version' => 1, 'confirmed_against_bill' => true])
                ->assertRedirect()->assertSessionHasNoErrors();
            $this->assertDatabaseHas('monthly_records', ['id' => $record->id, 'verification_status' => 'verified', 'verified_by' => $reviewer->id, 'version' => 2]);
            $this->assertNotNull($record->fresh()->verified_at);
            $this->assertDatabaseHas('audit_logs', ['monthly_record_id' => $record->id, 'actor_id' => $reviewer->id, 'event' => 'monthly_record.verified']);
        }
    }

    public function test_uploader_cannot_self_verify_even_as_admin_or_energy_manager(): void
    {
        foreach (['admin', 'energy_manager'] as $role) {
            $user = User::factory()->role($role)->create();
            $record = $this->record($user);
            $this->actingAs($user)->post("/monthly-records/{$record->id}/verify", ['version' => 1, 'confirmed_against_bill' => true])->assertForbidden();
            $this->assertSame('draft', $record->fresh()->verification_status);
            $this->assertDatabaseHas('audit_logs', ['actor_id' => $user->id, 'event' => 'monthly_record.permission_denied']);
        }
    }

    public function test_verification_requires_bill_confirmation_and_current_version(): void
    {
        $record = $this->record();
        $this->actingAs(User::factory()->admin()->create());
        $this->post("/monthly-records/{$record->id}/verify", ['version' => 1, 'confirmed_against_bill' => false])->assertSessionHasErrors('confirmed_against_bill');
        $this->post("/monthly-records/{$record->id}/verify", ['version' => 999, 'confirmed_against_bill' => true])->assertSessionHasErrors('record');
        $this->assertSame('draft', $record->fresh()->verification_status);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_repeated_verification_does_not_create_duplicate_actions(): void
    {
        $record = $this->record();
        $this->actingAs(User::factory()->admin()->create());
        $this->post("/monthly-records/{$record->id}/verify", ['version' => 1, 'confirmed_against_bill' => true])->assertSessionHasNoErrors();
        $this->post("/monthly-records/{$record->id}/verify", ['version' => 2, 'confirmed_against_bill' => true])->assertSessionHasErrors('record');
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_verification_routes_reject_other_roles_and_audit_permission_denials(): void
    {
        $record = $this->record();
        foreach (['facilities_personnel', 'viewer', 'budget_officer'] as $role) {
            $this->actingAs(User::factory()->role($role)->create());
            $this->get('/verification-queue')->assertForbidden();
            $this->post("/monthly-records/{$record->id}/verify", [])->assertForbidden();
            $this->patch("/monthly-records/{$record->id}/correct", [])->assertForbidden();
            $this->get("/monthly-records/{$record->id}/history")->assertForbidden();
        }
        $this->assertSame(12, AuditLog::where('event', 'authorization.denied')->count());
        $this->assertDatabaseCount('monthly_record_revisions', 0);
        $this->assertSame('draft', $record->fresh()->verification_status);
    }

    public function test_verified_corrections_preserve_values_reset_verification_and_prevent_editor_self_verification(): void
    {
        $record = $this->record();
        $reviewer = User::factory()->admin()->create();
        $this->actingAs($reviewer)->post("/monthly-records/{$record->id}/verify", ['version' => 1, 'confirmed_against_bill' => true])->assertSessionHasNoErrors();
        $this->patch("/monthly-records/{$record->id}/correct", ['version' => 2, 'kwh' => '125.5', 'notes' => 'Corrected from bill', 'reason' => 'Transposed energy digits'])
            ->assertSessionHasNoErrors();
        $current = $record->fresh();
        $this->assertSame('125.500', $current->kwh);
        $this->assertSame('draft', $current->verification_status);
        $this->assertNull($current->verified_by);
        $this->assertNull($current->verified_at);
        $this->assertSame(3, $current->version);
        $revision = MonthlyRecordRevision::firstOrFail();
        $this->assertSame('100.000', $revision->before['kwh']);
        $this->assertSame('verified', $revision->before['verification_status']);
        $this->assertSame('125.500', $revision->after['kwh']);
        $this->assertSame('draft', $revision->after['verification_status']);
        $this->assertSame($reviewer->id, $revision->actor_id);
        $this->assertSame('Transposed energy digits', $revision->reason);
        $this->assertDatabaseHas('audit_logs', ['event' => 'monthly_record.corrected', 'monthly_record_id' => $record->id]);
        $this->post("/monthly-records/{$record->id}/verify", ['version' => 3, 'confirmed_against_bill' => true])->assertForbidden();
        $this->actingAs(User::factory()->role('energy_manager')->create())
            ->post("/monthly-records/{$record->id}/verify", ['version' => 3, 'confirmed_against_bill' => true])->assertSessionHasNoErrors();
    }

    public function test_correction_requires_reason_valid_values_and_rejects_stale_form_versions(): void
    {
        $record = $this->record();
        $this->actingAs(User::factory()->role('energy_manager')->create());
        $this->patch("/monthly-records/{$record->id}/correct", ['version' => 1, 'kwh' => -1])->assertSessionHasErrors(['reason', 'kwh']);
        $this->assertDatabaseCount('monthly_record_revisions', 0);
        $this->patch("/monthly-records/{$record->id}/correct", ['version' => 1, 'kwh' => '105', 'reason' => 'Bill review'])->assertSessionHasNoErrors();
        $this->patch("/monthly-records/{$record->id}/correct", ['version' => 1, 'kwh' => '999', 'reason' => 'Stale copy'])->assertSessionHasErrors('record');
        $this->assertSame('105.000', $record->fresh()->kwh);
        $this->assertDatabaseCount('monthly_record_revisions', 1);
    }

    public function test_correction_cannot_change_meter_source_or_force_verified_status(): void
    {
        $record = $this->record();
        $this->actingAs(User::factory()->admin()->create())->patch("/monthly-records/{$record->id}/correct", [
            'version' => 1, 'kwh' => '105', 'reason' => 'Bill review', 'meter_id' => 999,
            'source' => 'synthetic', 'verification_status' => 'verified', 'verified_by' => 999,
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('monthly_records', ['id' => $record->id, 'meter_id' => $record->meter_id, 'month' => '2026-10-01', 'source' => 'manual', 'verification_status' => 'draft', 'verified_by' => null]);
    }

    public function test_history_is_paginated_preserves_every_revision_and_does_not_expose_account_reference(): void
    {
        $record = $this->record();
        $this->actingAs(User::factory()->admin()->create());
        for ($version = 1; $version <= 11; $version++) {
            $this->patch("/monthly-records/{$record->id}/correct", ['version' => $version, 'kwh' => (string) $version, 'reason' => "Revision $version"])->assertSessionHasNoErrors();
        }
        $response = $this->getJson("/monthly-records/{$record->id}/history");
        $response->assertOk()->assertJsonCount(10, 'data')->assertJsonPath('total', 11);
        $this->assertStringNotContainsString($record->meter->meralco_account_ref, $response->getContent());
        $this->getJson("/monthly-records/{$record->id}/history?page=2")->assertJsonCount(1, 'data');
    }

    public function test_queue_metrics_and_records_filter_to_selected_building(): void
    {
        $meter = Meter::factory()->create(['building_id' => Building::factory()->create(['code' => 'HIGHRISE'])->id]);
        $record = $this->record(meter: $meter);
        $other = $this->record();
        $other->update(['verification_status' => 'verified']);
        $this->actingAs(User::factory()->admin()->create())->get('/verification-queue?building=HIGHRISE')
            ->assertInertia(fn ($page) => $page->where('metrics.draft', 1)->where('metrics.verified', 0)
                ->where('metrics.corrections', 0)->has('records.data', 1)->where('records.data.0.id', $record->id)->where('records.data.0.canVerify', true));
        $this->get('/verification-queue?building=UNKNOWN')->assertNotFound();
    }

    public function test_summary_counts_use_only_verified_periods_and_reflect_corrections(): void
    {
        $meter = Meter::factory()->create(['building_id' => Building::factory()->create(['code' => 'HIGHRISE'])->id]);
        $record = $this->record(meter: $meter);
        $this->actingAs(User::factory()->admin()->create());
        $this->get('/dashboard?building=HIGHRISE')->assertInertia(fn ($page) => $page->where('kpis.pendingVerification', 1)->where('kpis.verifiedMonths', 0)->where('latestVerifiedPeriod', null));
        $this->post("/monthly-records/{$record->id}/verify", ['version' => 1, 'confirmed_against_bill' => true])->assertSessionHasNoErrors();
        $this->get('/dashboard?building=HIGHRISE')->assertInertia(fn ($page) => $page->where('kpis.pendingVerification', 0)->where('kpis.verifiedMonths', 1)->where('latestVerifiedPeriod', '2026-10-01')->where('readiness.0.forecastReady', false));
        $this->get('/buildings-meters')->assertInertia(fn ($page) => $page->where('buildings.0.verifiedMonths', 1)->where('latestVerifiedPeriod', '2026-10-01'));
        $this->patch("/monthly-records/{$record->id}/correct", ['version' => 2, 'kwh' => '90', 'reason' => 'Bill review'])->assertSessionHasNoErrors();
        $this->get('/dashboard?building=HIGHRISE')->assertInertia(fn ($page) => $page->where('kpis.pendingVerification', 1)->where('kpis.verifiedMonths', 0)->where('latestVerifiedPeriod', null));
    }

    public function test_demo_request_changes_no_official_records_audits_or_counts(): void
    {
        $this->record();
        $this->actingAs(User::factory()->admin()->create())->get('/data-management?demo=1')
            ->assertInertia(fn ($page) => $page->where('demoRequested', true)->has('records.data', 1));
        $this->assertDatabaseCount('monthly_records', 1);
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertDatabaseCount('monthly_record_revisions', 0);
    }

    public function test_facilities_verified_counts_and_latest_period_exclude_other_buildings(): void
    {
        $record = $this->record();
        $record->update(['verification_status' => 'verified', 'month' => '2026-09-01']);
        $other = $this->record();
        $other->update(['verification_status' => 'verified', 'month' => '2026-10-01']);
        $user = User::factory()->role('facilities_personnel')->create();
        $user->buildings()->attach($record->meter->building_id);
        $this->actingAs($user)->get('/dashboard')->assertInertia(fn ($page) => $page
            ->has('buildings', 1)->where('kpis.verifiedMonths', 1)
            ->where('latestVerifiedPeriod', '2026-09-01')->has('readiness', 1));
        $this->get('/buildings-meters')->assertInertia(fn ($page) => $page
            ->has('buildings', 1)->where('buildings.0.verifiedMonths', 1)->where('latestVerifiedPeriod', '2026-09-01'));
    }

    public function test_billing_month_corrections_preserve_old_period_and_reject_collisions(): void
    {
        $record = $this->record();
        MonthlyRecord::create(['meter_id' => $record->meter_id, 'month' => '2026-08-01', 'kwh' => '50', 'source' => 'manual']);
        $this->actingAs(User::factory()->admin()->create());
        $this->patch("/monthly-records/{$record->id}/correct", ['version' => 1, 'month' => '2026-08-01', 'kwh' => '100', 'reason' => 'Period correction'])
            ->assertSessionHasErrors('month');
        $this->assertDatabaseCount('monthly_record_revisions', 0);
        $this->patch("/monthly-records/{$record->id}/correct", ['version' => 1, 'month' => '2026-09-01', 'kwh' => '100', 'reason' => 'Period correction'])
            ->assertSessionHasNoErrors();
        $this->assertSame('2026-09-01', $record->fresh()->month->toDateString());
        $this->assertStringStartsWith('2026-10-01', MonthlyRecordRevision::firstOrFail()->before['month']);
        $this->assertStringStartsWith('2026-09-01', MonthlyRecordRevision::firstOrFail()->after['month']);
    }
}
