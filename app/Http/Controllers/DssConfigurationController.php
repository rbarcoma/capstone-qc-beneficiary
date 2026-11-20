<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\Building;
use App\Models\DssConfiguration;
use App\Models\DssConfigurationEvent;
use App\Models\MonthlyRecord;
use App\Models\User;
use App\Services\DssConfigurationWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class DssConfigurationController extends Controller
{
    public function index(Request $request, DssConfigurationWorkflow $workflow): Response
    {
        $actor = User::findOrFail($request->user()?->id);
        $budget = $actor->hasRole(UserRole::BudgetOfficer);
        $workflow->actor($actor, $budget);
        $buildings = Building::orderBy('name')->get();
        $code = $request->query('building', $buildings->first()?->code);
        $building = $buildings->firstWhere('code', $code);
        abort_unless($building || $code === null, 404);
        $kind = $request->query('kind', $budget ? 'tariff' : 'rule');
        abort_unless(in_array($kind, $budget ? ['tariff'] : ['tariff', 'rule', 'catalogue', 'review'], true), 403);
        $subject = $request->query('subject', $kind === 'tariff' ? 'R1' : (in_array($kind, ['catalogue', 'review'], true) ? 'R5-01' : 'R2'));
        abort_unless(is_string($subject) && ($kind === 'tariff' ? $subject === 'R1' : ($kind === 'rule' ? array_key_exists($subject, $workflow::MEASURES) : preg_match('/^R5-0[1-7]$/', $subject))), 404);
        $query = DssConfiguration::where('building_id', $building?->id)->where('kind', $kind)->where('subject_key', $subject);
        $latest = (int) (clone $query)->max('version');
        $items = $query->latest('version')->paginate(10)->withQueryString();
        $items->through(function (DssConfiguration $item) use ($actor, $budget, $latest, $workflow) {
            $fresh = $item->content_hash === $workflow->hash($item) && $workflow->fresh($item, $actor);
            $notOwn = $item->author_id !== $actor->id;
            $latestVersion = $item->version === $latest;
            $operations = [];
            if ($budget) {
                if ($fresh && $latestVersion && $notOwn && $item->status === 'submitted' && $item->source_confirmation === null) {
                    $operations[] = 'confirm_source';
                }
            } else {
                if ($fresh && $latestVersion && $item->author_id === $actor->id && $item->status === 'draft') {
                    $operations[] = 'submit';
                }
                if ($fresh && $latestVersion && $notOwn && $item->status === 'submitted') {
                    $operations = ['approve', 'reject'];
                }
                if ($notOwn && $item->status === 'approved') {
                    $operations[] = 'revoke';
                }
            }

            return ['id' => $item->id, 'version' => $item->version, 'stateVersion' => $item->state_version, 'kind' => $item->kind,
                'subjectKey' => $item->subject_key, 'payload' => $item->payload, 'sourceSnapshot' => $item->source_snapshot,
                'contentHash' => $item->content_hash, 'status' => $item->status, 'sourceCurrent' => $fresh,
                'authorId' => $item->author_id, 'reviewerId' => $item->reviewer_id,
                'validFrom' => $item->valid_from?->toDateString(), 'validTo' => $item->valid_to?->toDateString(),
                'sourceConfirmation' => $item->source_confirmation, 'operations' => $operations,
                'history' => DssConfigurationEvent::where('dss_configuration_id', $item->id)->orderBy('state_version')->get()
                    ->map(fn ($event) => $event->only(['actor_id', 'state_version', 'event', 'reference', 'created_at']))];
        });
        $response = Inertia::render('dss-configuration', [
            'buildings' => $buildings->map(fn ($b) => $b->only(['id', 'code', 'name'])), 'selectedBuildingCode' => $building?->code,
            'buildingId' => $building?->id, 'kind' => $kind, 'subject' => $subject, 'previousVersion' => $latest,
            'items' => $items, 'canDraft' => ! $budget, 'measures' => $workflow::MEASURES,
            'latestVerifiedPeriod' => MonthlyRecord::query()
                ->whereHas('meter', fn ($query) => $query->where('building_id', $building?->id))
                ->verified()->max('month'),
            'catalogues' => $kind === 'review' ? DssConfiguration::where('building_id', $building?->id)->where('kind', 'catalogue')
                ->where('subject_key', $subject)->where('status', 'approved')->get()->map(fn ($item) => ['id' => $item->id, 'version' => $item->version]) : [],
            'result' => $request->session()->get('configurationResult'), 'runtimeActive' => false,
        ])->toResponse($request);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    public function store(Request $request, Building $building, DssConfigurationWorkflow $workflow): RedirectResponse
    {
        $workflow->draft($building, $request->user(), $request->all());

        return back()->with('configurationResult', 'New immutable draft saved. Independent review does not activate operational policy.');
    }

    public function transition(Request $request, DssConfiguration $configuration, DssConfigurationWorkflow $workflow): RedirectResponse
    {
        $workflow->transition($configuration, $request->user(), $request->all());

        return back()->with('configurationResult', 'Review event recorded and audited. Runtime policies remain disabled.');
    }
}
