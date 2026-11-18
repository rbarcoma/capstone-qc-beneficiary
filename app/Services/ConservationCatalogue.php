<?php

namespace App\Services;

class ConservationCatalogue
{
    /**
     * All entries are shown in catalogue order, never selected by consumption,
     * demand, direction, percentage or priority. Availability is not eligibility.
     * External documents/approvals are deliberately not inferred from user roles,
     * operational notes, scenario submissions or historical Action Tracker rows.
     *
     * @param  array<string, mixed>  $comparison
     * @param  array<string, mixed>  $history
     * @return array<string, mixed>
     */
    public function read(array $comparison, array $history): array
    {
        $available = [
            'R2' => $comparison['state'] === 'available',
            'R3' => ($history['consumption']['difference'] ?? null) !== null,
            'R4' => array_filter($history['meters'], fn ($meter) => $meter['difference'] !== null) !== [],
        ];
        $entries = [];
        foreach ($this->definitions() as $definition) {
            $hasEvidence = array_filter($definition['evidenceRules'], fn ($rule) => $available[$rule]) !== [];
            $requirements = [['label' => $definition['systemRequirement'], 'state' => $hasEvidence ? 'available' : 'unavailable']];
            foreach ([...$definition['additionalRequirements'], 'Named business/technical approval, applicable scope and approval reference for this catalogue item.'] as $label) {
                $requirements[] = ['label' => $label, 'state' => 'not_assessed'];
            }
            $entries[] = [
                'id' => $definition['id'], 'title' => $definition['title'], 'description' => $definition['description'],
                'approvalState' => 'unapproved', 'eligibilityState' => 'not_assessed',
                'eligibility' => $definition['eligibility'], 'evidenceRules' => $definition['evidenceRules'],
                'requirements' => $requirements,
                'missingRequirements' => array_values(array_filter($requirements, fn ($requirement) => $requirement['state'] !== 'available')),
                'limitations' => $definition['limitations'], 'proposedOwners' => $definition['proposedOwners'],
            ];
        }

        return ['version' => 'r5-review-prototype-v1', 'asOf' => $history['asOf'], 'entries' => $entries];
    }

    /** @return list<array<string, mixed>> */
    private function definitions(): array
    {
        $energyEvidence = 'At least one complete current R2 or R3 comparison; absolute values remain usable when percentage is undefined.';
        $demandEvidence = 'At least one complete same-meter R4 comparison; other meters may remain unassessed.';

        return [
            [
                'id' => 'R5-01', 'title' => 'Review the operating context behind the comparison',
                'description' => 'Discuss occupancy, opening hours, events, closures and equipment changes with Facilities before interpreting the comparison.',
                'eligibility' => 'For reviewer consideration only: a valid R2 or R3 comparison and a documented question about operating context.',
                'evidenceRules' => ['R2', 'R3'], 'systemRequirement' => $energyEvidence,
                'additionalRequirements' => ['Reviewer-documented question and reason for considering this item.', 'Operating calendar and Facilities explanation for the relevant period.'],
                'limitations' => ['A difference does not establish abnormal use or a cause. Missing notes do not establish that operations were unchanged.', 'Do not automatically correct verified billing records.'],
                'proposedOwners' => ['Energy Manager: business review.', 'Facilities: confirm operating context.'],
            ],
            [
                'id' => 'R5-02', 'title' => 'Review non-essential after-hours operation',
                'description' => 'Check whether lighting, plug loads and non-essential equipment schedules match actual use; consider adjustments only where separately authorized.',
                'eligibility' => 'For reviewer consideration only: R2/R3 context plus identified areas, equipment and service requirements.',
                'evidenceRules' => ['R2', 'R3'], 'systemRequirement' => $energyEvidence,
                'additionalRequirements' => ['Reviewer selection and documented building/area/equipment scope.', 'Occupied hours, authorized schedules and equipment inventory.', 'Dated observations of actual operation and applicable service requirements.'],
                'limitations' => ['Monthly totals do not identify after-hours operation or individual equipment loads.', 'No blanket shutdown, assumed reduction or operational authorization is supplied.'],
                'proposedOwners' => ['Energy Manager: business review.', 'Facilities technical lead and affected office: operational suitability.'],
            ],
            [
                'id' => 'R5-03', 'title' => 'Review HVAC schedules, controls and maintenance needs',
                'description' => 'Compare documented HVAC operation with occupancy and manufacturer or maintenance guidance.',
                'eligibility' => 'For reviewer consideration only: R2/R3 context and Facilities-confirmed equipment with site or maintenance evidence.',
                'evidenceRules' => ['R2', 'R3'], 'systemRequirement' => $energyEvidence,
                'additionalRequirements' => ['Reviewer selection and identified equipment/zones.', 'Operating schedules, control settings and maintenance history.', 'Dated inspection findings and appropriate technical guidance.'],
                'limitations' => ['Monthly bills cannot diagnose a fault or identify suitable settings.', 'No temperature setpoint, replacement, savings estimate or procurement conclusion is supplied.'],
                'proposedOwners' => ['Energy Manager: business review.', 'Facilities or qualified maintenance personnel: technical review; affected office consulted.'],
            ],
            [
                'id' => 'R5-04', 'title' => 'Confirm the billed-demand basis before proposing load management',
                'description' => 'Check whether the meter readings represent comparable measured demand, billing minima or another billing convention.',
                'eligibility' => 'For reviewer consideration only: a valid R4 comparison for the identified meter and access to corresponding bill evidence.',
                'evidenceRules' => ['R4'], 'systemRequirement' => $demandEvidence,
                'additionalRequirements' => ['Corresponding bills, billing-period duration, reading definitions and relevant annotations.', 'Reviewer confirmation that the compared billed-demand readings are comparable.'],
                'limitations' => ['A larger billed reading does not establish a short-duration spike, its timing or an avoidable charge.', 'Monetary conclusions require separately approved tariff evidence; account references remain restricted.'],
                'proposedOwners' => ['Energy Manager and Facilities: review.', 'Budget Officer or billing contact: confirm charge interpretation where relevant.'],
            ],
            [
                'id' => 'R5-05', 'title' => 'Investigate overlapping equipment operation',
                'description' => 'Collect timing evidence and assess whether non-critical starts or operating periods could be coordinated.',
                'eligibility' => 'For reviewer consideration only: R4 context, confirmed bill comparability and Facilities evidence linking equipment to the meter.',
                'evidenceRules' => ['R4'], 'systemRequirement' => $demandEvidence,
                'additionalRequirements' => ['Bill comparability confirmed as described in R5-04; no approval is inferred from viewing that item.', 'Equipment-to-meter mapping and operating/start-up logs or suitable interval measurements.', 'Service constraints and a technically reviewed proposed schedule.'],
                'limitations' => ['Monthly billed demand cannot identify when a peak occurred or prove equipment overlap.', 'No staggered-operation instruction is justified without site evidence and separate operational authorization.'],
                'proposedOwners' => ['Energy Manager: business review.', 'Facilities technical lead and affected offices: review any schedule change.'],
            ],
            [
                'id' => 'R5-06', 'title' => 'Examine an explicit conservation what-if',
                'description' => 'Use the existing scenario capability to explore a reviewer-defined assumption without changing the issued forecast.',
                'eligibility' => 'For reviewer consideration only: a current eligible issued forecast and an explicitly stated assumption with rationale.',
                'evidenceRules' => ['R2'], 'systemRequirement' => 'A current eligible issued source and complete R2 comparison.',
                'additionalRequirements' => ['Explicit reviewer-chosen assumption and supporting rationale.', 'Separate review of assumption plausibility and any resulting scenario calculation; previous scenario inputs are not reused automatically.'],
                'limitations' => ['What-if arithmetic is not a prediction of measure performance or measured savings.', 'No default reduction assumption or monetary benefit is supplied; tariff-dependent output remains unavailable.'],
                'proposedOwners' => ['Energy Manager: review use of the assumption.', 'Facilities: review plausibility; Budget Officer only for separately approved monetary scope.'],
            ],
            [
                'id' => 'R5-07', 'title' => 'Prepare a follow-up and measurement plan',
                'description' => 'Define how a separately authorized measure would be documented and reviewed.',
                'eligibility' => 'For reviewer consideration only: a specific separately authorized measure, defined scope and responsible office.',
                'evidenceRules' => ['R2', 'R3', 'R4'], 'systemRequirement' => 'At least one complete current R2, R3 or R4 comparison linked to the proposed review.',
                'additionalRequirements' => ['Separately authorized measure, scope, responsible office and implementation dates.', 'Comparable verified observations and relevant occupancy, weather and operational context.', 'Agreed measurement method distinguishing reported outcomes from verified measurements.'],
                'limitations' => ['A later reduction versus a forecast or three-month average does not establish attributable savings.', 'This prototype neither reads measure approvals from Action Tracker nor creates or modifies an action.'],
                'proposedOwners' => ['Energy Manager: own review plan.', 'Facilities: supply implementation/observation evidence; Budget Officer for monetary claims only.'],
            ],
        ];
    }
}
