<?php

namespace Tests\Support;

use App\Models\DssConfiguration;
use App\Models\User;
use App\Services\BuildingDataset;
use App\Services\DssConfigurationWorkflow;
use App\Services\ForecastIssuance;

trait ReviewedDssData
{
    use ForecastData;

    private array $f;

    private User $reviewer;

    protected function beforeRefreshingDatabase(): void
    {
        SyntheticRecommendationEvaluator::guard();
    }

    private function issue(): int
    {
        app(BuildingDataset::class)->build($this->f['building'], $this->f['user']);

        return app(ForecastIssuance::class)->issue($this->f['building'], $this->f['user'])['forecast']->id;
    }

    private function configuration(string $key, array $overrides = [], string $kind = 'rule', string $through = '2026-09-01', bool $approve = true): DssConfiguration
    {
        $workflow = app(DssConfigurationWorkflow::class);
        $payload = ['title' => 'Fictional test case', 'rationale' => SyntheticRecommendationEvaluator::FIXTURE,
            'reference_method' => 'Synthetic boundary exercise only', 'mode' => 'conditional_proposal', 'measure' => DssConfigurationWorkflow::MEASURES[$key][0] ?? null,
            'operator' => 'gt', 'threshold' => '0', 'priority' => 'low', 'source_reference' => 'Fictional energy-only source',
            'source_document_hash' => hash('sha256', 'synthetic'), 'tariff_basis' => 'energy_only', 'rate_php_per_kwh' => '2.5',
            'included_charges' => 'Fixture energy', 'excluded_charges' => 'All other charges', 'conditions' => 'Fixture case review',
            'limitations' => 'Synthetic only', 'responsible_office' => 'Fixture office', 'scope' => 'Fixture building', 'operational_evidence' => 'Fictional observation'];
        $item = $workflow->draft($this->f['building'], $this->f['user'], ['kind' => $kind, 'subject_key' => $key,
            'previous_version' => (int) DssConfiguration::where('building_id', $this->f['building']->id)->where('kind', $kind)->where('subject_key', $key)->max('version'),
            'valid_from' => '2026-01-01', 'valid_to' => $through, 'payload' => [...$payload, ...$overrides]]);
        if ($approve) {
            $this->transition($item, $this->f['user'], 'submit');
            if ($kind === 'tariff') {
                $this->transition($item, User::factory()->role('budget_officer')->create(), 'confirm_source');
            }
            $this->transition($item, $this->reviewer, 'approve');
        }

        return $item->fresh();
    }

    private function transition(DssConfiguration $item, User $actor, string $operation): void
    {
        $item->refresh();
        app(DssConfigurationWorkflow::class)->transition($item, $actor, ['operation' => $operation, 'state_version' => $item->state_version,
            'content_hash' => $item->content_hash, 'reference' => 'Synthetic review']);
    }
}
