<?php

namespace App\Services;

use Illuminate\Support\Carbon;

class DecisionSupportHistory
{
    /**
     * Pure projection of the current authorized inspection, not a rule evaluator.
     * A missing latest-period value never falls back to an older actual/month.
     *
     * @param  array<string, mixed>  $inspection
     * @return array<string, mixed>
     */
    public function read(array $inspection): array
    {
        $months = array_keys($inspection['manifest']['coverage']);
        sort($months);
        $month = $months === [] ? null : Carbon::parse($months[count($months) - 1])->toDateString();
        $base = ['assessedMonth' => $month, 'asOf' => now()->toIso8601String(),
            'sourceFingerprint' => $inspection['fingerprint'], 'referenceMonths' => [], 'consumption' => null, 'meters' => []];
        if ($month === null) {
            return $base;
        }
        $referenceMonths = array_map(fn ($offset) => Carbon::parse($month)->subMonths($offset)->toDateString(), [3, 2, 1]);
        $rows = array_column($inspection['rows'], null, 'month');
        $verified = [];
        foreach ($inspection['manifest']['verified_records'] as $source) {
            $verified[$source['meter_id']][Carbon::parse($source['month'])->toDateString()] = $source;
        }
        $meterIds = array_column($inspection['manifest']['required_meters'], 'id');
        $buildingPeriod = function (string $period) use ($inspection, $rows, $meterIds): array {
            $row = $rows[$period] ?? null;
            $records = $inspection['manifest']['coverage'][$period] ?? [];
            $verifiedIds = array_column(array_filter($records, fn ($record) => $record['status'] === 'verified'), 'meter_id');

            return ['month' => $period, 'state' => $row ? 'verified' : ($records === [] ? 'missing' : 'incomplete'),
                'value' => $row ? (float) $row['kwh'] : null,
                'sources' => $row ? array_map($this->source(...), $row['source_snapshot']) : [],
                'missingMeterIds' => array_values(array_diff($meterIds, array_column($records, 'meter_id'))),
                'unverifiedMeterIds' => array_values(array_diff(array_column($records, 'meter_id'), $verifiedIds))];
        };
        $meters = [];
        foreach ($meterIds as $id) {
            $meterPeriod = function (string $period) use ($inspection, $verified, $id): array {
                $coverage = array_column($inspection['manifest']['coverage'][$period] ?? [], null, 'meter_id');
                $source = $verified[$id][$period] ?? null;
                $value = $source['billed_demand_kw'] ?? null;
                $valid = $value !== null && is_numeric($value) && is_finite((float) $value) && $value >= 0;

                return ['month' => $period,
                    'state' => $source ? ($valid ? 'verified' : 'demand_unavailable') : (isset($coverage[$id]) ? 'unverified' : 'missing'),
                    'value' => $valid ? (float) $value : null, 'sources' => $source ? [$this->source($source)] : [],
                    'missingMeterIds' => [], 'unverifiedMeterIds' => []];
            };
            $meters[] = ['meterId' => $id, ...$this->compare($meterPeriod($month), array_map($meterPeriod, $referenceMonths))];
        }

        return [...$base, 'referenceMonths' => $referenceMonths,
            'consumption' => $this->compare($buildingPeriod($month), array_map($buildingPeriod, $referenceMonths)), 'meters' => $meters];
    }

    /** @param array<string, mixed> $source
     * @return array{recordId: int, meterId: int, version: int}
     */
    private function source(array $source): array
    {
        return ['recordId' => $source['id'], 'meterId' => $source['meter_id'], 'version' => $source['version']];
    }

    /** @param array<string, mixed> $actual
     * @param  list<array<string, mixed>>  $references
     * @return array<string, mixed>
     */
    private function compare(array $actual, array $references): array
    {
        $values = array_column($references, 'value');
        $average = count($values) === 3 && ! in_array(null, $values, true) ? array_sum($values) / 3 : null;
        $difference = $average !== null && $actual['value'] !== null ? $actual['value'] - $average : null;

        return ['actual' => $actual, 'references' => $references,
            'referenceAverage' => $average === null ? null : round($average, 6),
            'difference' => $difference === null ? null : round($difference, 3),
            'absoluteDifference' => $difference === null ? null : round(abs($difference), 3),
            'differencePercent' => $difference !== null && $average > 0 ? round($difference / $average * 100, 3) : null,
            'percentageReason' => $difference === null ? 'comparison_unavailable' : ($average > 0 ? null : 'zero_baseline')];
    }
}
