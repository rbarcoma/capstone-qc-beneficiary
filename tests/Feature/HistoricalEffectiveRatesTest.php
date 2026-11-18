<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\BillingEvidence;
use App\Models\Building;
use App\Models\Meter;
use App\Models\MonthlyRecord;
use App\Models\User;
use App\Models\UtilityAccount;
use App\Models\UtilityService;
use App\Services\BillingEvidenceMath;
use App\Services\BillingEvidenceWorkflow;
use App\Services\HistoricalEffectiveRates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\BillingEvidenceData;
use Tests\TestCase;

class HistoricalEffectiveRatesTest extends TestCase
{
    use BillingEvidenceData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-18 12:00:00', 'UTC'));
        $this->assertSame('testing', DB::connection()->getDatabaseName());
    }

    /** @param callable(): mixed $operation */
    private function invalid(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected a financial/source validation rejection.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('billing_evidence', $exception->errors());
        }
    }

    public function test_reconciled_rates_exclude_balances_deposits_and_account_refunds_and_apply_export_once(): void
    {
        $f = $this->billingFixture();
        $before = $f['record']->fresh()->getRawOriginal();
        $this->actingAs($f['reviewer'])->get('/decision-support')->assertInertia(fn (Assert $page) => $page
            ->where('historicalRates.rows.0.grossPhp', '1000.00')->where('historicalRates.rows.0.netServicePhp', '980.00')
            ->where('historicalRates.rows.0.importKwh', '100.000')->where('historicalRates.rows.0.exportCreditPhp', '-20.00')
            ->where('historicalRates.rows.0.excludedOtherPhp', '50.00')->where('historicalRates.rows.0.excludedCreditsPhp', '-100.00')
            ->where('historicalRates.rows.0.grossRate', '10.000000')->where('historicalRates.rows.0.netServiceRate', '9.800000')
            ->where('historicalRates.rows.0.sources.0.state', 'verified')->where('historicalRates.rows.0.sources.0.pages', [1, 2])
            ->where('historicalRates.weighted.grossRate', '10.000000')->where('policyAvailable', false)->where('counts.evaluated', 0));
        $this->assertSame($before, $f['record']->fresh()->getRawOriginal());
        $this->assertSame('777.00', $f['record']->fresh()->cost_php);
    }

    public function test_shared_customer_account_does_not_merge_building_services_or_infer_legacy_mappings(): void
    {
        $highrise = $this->billingFixture();
        $account = UtilityAccount::findOrFail($highrise['service']->utility_account_id);
        $treasury = $this->billingFixture('TREASURY', $account);
        $this->assertDatabaseCount('utility_accounts', 1);
        $this->assertDatabaseCount('utility_services', 2);
        $meter = Meter::factory()->create(['building_id' => $highrise['building']->id, 'status' => 'inactive']);
        $this->assertDatabaseMissing('utility_services', ['meter_id' => $meter->id]);
        $service = app(HistoricalEffectiveRates::class);
        $high = $service->read($highrise['building'], $highrise['reviewer']);
        $treasuryRates = $service->read($treasury['building'], $highrise['reviewer']);
        $this->assertSame('incomplete', $high['rows'][0]['state']);
        $this->assertNull($high['rows'][0]['grossPhp']);
        $this->assertSame('monthly_record_missing', $high['rows'][0]['sources'][1]['state']);
        $this->assertSame('10.000000', $treasuryRates['rows'][0]['grossRate']);
        $this->assertCount(1, $treasuryRates['rows'][0]['sources']);
        $this->invalid(fn () => app(BillingEvidenceWorkflow::class)->draft($highrise['record'], $treasury['service'], $highrise['author'], $this->billingInput()));
    }

    public function test_existing_bill_verification_does_not_verify_new_financial_evidence_and_latest_draft_withholds_old_rates(): void
    {
        $f = $this->billingFixture();
        $workflow = app(BillingEvidenceWorkflow::class);
        $draft = $workflow->draft($f['record'], $f['service'], $f['author'], $this->billingInput(['revision_reason' => 'New review required']));
        $this->assertSame(2, $draft->version);
        $this->assertSame('verified', $f['record']->fresh()->verification_status);
        $rates = app(HistoricalEffectiveRates::class)->read($f['building'], $f['reviewer']);
        $this->assertSame('independent_verification_required', $rates['rows'][0]['sources'][0]['state']);
        $this->assertNull($rates['rows'][0]['grossRate']);
        $this->invalid(fn () => $workflow->verify($draft, $f['author'], 2, true));
        $this->invalid(fn () => $workflow->verify($draft, $f['reviewer'], 1, true));
        $this->invalid(fn () => $workflow->verify($draft, $f['reviewer'], 2, false));
        $workflow->verify($draft, $f['reviewer'], 2, true);
        $this->assertSame('verified', $f['bill']->fresh()->verification_status);
        $this->assertSame('10.000000', app(HistoricalEffectiveRates::class)->read($f['building'], $f['reviewer'])['rows'][0]['grossRate']);
    }

    public function test_corrections_and_reverification_require_new_evidence_for_the_new_record_version(): void
    {
        $f = $this->billingFixture();
        $this->actingAs($f['reviewer'])->from('/verification-queue')->patch('/monthly-records/'.$f['record']->id.'/correct', [
            'version' => $f['record']->version, 'kwh' => '100.000', 'reason' => 'Synthetic correction reviewed independently',
        ])->assertSessionHasNoErrors();
        $record = $f['record']->fresh();
        $this->assertSame('draft', $record->verification_status);
        $this->assertNull(app(HistoricalEffectiveRates::class)->read($f['building'], $f['author'])['rows'][0]['grossRate']);
        $this->actingAs($f['author'])->post('/monthly-records/'.$record->id.'/verify', [
            'version' => $record->version, 'confirmed_against_bill' => true,
        ])->assertSessionHasNoErrors();
        $record = $record->fresh();
        $this->assertSame('verified', $record->verification_status);
        $this->assertSame('source_changed', app(HistoricalEffectiveRates::class)->read($f['building'], $f['author'])['rows'][0]['sources'][0]['state']);
        $workflow = app(BillingEvidenceWorkflow::class);
        $new = $workflow->draft($record, $f['service'], $f['author'], $this->billingInput());
        $workflow->verify($new, $f['reviewer'], 2, true);
        $this->assertSame('10.000000', app(HistoricalEffectiveRates::class)->read($f['building'], $f['author'])['rows'][0]['grossRate']);
        $this->assertDatabaseCount('billing_evidence', 2);
    }

    public function test_cent_residuals_missing_fields_unknown_components_and_incorrect_subtotals_cannot_be_verified(): void
    {
        $f = $this->billingFixture(verify: false);
        $workflow = app(BillingEvidenceWorkflow::class);
        $componentInputs = $this->billingInput()['components'];
        $unknown = $componentInputs;
        $unknown[0]['category'] = 'unclassified';
        $badComponents = $componentInputs;
        $badComponents[0]['amount_php'] = '600.01';
        foreach ([
            ['current_charges_php' => '930.01'], ['total_due_php' => '1130.01'], ['energy_amount_php' => null],
            ['export_credit_php' => null], ['components' => $unknown], ['components' => $badComponents],
        ] as $overrides) {
            $draft = $workflow->draft($f['record'], $f['service'], $f['author'], $this->billingInput($overrides));
            $this->invalid(fn () => $workflow->verify($draft, $f['reviewer'], $draft->version, true));
            $this->assertSame('draft', $draft->fresh()->verification_status);
        }
    }

    public function test_reconciled_subtotals_are_not_double_counted_and_component_demand_bases_are_retained(): void
    {
        $f = $this->billingFixture(verify: false);
        $input = $this->billingInput();
        array_unshift($input['components'], ['position' => 1, 'parent_position' => null, 'is_subtotal' => true, 'category' => 'energy',
            'label' => 'Synthetic service subtotal', 'basis_unit' => 'PHP', 'amount_php' => '1000.00', 'source_page' => 2]);
        foreach ($input['components'] as $index => &$component) {
            if ($index === 0) {
                continue;
            }
            $component['position']++;
            $component['parent_position'] = $index <= 4 ? 1 : null;
            if ($component['category'] === 'demand') {
                $component['quantity'] = '12.345';
                $component['printed_rate'] = '24.30133657';
                $component['basis_unit'] = 'kW';
            }
        }
        unset($component);
        $workflow = app(BillingEvidenceWorkflow::class);
        $draft = $workflow->draft($f['record'], $f['service'], $f['author'], $input);
        $bill = $workflow->verify($draft, $f['reviewer'], $draft->version, true);
        $demand = $bill->components()->where('category', 'demand')->firstOrFail();
        $this->assertSame('12.345000', $demand->quantity);
        $this->assertSame('24.30133657', $demand->printed_rate);
        $this->assertSame('10.000000', app(HistoricalEffectiveRates::class)->read($f['building'], $f['reviewer'])['rows'][0]['grossRate']);
        $input['components'][0]['amount_php'] = '999.99';
        $bad = $workflow->draft($f['record'], $f['service'], $f['author'], $input);
        $this->invalid(fn () => $workflow->verify($bad, $f['reviewer'], $bad->version, true));
    }

    public function test_period_end_month_import_quantity_and_documented_mapping_must_match_without_relabeling(): void
    {
        $f = $this->billingFixture(verify: false);
        $workflow = app(BillingEvidenceWorkflow::class);
        $this->invalid(fn () => $workflow->draft($f['record'], $f['service'], $f['author'], $this->billingInput(['period_end' => '2025-06-01'])));
        $draft = $workflow->draft($f['record'], $f['service'], $f['author'], $this->billingInput(['import_kwh' => '125.000']));
        $this->invalid(fn () => $workflow->verify($draft, $f['reviewer'], $draft->version, true));
        $this->assertSame('2025-05-01', $f['record']->fresh()->month->toDateString());
    }

    public function test_complete_meter_coverage_includes_inactive_meters_and_withholds_partial_financial_totals(): void
    {
        $f = $this->billingFixture();
        $second = Meter::factory()->create(['building_id' => $f['building']->id, 'status' => 'inactive']);
        $this->actingAs($f['reviewer'])->get('/decision-support')->assertInertia(fn (Assert $page) => $page
            ->where('historicalRates.requiredMeterCount', 2)->where('historicalRates.rows.0.state', 'incomplete')
            ->where('historicalRates.rows.0.grossPhp', null)->where('historicalRates.weighted', null));
        MonthlyRecord::create(['meter_id' => $second->id, 'month' => '2025-05-01', 'kwh' => '200', 'source' => 'manual', 'verification_status' => 'verified']);
        $rates = app(HistoricalEffectiveRates::class)->read($f['building'], $f['reviewer']);
        $this->assertSame('billing_evidence_missing', $rates['rows'][0]['sources'][1]['state']);
        $this->assertNull($rates['rows'][0]['netServicePhp']);
    }

    public function test_weighted_rates_use_unrounded_sums_and_explicit_calendar_gap_coverage(): void
    {
        $f = $this->billingFixture();
        $record = MonthlyRecord::create(['meter_id' => $f['meter']->id, 'month' => '2025-07-01', 'kwh' => '300', 'source' => 'manual', 'verification_status' => 'verified']);
        $workflow = app(BillingEvidenceWorkflow::class);
        $bill = $workflow->draft($record, $f['service'], $f['author'], $this->billingInput([
            'period_start' => '2025-06-26', 'period_end' => '2025-07-25', 'bill_date' => '2025-08-01', 'import_kwh' => '300',
        ]));
        $workflow->verify($bill, $f['reviewer'], 1, true);
        $rates = app(HistoricalEffectiveRates::class)->read($f['building'], $f['author']);
        $this->assertSame(['2025-05-01', '2025-07-01'], $rates['includedMonths']);
        $this->assertSame(['2025-06-01'], $rates['excludedMonths']);
        $this->assertSame('incomplete', $rates['rows'][1]['state']);
        $this->assertSame('3.333333', $rates['rows'][2]['grossRate']);
        $this->assertSame('5.000000', $rates['weighted']['grossRate']);
        $this->assertSame('4.900000', $rates['weighted']['netServiceRate']);
    }

    public function test_zero_import_keeps_reconciled_amounts_but_rates_are_undefined(): void
    {
        $f = $this->billingFixture(verify: false);
        $f['record']->update(['kwh' => '0']);
        $workflow = app(BillingEvidenceWorkflow::class);
        $bill = $workflow->draft($f['record']->fresh(), $f['service'], $f['author'], $this->billingInput(['import_kwh' => '0']));
        $workflow->verify($bill, $f['reviewer'], $bill->version, true);
        $rates = app(HistoricalEffectiveRates::class)->read($f['building'], $f['reviewer']);
        $this->assertSame('zero_import', $rates['rows'][0]['state']);
        $this->assertSame('1000.00', $rates['rows'][0]['grossPhp']);
        $this->assertNull($rates['rows'][0]['grossRate']);
        $this->assertNull($rates['weighted']['grossRate']);
    }

    public function test_newer_draft_cannot_be_bypassed_by_verifying_an_older_draft(): void
    {
        $f = $this->billingFixture(verify: false);
        app(BillingEvidenceWorkflow::class)->draft($f['record'], $f['service'], $f['author'], $this->billingInput());
        $this->invalid(fn () => app(BillingEvidenceWorkflow::class)->verify($f['bill'], $f['reviewer'], 1, true));
    }

    public function test_hash_checks_withdraw_evidence_after_out_of_band_source_or_component_changes(): void
    {
        $f = $this->billingFixture();
        $read = fn () => app(HistoricalEffectiveRates::class)->read($f['building'], $f['reviewer']);
        DB::table('billing_evidence_components')->where('billing_evidence_id', $f['bill']->id)->where('position', 1)->update(['amount_php' => '600.01']);
        $this->assertSame('evidence_changed', $read()['rows'][0]['sources'][0]['state']);
        DB::table('billing_evidence_components')->where('billing_evidence_id', $f['bill']->id)->where('position', 1)->update(['amount_php' => '600.00']);
        DB::table('monthly_records')->where('id', $f['record']->id)->update(['cost_php' => '777.01']);
        $this->assertSame('source_changed', $read()['rows'][0]['sources'][0]['state']);
    }

    public function test_evidence_components_and_service_identities_are_immutable_and_not_mass_assignable_as_verified(): void
    {
        $f = $this->billingFixture();
        foreach ([fn () => $f['bill']->forceFill(['energy_amount_php' => '1'])->save(), fn () => $f['bill']->delete(),
            fn () => $f['bill']->components()->firstOrFail()->forceFill(['amount_php' => '1'])->save(),
            fn () => $f['service']->update(['service_reference' => 'changed']),
        ] as $operation) {
            try {
                $operation();
                $this->fail('Immutable evidence was changed.');
            } catch (LogicException) {
                $this->assertTrue(true);
            }
        }
        $this->assertFalse((new BillingEvidence)->isFillable('verification_status'));
    }

    public function test_evidence_workflow_rechecks_persisted_role_module_and_independence(): void
    {
        $f = $this->billingFixture(verify: false);
        $staleReviewer = $f['reviewer'];
        DB::table('users')->where('id', $staleReviewer->id)->update(['role' => 'viewer']);
        try {
            app(BillingEvidenceWorkflow::class)->verify($f['bill'], $staleReviewer, 1, true);
            $this->fail('Stale role accepted.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $f['author']->moduleRestrictions()->create(['module_key' => 'verification_queue']);
        try {
            app(BillingEvidenceWorkflow::class)->draft($f['record'], $f['service'], $f['author'], $this->billingInput());
            $this->fail('Restricted actor accepted.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertSame('draft', $f['bill']->fresh()->verification_status);
    }

    public function test_authorized_cost_roles_only_receive_allowlisted_history_and_no_public_mutation_endpoint_exists(): void
    {
        $f = $this->billingFixture();
        foreach (['admin', 'energy_manager', 'budget_officer', 'viewer', 'facilities_personnel'] as $role) {
            $user = User::factory()->role($role)->create();
            $user->buildings()->attach($f['building']);
            $response = $this->actingAs($user)->get('/decision-support');
            if ($role === 'facilities_personnel') {
                $response->assertInertia(fn (Assert $page) => $page->where('historicalRates', null));
                $response->assertDontSee('1000.00');
            } else {
                $response->assertInertia(fn (Assert $page) => $page->where('historicalRates.rows.0.grossRate', '10.000000'));
            }
            foreach (['PRIVATE-SYNTHETIC-DOCUMENT', 'PRIVATE-FICTIONAL-SERVICE', 'PRIVATE-R1-OPERATIONAL-NOTES', 'PRIVATE-LINE-energy', $f['meter']->meralco_account_ref] as $private) {
                $response->assertDontSee($private);
            }
            $user->moduleRestrictions()->create(['module_key' => 'decision_support']);
            $this->get('/decision-support')->assertForbidden();
        }
        $this->actingAs($f['reviewer'])->post('/decision-support', ['billing_evidence' => ['verification_status' => 'verified']])->assertStatus(405);
    }

    public function test_history_read_has_no_domain_or_audit_writes_and_does_not_require_forecasts_weather_or_processed_datasets(): void
    {
        $f = $this->billingFixture();
        $tables = ['meters', 'monthly_records', 'utility_accounts', 'utility_services', 'billing_evidence', 'billing_evidence_components', 'audit_logs', 'forecasts', 'recommendations', 'actions'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        $this->actingAs($f['reviewer'])->get('/decision-support')->assertInertia(fn (Assert $page) => $page
            ->where('historicalRates.rows.0.state', 'available')->where('currentForecastId', null));
        foreach ($tables as $table) {
            $this->assertSame($before[$table], DB::table($table)->orderBy('id')->get()->toJson(), $table);
        }
        $this->assertDatabaseCount('datasets', 0);
        $this->assertDatabaseCount('weather_records', 0);
        $this->assertNull(config('recommendations.policy_fingerprint'));
    }

    public function test_decimal_division_rounding_preserves_precision_beyond_javascript_safe_integer_and_signed_adjustments(): void
    {
        $this->assertSame('90071992547409.910000', BillingEvidenceMath::rate('90071992547409.91', '1'));
        $this->assertSame('0.333333', BillingEvidenceMath::rate('1.00', '3'));
        $this->assertSame('0.000001', BillingEvidenceMath::rate('0.01', '20000'));
        $this->assertSame('-0.333333', BillingEvidenceMath::rate('-1.00', '3'));
        $this->assertNull(BillingEvidenceMath::rate('1', '0'));
    }

    public function test_failed_verification_audit_rolls_back_verification_atomically(): void
    {
        $f = $this->billingFixture(verify: false);
        AuditLog::creating(function ($audit) {
            if ($audit->event === 'billing_evidence.verified') {
                throw new \RuntimeException('Synthetic audit failure');
            }
        });
        try {
            app(BillingEvidenceWorkflow::class)->verify($f['bill'], $f['reviewer'], 1, true);
            $this->fail('Expected audit rollback.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Synthetic audit failure', $exception->getMessage());
            $this->assertSame('draft', $f['bill']->fresh()->verification_status);
            $this->assertNull($f['bill']->fresh()->verified_fingerprint);
        } finally {
            AuditLog::flushEventListeners();
        }
    }

    public function test_empty_history_stays_empty_and_facilities_scope_is_still_enforced(): void
    {
        $f = $this->billingFixture();
        $other = Building::factory()->create();
        $this->actingAs($f['author'])->get('/decision-support?building='.$other->code)->assertInertia(fn (Assert $page) => $page
            ->where('historicalRates.rows', [])->where('historicalRates.weighted', null)->where('historicalRates.fromMonth', null));
        $facilities = User::factory()->role('facilities_personnel')->create();
        $facilities->buildings()->attach($f['building']);
        $this->actingAs($facilities)->get('/decision-support?building='.$other->code)->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->get('/decision-support')->assertRedirect('/login');
    }

    public function test_synthetic_evidence_cannot_be_written_or_used_outside_testing_and_no_fallback_cost_is_used(): void
    {
        $f = $this->billingFixture();
        $this->app->instance('env', 'local');
        try {
            $this->invalid(fn () => app(BillingEvidenceWorkflow::class)->draft($f['record'], $f['service'], $f['author'], $this->billingInput()));
            $rates = app(HistoricalEffectiveRates::class)->read($f['building'], $f['reviewer']);
            $this->assertSame('synthetic_evidence_excluded', $rates['rows'][0]['sources'][0]['state']);
            $this->assertNull($rates['rows'][0]['grossPhp']);
        } finally {
            $this->app->instance('env', 'testing');
        }
    }

    public function test_financial_validation_rejects_invalid_decimal_input_credit_direction_and_missing_source_pages(): void
    {
        $f = $this->billingFixture(verify: false);
        $workflow = app(BillingEvidenceWorkflow::class);
        foreach (['1e3', 'NaN', 'INF', '1,000.00', '1.001', '9999999999999999999.00'] as $value) {
            try {
                $workflow->draft($f['record'], $f['service'], $f['author'], $this->billingInput(['energy_amount_php' => $value]));
                $this->fail('Invalid decimal accepted: '.$value);
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('energy_amount_php', $exception->errors());
            }
        }
        foreach ([['export_kwh' => '0'], ['source_pages' => [1]]] as $overrides) {
            $bill = $workflow->draft($f['record'], $f['service'], $f['author'], $this->billingInput($overrides));
            $this->invalid(fn () => $workflow->verify($bill, $f['reviewer'], $bill->version, true));
        }
    }

    public function test_all_required_meter_evidence_is_summed_once_instead_of_averaging_meter_rates(): void
    {
        $f = $this->billingFixture();
        $meter = Meter::factory()->create(['building_id' => $f['building']->id, 'status' => 'inactive']);
        $service = UtilityService::create(['utility_account_id' => $f['service']->utility_account_id, 'meter_id' => $meter->id,
            'service_reference' => 'EXPLICIT-FICTIONAL-SECOND-SERVICE', 'mapping_reference' => 'Synthetic explicit association']);
        $record = MonthlyRecord::create(['meter_id' => $meter->id, 'month' => '2025-05-01', 'kwh' => '300', 'source' => 'manual', 'verification_status' => 'verified']);
        $workflow = app(BillingEvidenceWorkflow::class);
        $bill = $workflow->draft($record, $service, $f['author'], $this->billingInput(['import_kwh' => '300']));
        $workflow->verify($bill, $f['reviewer'], 1, true);
        $rates = app(HistoricalEffectiveRates::class)->read($f['building'], $f['reviewer']);
        $this->assertSame('400.000', $rates['rows'][0]['importKwh']);
        $this->assertSame('2000.00', $rates['rows'][0]['grossPhp']);
        $this->assertSame('5.000000', $rates['rows'][0]['grossRate']);
        $this->assertSame('4.900000', $rates['rows'][0]['netServiceRate']);
    }
}
