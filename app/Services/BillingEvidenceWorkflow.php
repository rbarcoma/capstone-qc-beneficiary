<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\BillingEvidence;
use App\Models\Building;
use App\Models\Meter;
use App\Models\MonthlyRecord;
use App\Models\User;
use App\Models\UtilityAccount;
use App\Models\UtilityService;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Internal foundation service. No new HTTP intake, mapping or verification route. */
class BillingEvidenceWorkflow
{
    /** @param array<string, mixed> $input */
    public function draft(MonthlyRecord $record, UtilityService $service, User $user, array $input): BillingEvidence
    {
        $rules = [
            'expected_record_version' => ['sometimes', 'integer', 'min:1'], 'previous_evidence_version' => ['sometimes', 'integer', 'min:0'],
            'period_start' => ['required', 'date_format:Y-m-d'],
            'period_end' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_start', 'before:'.now('Asia/Manila')->startOfMonth()->toDateString()],
            'bill_date' => ['required', 'date_format:Y-m-d'], 'origin' => ['required', 'in:operational,synthetic'],
            'source_document' => ['required', 'string', 'max:255'], 'document_hash' => ['required', 'regex:/^[a-f0-9]{64}$/'],
            'source_pages' => ['required', 'array', 'min:1', 'max:100'], 'source_pages.*' => ['required', 'integer', 'min:1', 'distinct'],
            'revision_reason' => ['required', 'string', 'max:2000'],
            'components' => ['required', 'array', 'min:1', 'max:250'], 'components.*' => ['required', 'array'],
            'components.*.position' => ['required', 'integer', 'min:1', 'distinct'],
            'components.*.parent_position' => ['nullable', 'integer', 'min:1'], 'components.*.is_subtotal' => ['required', 'boolean'],
            'components.*.category' => ['required', Rule::in(BillingEvidenceMath::CATEGORIES)],
            'components.*.label' => ['required', 'string', 'max:255'],
            'components.*.basis_unit' => ['required', 'in:kWh,kW,customer_month,PHP,percent,other'],
            'components.*.quantity' => ['nullable', 'string', 'regex:/^\d{1,14}(\.\d{1,6})?$/'],
            'components.*.printed_rate' => ['nullable', 'string', 'regex:/^-?\d{1,12}(\.\d{1,8})?$/'],
            'components.*.amount_php' => ['required', 'string', 'regex:/^-?\d{1,18}(\.\d{1,2})?$/'],
            'components.*.source_page' => ['required', 'integer', 'min:1'],
        ];
        foreach (['import_kwh', 'export_kwh', 'renewable_kwh', 'own_use_kwh'] as $field) {
            $rules[$field] = ['nullable', 'string', 'regex:/^\d{1,12}(\.\d{1,3})?$/'];
        }
        foreach (BillingEvidenceMath::MONEY as $field) {
            $rules[$field] = ['nullable', 'string', 'regex:/^-?\d{1,18}(\.\d{1,2})?$/'];
        }
        $data = Validator::make($input, $rules)->validate();
        if ($data['origin'] === 'synthetic' && ! $this->isolatedTesting()) {
            $this->fail('Synthetic billing evidence is permitted only in the isolated testing database.');
        }

        return DB::transaction(function () use ($record, $service, $user, $data) {
            [$record, $actor] = $this->lock($record, $user);
            $service = UtilityService::lockForUpdate()->findOrFail($service->id);
            if ($service->meter_id !== $record->meter_id || trim($service->mapping_reference) === '') {
                $this->fail('Choose an explicitly documented service/meter association. No mapping is inferred.');
            }
            if (Carbon::parse($data['period_end'])->startOfMonth()->toDateString() !== $record->month->toDateString()) {
                $this->fail('The billing-period end month must match the existing record. No period was relabeled.');
            }
            $version = (int) BillingEvidence::where('monthly_record_id', $record->id)->max('version') + 1;
            if ((isset($data['expected_record_version']) && (int) $data['expected_record_version'] !== $record->version)
                || (isset($data['previous_evidence_version']) && (int) $data['previous_evidence_version'] !== $version - 1)) {
                $this->fail('The record/evidence changed since this form was opened. Refresh and review the current version.');
            }
            $components = $data['components'];
            unset($data['components'], $data['expected_record_version'], $data['previous_evidence_version']);
            $evidence = new BillingEvidence;
            $evidence->forceFill([
                ...$data, 'source_pages' => array_map(intval(...), $data['source_pages']),
                'monthly_record_id' => $record->id, 'utility_service_id' => $service->id,
                'monthly_record_version' => $record->version, 'version' => $version,
                'source_fingerprint' => $this->sourceFingerprint($record, $service),
                'author_id' => $actor->id, 'verification_status' => 'draft',
            ])->save();
            foreach ($components as $component) {
                // The nested array validator does not make unknown keys writable.
                $row = $evidence->components()->make();
                $row->forceFill(Arr::only($component, [
                    'position', 'parent_position', 'is_subtotal', 'category', 'label', 'basis_unit', 'quantity', 'printed_rate', 'amount_php', 'source_page',
                ]));
                $row->save();
            }
            AuditLog::create(['actor_id' => $actor->id, 'event' => 'billing_evidence.drafted', 'monthly_record_id' => $record->id,
                'after' => ['evidence_id' => $evidence->id, 'version' => $version, 'monthly_record_version' => $record->version], 'reason' => $data['revision_reason']]);

            return $evidence->fresh();
        }, 3);
    }

    public function verify(BillingEvidence $evidence, User $user, int $expectedVersion, bool $confirmedAgainstBill): BillingEvidence
    {
        return DB::transaction(function () use ($evidence, $user, $expectedVersion, $confirmedAgainstBill) {
            [$record, $actor] = $this->lock(MonthlyRecord::findOrFail($evidence->monthly_record_id), $user);
            $current = BillingEvidence::lockForUpdate()->findOrFail($evidence->id);
            $latest = (int) BillingEvidence::where('monthly_record_id', $record->id)->max('version');
            if (! $confirmedAgainstBill || $current->version !== $expectedVersion || $current->version !== $latest || $current->verification_status !== 'draft') {
                $this->fail('Confirm the bill and review the latest draft evidence version.');
            }
            if ($current->author_id === $actor->id) {
                $this->fail('A different authorized person must verify this evidence version.');
            }
            $reason = $this->sourceReason($current, $record);
            $math = app(BillingEvidenceMath::class);
            $result = $math->assess($current);
            if ($reason !== null || ! $result['valid']) {
                $this->fail('Evidence cannot be verified: '.($reason ?? $result['reason']).'.');
            }
            $current->forceFill(['verification_status' => 'verified', 'verified_by' => $actor->id, 'verified_at' => now(),
                'verified_fingerprint' => $math->fingerprint($current)])->save();
            AuditLog::create(['actor_id' => $actor->id, 'event' => 'billing_evidence.verified', 'monthly_record_id' => $record->id,
                'before' => ['evidence_id' => $current->id, 'version' => $current->version, 'state' => 'draft'],
                'after' => ['state' => 'verified', 'fingerprint' => $current->verified_fingerprint]]);

            return $current->fresh();
        }, 3);
    }

    public function sourceFingerprint(MonthlyRecord $record, UtilityService $service): string
    {
        return DataFingerprint::hash([
            'record' => $record->snapshot(), 'record_id' => $record->id,
            'service' => $service->getAttributes(), 'account' => UtilityAccount::findOrFail($service->utility_account_id)->getAttributes(),
        ]);
    }

    public function sourceReason(BillingEvidence $evidence, MonthlyRecord $record): ?string
    {
        if ($evidence->origin === 'synthetic' && ! $this->isolatedTesting()) {
            return 'synthetic_evidence_excluded';
        }
        $service = UtilityService::find($evidence->utility_service_id);
        if (! $service || $service->meter_id !== $record->meter_id || trim($service->mapping_reference) === '') {
            return 'service_mapping_unavailable';
        }
        if ($record->verification_status !== 'verified') {
            return 'monthly_record_unverified';
        }
        if ($record->version !== $evidence->monthly_record_version || $evidence->source_fingerprint !== $this->sourceFingerprint($record, $service)) {
            return 'source_changed';
        }
        if ($evidence->period_end->copy()->startOfMonth()->toDateString() !== $record->month->toDateString()) {
            return 'billing_period_mismatch';
        }
        if ($evidence->import_kwh === null || ! BillingEvidenceMath::decimal($record->kwh)->isEqualTo($evidence->import_kwh)) {
            return 'import_energy_mismatch';
        }

        return null;
    }

    /** @return array{MonthlyRecord, User} */
    private function lock(MonthlyRecord $record, User $user): array
    {
        $meter = Meter::findOrFail($record->meter_id);
        $building = Building::lockForUpdate()->findOrFail($meter->building_id);
        Meter::lockForUpdate()->findOrFail($meter->id);
        $record = MonthlyRecord::lockForUpdate()->findOrFail($record->id);
        $actor = User::lockForUpdate()->findOrFail($user->id);
        abort_unless($actor->hasRole(UserRole::Admin, UserRole::EnergyManager) && $actor->canAccessModule('verification_queue'), 403);
        Gate::forUser($actor)->authorize('view', $building);
        Gate::forUser($actor)->authorize('correct', $record);

        return [$record, $actor];
    }

    private function isolatedTesting(): bool
    {
        return app()->environment('testing') && DB::connection()->getDatabaseName() === 'testing';
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['billing_evidence' => $message]);
    }
}
