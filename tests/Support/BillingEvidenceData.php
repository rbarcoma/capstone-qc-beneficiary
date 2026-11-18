<?php

namespace Tests\Support;

use App\Models\BillingEvidence;
use App\Models\Building;
use App\Models\Meter;
use App\Models\MonthlyRecord;
use App\Models\User;
use App\Models\UtilityAccount;
use App\Models\UtilityService;
use App\Services\BillingEvidenceWorkflow;

/** Entirely fictional financial/identity data, never copied from confidential bills. */
trait BillingEvidenceData
{
    /** @return array{author: User, reviewer: User, building: Building, meter: Meter, record: MonthlyRecord, service: UtilityService, bill: BillingEvidence} */
    protected function billingFixture(string $code = 'HIGHRISE', ?UtilityAccount $account = null, bool $verify = true): array
    {
        $author = User::factory()->role('admin')->create();
        $reviewer = User::factory()->role('energy_manager')->create();
        $building = Building::factory()->create(['code' => $code, 'name' => ucfirst(strtolower($code)).' Building']);
        $meter = Meter::factory()->create(['building_id' => $building->id]);
        $account ??= UtilityAccount::create(['provider' => 'SYNTHETIC-UTILITY', 'account_reference' => 'PRIVATE-FICTIONAL-ACCOUNT-'.$meter->id]);
        $service = UtilityService::create(['utility_account_id' => $account->id, 'meter_id' => $meter->id,
            'service_reference' => 'PRIVATE-FICTIONAL-SERVICE-'.$meter->id, 'physical_meter_reference' => 'PRIVATE-FICTIONAL-METER-'.$meter->id,
            'mapping_reference' => 'Explicit synthetic test association']);
        $record = MonthlyRecord::create(['meter_id' => $meter->id, 'month' => '2025-05-01', 'kwh' => '100.000', 'cost_php' => '777.00',
            'source' => 'manual', 'verification_status' => 'verified', 'uploaded_by' => $author->id, 'last_edited_by' => $author->id,
            'verified_by' => $reviewer->id, 'verified_at' => now(), 'notes' => 'PRIVATE-R1-OPERATIONAL-NOTES'])->fresh();
        $workflow = app(BillingEvidenceWorkflow::class);
        $bill = $workflow->draft($record, $service, $author, $this->billingInput());
        if ($verify) {
            $bill = $workflow->verify($bill, $reviewer, $bill->version, true);
        }

        return compact('author', 'reviewer', 'building', 'meter', 'record', 'service', 'bill');
    }

    /** @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    protected function billingInput(array $overrides = []): array
    {
        $components = [];
        foreach (['energy' => '600.00', 'demand' => '300.00', 'tax' => '120.00', 'embedded_adjustment' => '-20.00', 'export_credit' => '-20.00', 'deposit' => '50.00', 'account_credit' => '-100.00'] as $category => $amount) {
            $components[] = ['position' => count($components) + 1, 'parent_position' => null, 'is_subtotal' => false,
                'category' => $category, 'label' => 'PRIVATE-LINE-'.$category, 'basis_unit' => 'PHP',
                'quantity' => '1', 'printed_rate' => $amount, 'amount_php' => $amount, 'source_page' => 2];
        }

        return [...[
            'period_start' => '2025-04-26', 'period_end' => '2025-05-25', 'bill_date' => '2025-06-02',
            'origin' => 'synthetic', 'source_document' => 'PRIVATE-SYNTHETIC-DOCUMENT', 'document_hash' => hash('sha256', 'fictional-r1-bill'),
            'source_pages' => [1, 2], 'revision_reason' => 'Fictional test evidence, not a real bill',
            'import_kwh' => '100.000', 'export_kwh' => '5.000', 'renewable_kwh' => '25.000', 'own_use_kwh' => '20.000',
            'energy_amount_php' => '1000.00', 'export_credit_php' => '-20.00', 'other_charges_php' => '50.00',
            'applied_credits_php' => '-100.00', 'current_charges_php' => '930.00', 'previous_balance_php' => '200.00', 'total_due_php' => '1130.00',
            'components' => $components,
        ], ...$overrides];
    }
}
