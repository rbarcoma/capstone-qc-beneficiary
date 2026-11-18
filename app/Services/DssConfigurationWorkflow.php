<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Building;
use App\Models\DssConfiguration;
use App\Models\DssConfigurationEvent;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class DssConfigurationWorkflow
{
    public const MEASURES = [
        'R1' => ['forecast_cost_php'], 'R2' => ['forecast_change_percent', 'forecast_change_kwh'],
        'R3' => ['historical_consumption_difference_percent', 'historical_consumption_difference_kwh'],
        'R4' => ['meter_demand_difference_percent', 'meter_demand_difference_kw'], 'R5' => ['manual_evidence_review'],
        'R6' => ['explicit_solar_screening'], 'R7' => ['missing_meter_periods', 'complete_observations'],
    ];

    public function actor(User $user, bool $confirmationOnly = false): User
    {
        $actor = User::findOrFail($user->id);
        abort_unless($actor->canAccessModule('decision_support') && ($confirmationOnly
            ? $actor->hasRole(UserRole::BudgetOfficer) : $actor->hasRole(UserRole::Admin, UserRole::EnergyManager)), 403);

        return $actor;
    }

    /** @param array<string, mixed> $input */
    public function draft(Building $building, User $user, array $input): DssConfiguration
    {
        $this->actor($user);
        $data = Validator::make($input, [
            'kind' => ['required', 'in:tariff,rule,catalogue,review'], 'subject_key' => ['required', 'string', 'max:64'],
            'previous_version' => ['required', 'integer', 'min:0'], 'valid_from' => ['nullable', 'date_format:Y-m-d', 'regex:/^\d{4}-\d{2}-01$/'],
            'valid_to' => ['nullable', 'date_format:Y-m-d', 'regex:/^\d{4}-\d{2}-01$/', 'after_or_equal:valid_from'],
            'payload' => ['required', 'array'], 'payload.title' => ['required', 'string', 'max:255'],
            'payload.rationale' => ['required', 'string', 'max:4000'],
            'payload.source_reference' => ['nullable', 'string', 'max:2000'], 'payload.source_document_hash' => ['nullable', 'regex:/^[a-f0-9]{64}$/'],
            'payload.tariff_basis' => ['nullable', 'in:energy_only,blended_planning'], 'payload.rate_php_per_kwh' => ['nullable', 'string', 'regex:/^\d{1,12}(\.\d{1,8})?$/'],
            'payload.included_charges' => ['nullable', 'string', 'max:4000'], 'payload.excluded_charges' => ['nullable', 'string', 'max:4000'],
            'payload.mode' => ['nullable', 'in:information_only,conditional_proposal'], 'payload.measure' => ['nullable', 'string', 'max:100'],
            'payload.operator' => ['nullable', 'in:gt,gte,lt,lte,abs_gt'], 'payload.threshold' => ['nullable', 'string', 'regex:/^-?\d{1,12}(\.\d{1,6})?$/'],
            'payload.priority' => ['nullable', 'in:low,medium,high'], 'payload.reference_method' => ['nullable', 'string', 'max:2000'],
            'payload.conditions' => ['nullable', 'string', 'max:4000'], 'payload.limitations' => ['nullable', 'string', 'max:4000'],
            'payload.scope' => ['nullable', 'string', 'max:4000'], 'payload.operational_evidence' => ['nullable', 'string', 'max:6000'],
            'payload.responsible_office' => ['nullable', 'string', 'max:255'], 'payload.catalogue_configuration_id' => ['nullable', 'integer', 'min:1'],
        ])->validate();
        $kind = $data['kind'];
        $key = $data['subject_key'];
        if (($kind === 'tariff' && $key !== 'R1') || ($kind === 'rule' && ! array_key_exists($key, self::MEASURES))
            || (in_array($kind, ['catalogue', 'review'], true) && ! preg_match('/^R5-0[1-7]$/', $key))) {
            $this->fail('Choose a supported rule or catalogue subject.');
        }
        $fields = match ($kind) {
            'tariff' => ['source_reference', 'source_document_hash', 'tariff_basis', 'rate_php_per_kwh', 'included_charges', 'excluded_charges'],
            'rule' => ['mode', 'measure', 'operator', 'threshold', 'priority', 'reference_method'],
            'catalogue' => ['conditions', 'limitations', 'responsible_office'],
            'review' => ['scope', 'operational_evidence', 'responsible_office', 'catalogue_configuration_id'],
            default => $this->fail('Unsupported configuration kind.'),
        };
        $payload = Arr::only($data['payload'], ['title', 'rationale', ...$fields]);
        if ($kind === 'rule' && isset($payload['measure']) && ! in_array($payload['measure'], self::MEASURES[$key], true)) {
            $this->fail('The measure does not belong to this rule.');
        }

        return DB::transaction(function () use ($building, $user, $data, $kind, $key, $payload) {
            $building = Building::lockForUpdate()->findOrFail($building->id);
            User::lockForUpdate()->findOrFail($user->id);
            $actor = $this->actor($user);
            Gate::forUser($actor)->authorize('view', $building);
            $version = (int) DssConfiguration::where('building_id', $building->id)->where('kind', $kind)->where('subject_key', $key)->max('version');
            if ($version !== (int) $data['previous_version']) {
                $this->fail('A newer version exists. Refresh before creating another version.');
            }
            $context = app(DssContext::class);
            $item = new DssConfiguration;
            $item->forceFill(['building_id' => $building->id, 'kind' => $kind, 'subject_key' => $key, 'version' => $version + 1,
                'payload' => $payload, 'source_snapshot' => $kind === 'review' ? $this->reviewSnapshot($building, $actor, $payload) : [],
                'scope_hash' => $context->scopeHash($building), 'valid_from' => $data['valid_from'] ?? null, 'valid_to' => $data['valid_to'] ?? null,
                'author_id' => $actor->id, 'status' => 'draft', 'state_version' => 1]);
            $item->content_hash = $this->hash($item);
            $item->save();
            $this->event($item, $actor, 'drafted', $payload['rationale']);

            return $item->fresh();
        }, 3);
    }

    /** @param array<string, mixed> $input */
    public function transition(DssConfiguration $item, User $user, array $input): void
    {
        $data = Validator::make($input, ['operation' => ['required', 'in:submit,confirm_source,approve,reject,revoke'],
            'state_version' => ['required', 'integer', 'min:1'], 'content_hash' => ['required', 'regex:/^[a-f0-9]{64}$/'],
            'reference' => ['required', 'string', 'max:2000']])->validate();
        DB::transaction(function () use ($item, $user, $data) {
            $building = Building::lockForUpdate()->findOrFail($item->building_id);
            User::lockForUpdate()->findOrFail($user->id);
            $actor = $this->actor($user, $data['operation'] === 'confirm_source');
            Gate::forUser($actor)->authorize('view', $building);
            $current = DssConfiguration::lockForUpdate()->findOrFail($item->id);
            if ($current->state_version !== (int) $data['state_version'] || ! hash_equals($current->content_hash, $data['content_hash'])
                || ! hash_equals($current->content_hash, $this->hash($current))
                || (! in_array($data['operation'], ['revoke', 'reject'], true) && ! $this->fresh($current, $actor))) {
                $this->fail('The reviewed configuration or source changed. Draft/review a current version.');
            }
            $op = $data['operation'];
            if ($op !== 'revoke' && $current->version !== (int) DssConfiguration::where('building_id', $current->building_id)
                ->where('kind', $current->kind)->where('subject_key', $current->subject_key)->max('version')) {
                $this->fail('A newer version exists. Review the latest version instead.');
            }
            if ($op === 'submit') {
                if ($current->status !== 'draft') {
                    $this->fail('Only a draft can be submitted.');
                }
                $this->complete($current);
                $current->status = 'submitted';
            } elseif ($op === 'confirm_source') {
                if ($current->kind !== 'tariff' || $current->status !== 'submitted' || $current->author_id === $actor->id || $current->source_confirmation !== null) {
                    $this->fail('Confirm only a submitted tariff source authored by another user.');
                }
                $current->source_confirmation = ['actorId' => $actor->id, 'reference' => $data['reference'], 'contentHash' => $current->content_hash, 'at' => now()->toIso8601String()];
            } elseif (in_array($op, ['approve', 'reject'], true)) {
                if ($current->status !== 'submitted' || $current->author_id === $actor->id) {
                    $this->fail('A different Admin or Energy Manager must review the submitted version.');
                }
                if ($op === 'approve') {
                    $this->complete($current);
                    if ($current->kind === 'tariff' && ($current->source_confirmation['contentHash'] ?? null) !== $current->content_hash) {
                        $this->fail('Budget Officer tariff-source confirmation is required before approval.');
                    }
                    if ($current->kind === 'tariff' && DssConfiguration::where('building_id', $current->building_id)->where('kind', 'tariff')
                        ->where('status', 'approved')->whereKeyNot($current->id)->where('valid_from', '<=', $current->valid_to)->where('valid_to', '>=', $current->valid_from)->exists()) {
                        $this->fail('An approved tariff period overlaps. Revoke it explicitly before approving a replacement.');
                    }
                    $current->approved_hash = $current->content_hash;
                }
                $current->status = $op === 'approve' ? 'approved' : 'rejected';
                $current->forceFill(['reviewer_id' => $actor->id]);
                $current->approval_reference = $data['reference'];
            } else {
                if ($current->status !== 'approved' || $current->author_id === $actor->id) {
                    $this->fail('A different Admin or Energy Manager may revoke an approved version.');
                }
                $current->status = 'revoked';
                $current->approved_hash = null;
            }
            $current->state_version++;
            $current->save();
            $this->event($current, $actor, $op, $data['reference']);
        }, 3);
    }

    public function hash(DssConfiguration $item): string
    {
        return DataFingerprint::hash(['building' => $item->building_id, 'kind' => $item->kind, 'subject' => $item->subject_key, 'version' => $item->version,
            'payload' => $item->payload, 'scope' => $item->scope_hash, 'source' => $item->source_snapshot,
            'from' => $item->valid_from?->toDateString(), 'to' => $item->valid_to?->toDateString(), 'author' => $item->author_id]);
    }

    public function fresh(DssConfiguration $item, User $actor): bool
    {
        $building = Building::findOrFail($item->building_id);
        $context = app(DssContext::class);

        return $item->scope_hash === $context->scopeHash($building)
            && ($item->kind !== 'review' || DataFingerprint::hash($item->source_snapshot) === DataFingerprint::hash($this->reviewSnapshot($building, $actor, $item->payload)));
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function reviewSnapshot(Building $building, User $actor, array $payload): array
    {
        $catalogue = isset($payload['catalogue_configuration_id']) ? DssConfiguration::whereKey($payload['catalogue_configuration_id'])->first() : null;

        return [...app(DssContext::class)->evidence($building, $actor), 'catalogue' => $catalogue ? [
            'id' => $catalogue->id, 'version' => $catalogue->version, 'hash' => $this->hash($catalogue), 'status' => $catalogue->status,
            'latestVersion' => DssConfiguration::where('building_id', $building->id)->where('kind', 'catalogue')->where('subject_key', $catalogue->subject_key)->max('version'),
        ] : null];
    }

    private function complete(DssConfiguration $item): void
    {
        $required = match ($item->kind) {
            'tariff' => ['tariff_basis', 'rate_php_per_kwh', 'source_reference', 'source_document_hash', 'included_charges', 'excluded_charges'],
            'rule' => ['mode', 'measure', 'reference_method'], 'catalogue' => ['conditions', 'limitations', 'responsible_office'],
            'review' => ['scope', 'operational_evidence', 'responsible_office', 'catalogue_configuration_id'],
            default => $this->fail('Unsupported configuration kind.'),
        };
        foreach ($required as $field) {
            if (! isset($item->payload[$field]) || trim((string) $item->payload[$field]) === '') {
                $this->fail('Complete '.$field.' before submitting this version.');
            }
        }
        if (! $item->valid_from || ! $item->valid_to) {
            $this->fail('Explicit effective billing months are required.');
        }
        if ($item->kind === 'rule' && ($item->payload['mode'] ?? null) === 'conditional_proposal') {
            foreach (['operator', 'threshold', 'priority'] as $field) {
                if (! isset($item->payload[$field]) || $item->payload[$field] === '') {
                    $this->fail('Conditional proposals require an explicit '.$field.'.');
                }
            }
        }
        if ($item->kind === 'review') {
            $catalogue = DssConfiguration::whereKey($item->payload['catalogue_configuration_id'])->first();
            if (! $catalogue || $catalogue->kind !== 'catalogue' || $catalogue->subject_key !== $item->subject_key
                || $catalogue->building_id !== $item->building_id || $catalogue->status !== 'approved'
                || $catalogue->approved_hash !== $this->hash($catalogue) || $catalogue->scope_hash !== $item->scope_hash) {
                $this->fail('Choose the matching independently reviewed catalogue version.');
            }
        }
    }

    private function event(DssConfiguration $item, User $actor, string $event, string $reference): void
    {
        $row = new DssConfigurationEvent;
        $row->forceFill(['dss_configuration_id' => $item->id, 'actor_id' => $actor->id, 'state_version' => $item->state_version,
            'event' => $event, 'reference' => $reference, 'snapshot' => ['status' => $item->status, 'contentHash' => $item->content_hash,
                'sourceConfirmation' => $item->source_confirmation, 'approvedHash' => $item->approved_hash], 'created_at' => now()])->save();
        AuditLog::create(['actor_id' => $actor->id, 'event' => 'dss.configuration.'.$event,
            'after' => ['configuration_id' => $item->id, 'version' => $item->version, 'state_version' => $item->state_version, 'content_hash' => $item->content_hash, 'runtime_activated' => false],
            'reason' => $reference]);
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['configuration' => $message]);
    }
}
