<?php

namespace App\Services;

use App\Models\DssConfiguration;
use App\Models\Meter;
use App\Models\Recommendation;

class RecommendationReadiness
{
    /** @param array{fingerprint: string|null, rules: list<string>, catalogues?: array<string, array{catalogue: DssConfiguration, review: DssConfiguration}>} $contract */
    public function reason(Recommendation $recommendation, ?int $currentForecastId, array $contract): ?string
    {
        if ($recommendation->evaluation_status !== 'evaluated' || $contract['fingerprint'] === null
            || $recommendation->policy_fingerprint !== $contract['fingerprint'] || ! in_array($recommendation->rule_code, $contract['rules'], true)) {
            return 'Rule policy unavailable or changed; an evaluated current contract is required.';
        }
        if ($currentForecastId !== $recommendation->forecast_id
            || $recommendation->source_fingerprint !== $recommendation->forecast->dataset_fingerprint) {
            return 'The source is no longer the current issued forecast. Re-evaluation is required.';
        }
        if (isset($contract['catalogues']) && in_array($recommendation->rule_code, ['R4', 'R5'], true) && $recommendation->subject_key === 'building') {
            return 'This rule requires its evaluated meter or catalogue subject.';
        }
        if ($recommendation->subject_key !== 'building') {
            $snapshot = $recommendation->subject_snapshot ?? [];
            if ($recommendation->rule_code === 'R4') {
                $id = $snapshot['meterId'] ?? null;
                if (! is_int($id) || $recommendation->subject_key !== 'meter:'.$id || ! Meter::whereKey($id)
                    ->where('building_id', $recommendation->forecast->building_id)->exists()) {
                    return 'The meter subject is unavailable or belongs to another building.';
                }
            } elseif ($recommendation->rule_code === 'R5') {
                $entry = $snapshot['catalogueEntry'] ?? '';
                if (isset($contract['catalogues'])) {
                    $pair = is_string($entry) ? ($contract['catalogues'][$entry] ?? null) : null;
                    if (! $pair || $pair['catalogue']->id !== ($snapshot['catalogueConfigurationId'] ?? null)
                        || $pair['review']->id !== ($snapshot['reviewConfigurationId'] ?? null)) {
                        return 'The evaluated subject does not match the pinned catalogue and case review.';
                    }
                }
                $configuration = DssConfiguration::whereKey($snapshot['catalogueConfigurationId'] ?? 0)->first();
                if (! is_string($entry) || ! preg_match('/^R5-0[1-7]$/', $entry) || $recommendation->subject_key !== 'catalogue:'.$entry || ! $configuration
                    || $configuration->building_id !== $recommendation->forecast->building_id || $configuration->kind !== 'catalogue'
                    || $configuration->subject_key !== $entry || $configuration->status !== 'approved'
                    || $configuration->version !== ($snapshot['catalogueVersion'] ?? null)
                    || $configuration->approved_hash !== ($snapshot['catalogueHash'] ?? null)
                    || $configuration->approved_hash !== app(DssConfigurationWorkflow::class)->hash($configuration)
                    || $configuration->scope_hash !== app(DssContext::class)->scopeHash($recommendation->forecast->building)) {
                    return 'The exact independently reviewed catalogue subject/version is unavailable.';
                }
                $reviewId = $snapshot['reviewConfigurationId'] ?? null;
                if ($reviewId !== null) {
                    $review = DssConfiguration::whereKey($reviewId)->first();
                    if (! $review || $review->building_id !== $configuration->building_id || $review->kind !== 'review'
                        || $review->subject_key !== $entry || $review->status !== 'approved'
                        || $review->version !== ($snapshot['reviewVersion'] ?? null)
                        || $review->approved_hash !== ($snapshot['reviewHash'] ?? null)
                        || $review->approved_hash !== app(DssConfigurationWorkflow::class)->hash($review)) {
                        return 'The exact independently reviewed catalogue subject/version is unavailable.';
                    }
                }
            } else {
                return 'This rule does not support the supplied recommendation subject.';
            }
        }
        if (! in_array($recommendation->decision, ['pending', 'deferred'], true)) {
            return 'Accepted and rejected decisions are final; history cannot be overwritten.';
        }

        return null;
    }
}
