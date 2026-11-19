<?php

namespace App\Services;

use App\Models\Building;
use App\Models\DssConfiguration;
use App\Models\User;

/**
 * Resolves deployment-time recommendation activations.
 *
 * An activation is an explicit, reviewed deployment record. It pins the exact
 * approved configuration versions (id, version, hash) for one building and one
 * effective window, and is never produced from an approved database row alone.
 * Normal runtime ships with no activations.
 *
 * @phpstan-type Inactive array{state: 'disabled'|'unavailable', reason: string, fingerprint: null}
 * @phpstan-type Active array{state: 'active', reason: string, fingerprint: string, authorization: string, rules: list<string>, rulesByCode: array<string, DssConfiguration>, tariffs: array<string, DssConfiguration>, catalogues: array<string, array{catalogue: DssConfiguration, review: DssConfiguration}>}
 */
class RecommendationPolicy
{
    public const EXECUTABLE_RULES = ['R1', 'R2', 'R3', 'R4', 'R5'];

    public const VERSION = 'dss-recommendation-policy-v1';

    public function configuredCount(): int
    {
        $entries = config('recommendations.activations');

        return is_array($entries) ? count(array_filter($entries, fn ($entry) => is_array($entry)
            && is_string($entry['building'] ?? null) && $entry['building'] !== '' && is_array($entry['rules'] ?? null))) : 0;
    }

    /**
     * @param  Active|Inactive|null  $activation
     * @return array{fingerprint: string|null, rules: list<string>, catalogues?: array<string, array{catalogue: DssConfiguration, review: DssConfiguration}>}
     */
    public function reviewContract(?array $activation): array
    {
        return ['fingerprint' => $activation['fingerprint'] ?? null, 'rules' => $activation['rules'] ?? [], 'catalogues' => $activation['catalogues'] ?? []];
    }

    /** @return Active|Inactive */
    public function resolve(Building $building, ?string $targetMonth, User $actor, bool $lock = false): array
    {
        $entries = config('recommendations.activations');
        if (! is_array($entries) || $entries === []) {
            return $this->state('disabled', 'Recommendation evaluation is not activated for this building and period.');
        }
        if ($targetMonth === null) {
            return $this->state('disabled', 'A current issued forecast is required before rules can be evaluated.');
        }
        $matches = [];
        foreach ($entries as $entry) {
            if (! is_array($entry) || ($entry['building'] ?? null) !== $building->code) {
                continue;
            }
            $definition = $this->definition($entry);
            if ($definition === null) {
                return $this->state('unavailable', 'The configured activation is incomplete or invalid.');
            }
            if ($targetMonth >= $definition[0] && $targetMonth <= $definition[1]) {
                $matches[] = $definition;
            }
        }
        if ($matches === []) {
            return $this->state('disabled', 'Recommendation evaluation is not activated for this building and period.');
        }
        if (count($matches) > 1) {
            return $this->state('unavailable', 'More than one activation matches this building and period.');
        }
        [$from, $to, $authorization, $rules, $bindings] = $matches[0];

        $query = DssConfiguration::query()->whereIn('id', array_column($bindings, 'id'));
        if ($lock) {
            $query->lockForUpdate();
        }
        $rows = $query->get()->keyBy('id');
        $workflow = app(DssConfigurationWorkflow::class);
        foreach ($bindings as $binding) {
            $row = $rows->get($binding['id']);
            if (! $row instanceof DssConfiguration
                || $row->building_id !== $building->id
                || $row->kind !== $binding['kind']
                || $row->subject_key !== $binding['subject_key']
                || $row->version !== $binding['version']
                || $row->status !== 'approved'
                || $row->reviewer_id === null || $row->reviewer_id === $row->author_id
                || trim((string) $row->approval_reference) === '' || $row->content_hash !== $row->approved_hash
                || ($row->kind === 'rule' && ($row->payload['mode'] ?? null) !== 'conditional_proposal')
                || ($row->kind === 'tariff' && (($row->source_confirmation['contentHash'] ?? null) !== $row->approved_hash
                    || ! is_int($row->source_confirmation['actorId'] ?? null) || $row->source_confirmation['actorId'] === $row->author_id))
                || ! hash_equals($row->approved_hash, $binding['hash'])
                || ! hash_equals($row->approved_hash, $workflow->hash($row))
                || $row->valid_from === null || $row->valid_to === null
                || $row->valid_from->toDateString() > $targetMonth || $row->valid_to->toDateString() < $targetMonth
                || DssConfiguration::where('building_id', $building->id)->where('kind', $row->kind)->where('subject_key', $row->subject_key)->where('version', '>', $row->version)->exists()
                || ! $workflow->fresh($row, $actor)) {
                return $this->state('unavailable', 'A pinned reviewed version is no longer current. Evaluation is unavailable until a current activation is deployed.');
            }
        }

        $canonical = array_map(fn (array $binding) => [$binding['kind'], $binding['subject_key'],
            $binding['id'], $binding['version'], $binding['hash']], $bindings);
        usort($canonical, fn (array $a, array $b) => strcmp(implode('|', $a), implode('|', $b)));
        $fingerprint = DataFingerprint::hash(['policy' => self::VERSION, 'building' => $building->code,
            'from' => $from, 'to' => $to, 'authorization' => $authorization, 'rules' => $rules, 'bindings' => $canonical]);

        $byRule = $rows->filter(fn (DssConfiguration $row) => $row->kind === 'rule')->keyBy('subject_key');
        $tariffs = $rows->filter(fn (DssConfiguration $row) => $row->kind === 'tariff')->keyBy('subject_key');
        $catalogues = [];
        foreach ($rows->filter(fn (DssConfiguration $row) => $row->kind === 'catalogue') as $catalogue) {
            $review = $rows->first(fn (DssConfiguration $row) => $row->kind === 'review' && $row->subject_key === $catalogue->subject_key);
            if ($review instanceof DssConfiguration && (int) ($review->payload['catalogue_configuration_id'] ?? 0) === $catalogue->id) {
                $catalogues[$catalogue->subject_key] = ['catalogue' => $catalogue, 'review' => $review];
            } else {
                return $this->state('unavailable', 'Each R5 case requires its exact reviewed catalogue version.');
            }
        }
        if (in_array('R1', $rules, true) && ! $tariffs->has('R1')) {
            return $this->state('unavailable', 'R1 requires a pinned approved tariff.');
        }
        if (in_array('R5', $rules, true) && $catalogues === []) {
            return $this->state('unavailable', 'R5 requires a pinned reviewed catalogue case.');
        }

        return ['state' => 'active', 'reason' => 'Recommendation evaluation is activated for this building and period.',
            'fingerprint' => $fingerprint, 'authorization' => $authorization,
            'rules' => $rules, 'rulesByCode' => $byRule->all(), 'tariffs' => $tariffs->all(), 'catalogues' => $catalogues];
    }

    /**
     * @param  array<string,mixed>  $entry
     * @return array{0: string, 1: string, 2: string, 3: list<string>, 4: list<array{kind: string, subject_key: string, id: int, version: int, hash: string}>}|null
     */
    private function definition(array $entry): ?array
    {
        $from = $entry['from'] ?? null;
        $to = $entry['to'] ?? null;
        $authorization = $entry['authorization'] ?? null;
        $rules = $entry['rules'] ?? null;
        $bindings = $entry['bindings'] ?? null;
        $date = fn ($value) => is_string($value) && preg_match('/^\d{4}-(0[1-9]|1[0-2])-01$/D', $value) === 1;
        if (! $date($from) || ! $date($to) || $from > $to || ! is_string($authorization) || trim($authorization) === ''
            || ! is_array($rules) || $rules === [] || ! is_array($bindings) || $bindings === []
            || ! array_is_list($rules) || ! array_is_list($bindings) || array_filter($rules, fn ($rule) => ! in_array($rule, self::EXECUTABLE_RULES, true)) !== []
            || count($rules) !== count(array_unique($rules))) {
            return null;
        }

        $normalized = [];
        $seen = [];
        foreach ($bindings as $binding) {
            if (! is_array($binding) || ! in_array($binding['kind'] ?? null, ['rule', 'tariff', 'catalogue', 'review'], true)
                || ! is_string($binding['subject_key'] ?? null) || $binding['subject_key'] === ''
                || ! is_int($binding['id'] ?? null) || $binding['id'] < 1
                || ! is_int($binding['version'] ?? null) || $binding['version'] < 1
                || ! is_string($binding['hash'] ?? null) || preg_match('/^[a-f0-9]{64}$/', $binding['hash']) !== 1) {
                return null;
            }
            $identity = $binding['kind'].':'.$binding['subject_key'];
            if (isset($seen[$identity])) {
                return null;
            }
            $seen[$identity] = true;
            $normalized[] = ['kind' => $binding['kind'], 'subject_key' => $binding['subject_key'],
                'id' => $binding['id'], 'version' => $binding['version'], 'hash' => $binding['hash']];
        }
        foreach ($rules as $rule) {
            if (! isset($seen['rule:'.$rule])) {
                return null;
            }
        }
        foreach ($normalized as $binding) {
            if ($binding['kind'] === 'tariff' && $binding['subject_key'] !== 'R1') {
                return null;
            }
            if (($binding['kind'] === 'rule' || $binding['kind'] === 'tariff') && ! in_array($binding['subject_key'], $rules, true)) {
                return null;
            }
            if (in_array($binding['kind'], ['catalogue', 'review'], true) && preg_match('/^R5-0[1-7]$/', $binding['subject_key']) !== 1) {
                return null;
            }
            if (in_array($binding['kind'], ['catalogue', 'review'], true)
                && ! in_array('R5', $rules, true)) {
                return null;
            }
        }
        foreach ($normalized as $binding) {
            if ($binding['kind'] === 'review' && ! isset($seen['catalogue:'.$binding['subject_key']])) {
                return null;
            }
        }

        sort($rules);

        return [$from, $to, trim($authorization), $rules, $normalized];
    }

    /** @param 'unavailable'|'disabled' $state
     * @return Inactive
     */
    private function state(string $state, string $reason): array
    {
        return ['state' => $state, 'reason' => $reason, 'fingerprint' => null];
    }
}
