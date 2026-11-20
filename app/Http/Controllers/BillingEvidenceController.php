<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
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
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class BillingEvidenceController extends Controller
{
    private function actor(Request $request): User
    {
        $actor = User::findOrFail($request->user()?->id);
        abort_unless($actor->canAccessModule('verification_queue') && $actor->hasRole(UserRole::Admin, UserRole::EnergyManager), 403);

        return $actor;
    }

    public function index(Request $request, BillingEvidenceWorkflow $workflow): Response
    {
        $actor = $this->actor($request);
        $request->validate(['record' => ['nullable', 'integer', 'min:1']]);
        $buildings = Building::orderBy('name')->get();
        $code = $request->query('building', $buildings->first()?->code);
        $building = $buildings->firstWhere('code', $code);
        abort_unless($building || $code === null, 404);
        $meters = Meter::where('building_id', $building?->id)->orderBy('id')->get();
        $records = MonthlyRecord::whereIn('meter_id', $meters->modelKeys())->orderByDesc('month')->orderByDesc('id')->paginate(20)->withQueryString();
        $selected = $request->query('record');
        if ($selected === null) {
            $selected = $records->getCollection()->first(fn (MonthlyRecord $record) => $record->verification_status === 'verified')?->id;
            if ($selected === null) {
                $selected = $records->getCollection()->first()?->id;
            }
        }
        $record = $selected ? MonthlyRecord::whereIn('meter_id', $meters->modelKeys())->whereKey($selected)->firstOrFail() : null;
        $bills = BillingEvidence::where('monthly_record_id', $record?->id)->latest('version')->paginate(10, ['*'], 'evidence_page')->withQueryString();
        $bills->through(function (BillingEvidence $bill) use ($workflow, $record, $actor) {
            $reason = $record ? $workflow->sourceReason($bill, $record) : 'record_missing';

            return ['id' => $bill->id, 'version' => $bill->version, 'recordVersion' => $bill->monthly_record_version,
                'status' => $bill->verification_status, 'authorId' => $bill->author_id, 'reviewerId' => $bill->verified_by,
                'canVerify' => $reason === null && $bill->verification_status === 'draft' && $bill->author_id !== $actor->id
                    && $bill->version === (int) BillingEvidence::where('monthly_record_id', $bill->monthly_record_id)->max('version'),
                'sourceState' => $reason ?? 'current', 'reconciliation' => app(BillingEvidenceMath::class)->assess($bill),
                'input' => [...$bill->only(['period_start', 'period_end', 'bill_date', 'origin', 'source_document', 'document_hash', 'source_pages', 'revision_reason',
                    'import_kwh', 'export_kwh', 'renewable_kwh', 'own_use_kwh', ...BillingEvidenceMath::MONEY]),
                    'components' => $bill->components()->orderBy('position')->get()->map(fn ($row) => $row->only(['position', 'parent_position', 'is_subtotal', 'category', 'label', 'basis_unit', 'quantity', 'printed_rate', 'amount_php', 'source_page']))->all()]];
        });
        $response = Inertia::render('billing-evidence', [
            'buildings' => $buildings->map(fn ($b) => $b->only(['id', 'code', 'name'])), 'selectedBuildingCode' => $building?->code,
            'records' => $records->through(fn (MonthlyRecord $row) => $row->only(['id', 'meter_id', 'month', 'kwh', 'version', 'verification_status'])),
            'selectedRecord' => $record?->only(['id', 'meter_id', 'month', 'kwh', 'version', 'verification_status']),
            'latestVerifiedPeriod' => MonthlyRecord::whereIn('meter_id', $meters->modelKeys())->verified()->max('month'),
            'latestEvidenceVersion' => (int) BillingEvidence::where('monthly_record_id', $record?->id)->max('version'),
            'services' => UtilityService::whereIn('meter_id', $meters->modelKeys())->orderBy('id')->get()->map(fn ($s) => $s->only(['id', 'meter_id', 'utility_account_id'])),
            'accounts' => UtilityAccount::orderBy('id')->get()->map(fn ($a) => ['id' => $a->id, 'label' => $a->provider.' · account #'.$a->id]),
            'meters' => $meters->map(fn ($meter) => ['id' => $meter->id, 'label' => 'Meter #'.$meter->id.' · '.$meter->maskedRef()]),
            'evidence' => $bills, 'categories' => BillingEvidenceMath::CATEGORIES, 'canMap' => $actor->hasRole(UserRole::Admin) && $actor->canAccessModule('buildings_meters'),
            'syntheticAllowed' => app()->environment('testing') && DB::connection()->getDatabaseName() === 'testing',
            'result' => $request->session()->get('evidenceResult'),
        ])->toResponse($request);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    public function store(Request $request, MonthlyRecord $record, BillingEvidenceWorkflow $workflow): RedirectResponse
    {
        $actor = $this->actor($request);
        $data = $request->validate(['utility_service_id' => ['required', 'integer'], 'expected_record_version' => ['required', 'integer', 'min:1'],
            'previous_evidence_version' => ['required', 'integer', 'min:0'], 'authorized_source' => ['required', 'accepted']]);
        $workflow->draft($record, UtilityService::whereKey($data['utility_service_id'])->firstOrFail(), $actor, $request->all());

        return back()->with('evidenceResult', 'New evidence version saved as draft. Independent verification is required.');
    }

    public function verify(Request $request, BillingEvidence $evidence, BillingEvidenceWorkflow $workflow): RedirectResponse
    {
        $actor = $this->actor($request);
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'], 'confirmed_against_bill' => ['required', 'accepted']]);
        $workflow->verify($evidence, $actor, (int) $data['version'], true);

        return back()->with('evidenceResult', 'Billing evidence independently verified and audited.');
    }

    public function mapService(Request $request): RedirectResponse
    {
        $actor = $this->actor($request);
        abort_unless($actor->hasRole(UserRole::Admin) && $actor->canAccessModule('buildings_meters'), 403);
        $data = $request->validate(['meter_id' => ['required', 'integer'], 'utility_account_id' => ['nullable', 'integer'],
            'provider' => ['required_without:utility_account_id', 'nullable', 'string', 'max:100'],
            'account_reference' => ['required_without:utility_account_id', 'nullable', 'string', 'max:255'],
            'service_reference' => ['required', 'string', 'max:255', 'unique:utility_services'], 'physical_meter_reference' => ['nullable', 'string', 'max:255'],
            'mapping_reference' => ['required', 'string', 'max:255'], 'authorized_source' => ['required', 'accepted']]);
        DB::transaction(function () use ($data, $actor) {
            $meter = Meter::whereKey($data['meter_id'])->firstOrFail();
            $building = Building::lockForUpdate()->findOrFail($meter->building_id);
            Meter::lockForUpdate()->findOrFail($meter->id);
            $fresh = User::lockForUpdate()->findOrFail($actor->id);
            abort_unless($fresh->hasRole(UserRole::Admin) && $fresh->canAccessModule('verification_queue') && $fresh->canAccessModule('buildings_meters'), 403);
            Gate::forUser($fresh)->authorize('manage', $building);
            if (UtilityService::where('meter_id', $meter->id)->exists()) {
                throw ValidationException::withMessages(['mapping' => 'This meter already has an immutable service association.']);
            }
            $account = isset($data['utility_account_id']) ? UtilityAccount::whereKey($data['utility_account_id'])->firstOrFail()
                : UtilityAccount::firstOrCreate(['provider' => $data['provider'], 'account_reference' => $data['account_reference']]);
            $service = UtilityService::create([...Arr::only($data, ['meter_id', 'service_reference', 'physical_meter_reference', 'mapping_reference']), 'utility_account_id' => $account->id]);
            AuditLog::create(['actor_id' => $fresh->id, 'event' => 'utility_service.associated',
                'after' => ['service_id' => $service->id, 'account_id' => $account->id, 'meter_id' => $meter->id], 'reason' => $data['mapping_reference']]);
        }, 3);

        return back()->with('evidenceResult', 'Explicit service association recorded. Existing meter references were preserved.');
    }
}
