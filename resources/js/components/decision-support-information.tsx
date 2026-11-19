import { formatEnergy } from '@/components/energy-workspace';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import type { DecisionReadiness, ForecastComparison } from '@/types';

export default function DecisionSupportInformation({
    comparison,
    readiness,
    showCost,
    tariffActive,
}: {
    comparison: ForecastComparison | null;
    readiness: DecisionReadiness | null;
    showCost: boolean;
    tariffActive: boolean;
}) {
    if (!comparison || !readiness) {
        return (
            <p className="text-sm text-muted-foreground">
                No building is available for monthly review.
            </p>
        );
    }

    return (
        <div className="grid min-w-0 items-start gap-4 lg:auto-cols-fr lg:grid-flow-col">
            <ReadinessCard readiness={readiness} />
            <ComparisonCard comparison={comparison} />
            {showCost && (
                <Card className="min-w-0 gap-2 rounded-xl shadow-none">
                    <CardHeader>
                        <CardTitle>
                            <h2 className="text-sm">Forecast cost</h2>
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="text-sm">
                        {tariffActive
                            ? 'A reviewed tariff is active for R1. Any matching estimate appears in the recommendations below.'
                            : 'Unavailable — no active tariff.'}
                    </CardContent>
                </Card>
            )}
        </div>
    );
}

function ComparisonCard({ comparison }: { comparison: ForecastComparison }) {
    const signed = (value: number) =>
        `${value > 0 ? '+' : ''}${formatEnergy(value)}`;

    return (
        <Card
            className="min-w-0 gap-2 rounded-xl shadow-none"
            role="region"
            aria-labelledby="r2-title"
        >
            <CardHeader>
                <CardTitle className="text-[17px]">
                    <h2 id="r2-title" className="text-sm">
                        Forecast comparison
                    </h2>
                </CardTitle>
            </CardHeader>
            <CardContent className="space-y-4 text-xs leading-relaxed">
                {comparison.state === 'available' ? (
                    <>
                        <dl className="grid grid-cols-2 gap-4">
                            <div>
                                <dt className="text-muted-foreground">
                                    Forecast ·{' '}
                                    {comparison.targetMonth.slice(0, 7)}
                                </dt>
                                <dd className="mt-1 font-semibold">
                                    {formatEnergy(comparison.forecastKwh)} kWh
                                </dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">
                                    Verified ·{' '}
                                    {comparison.referenceMonth.slice(0, 7)}
                                </dt>
                                <dd className="mt-1 font-semibold">
                                    {formatEnergy(comparison.referenceKwh)} kWh
                                </dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">
                                    Difference
                                </dt>
                                <dd className="mt-1 font-semibold">
                                    {signed(comparison.differenceKwh)} kWh
                                </dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">
                                    Percentage difference
                                </dt>
                                <dd className="mt-1 font-semibold">
                                    {comparison.differencePercent === null
                                        ? 'Undefined — verified reference is zero'
                                        : `${signed(comparison.differencePercent)}%`}
                                </dd>
                            </div>
                        </dl>
                        <details className="min-w-0 rounded-[9px] bg-secondary p-3">
                            <summary className="cursor-pointer font-semibold text-primary">
                                View comparison evidence
                            </summary>
                            <div className="mt-2 space-y-2 break-words">
                                <p>
                                    Same building; latest complete verified
                                    month. Difference = forecast − verified
                                    reference. Percentage = difference ÷
                                    positive reference × 100.
                                </p>
                                <p>
                                    Run #{comparison.forecastId} · Dataset #
                                    {comparison.datasetId}, version{' '}
                                    {comparison.datasetVersion} · Model{' '}
                                    {comparison.modelVersion}
                                </p>
                                <p className="break-all">
                                    Source fingerprint:{' '}
                                    {comparison.datasetFingerprint}
                                </p>
                                <ul className="list-disc space-y-1 pl-4">
                                    {comparison.referenceRecords.map(
                                        (source) => (
                                            <li key={source.recordId}>
                                                Meter #{source.meterId} ·
                                                Verified record #
                                                {source.recordId}, version{' '}
                                                {source.version}
                                            </li>
                                        ),
                                    )}
                                </ul>
                            </div>
                        </details>
                    </>
                ) : (
                    <p>
                        {comparison.state === 'current_forecast_unavailable'
                            ? 'No current forecast to compare.'
                            : 'No matching verified month for this forecast.'}
                    </p>
                )}
            </CardContent>
        </Card>
    );
}

function ReadinessCard({ readiness }: { readiness: DecisionReadiness }) {
    const coverage = readiness.coverage;
    return (
        <Card
            className="min-w-0 gap-2 rounded-xl shadow-none"
            role="region"
            aria-labelledby="r7-title"
        >
            <CardHeader>
                <CardTitle className="text-[17px]">
                    <h2 id="r7-title" className="text-sm">
                        Data readiness
                    </h2>
                </CardTitle>
            </CardHeader>
            <CardContent className="space-y-4 text-xs leading-relaxed">
                <p className="font-semibold">
                    {readiness.state === 'current_forecast'
                        ? 'Current forecast available'
                        : readiness.state === 'eligible_not_issued'
                          ? 'Eligible inputs · forecast not issued'
                          : 'Forecast inputs not ready'}
                </p>
                {readiness.state !== 'current_forecast' &&
                    readiness.state !== 'eligible_not_issued' && (
                        <p>{readiness.notices[0]?.message}</p>
                    )}
                <details className="min-w-0 rounded-[9px] bg-secondary p-3">
                    <summary className="cursor-pointer font-semibold text-primary">
                        View coverage
                    </summary>
                    <div className="mt-2 space-y-3">
                        <ul className="list-disc space-y-2 pl-4">
                            {readiness.notices.map((notice) => (
                                <li key={notice.code}>{notice.message}</li>
                            ))}
                        </ul>
                        <p>
                            Complete observations: {readiness.observations} /{' '}
                            {readiness.requiredObservations} required by the
                            existing technical guard. History must be contiguous
                            with required weather; the count alone does not
                            establish eligibility.
                        </p>
                        <p>
                            As of {new Date(readiness.asOf).toLocaleString()}.
                        </p>
                        <p>
                            Required meters: {coverage.requiredMeterCount}. All
                            registered non-removed meters, including inactive
                            meters, follow the existing completeness rule.
                        </p>
                        {coverage.fromMonth && coverage.throughMonth ? (
                            <>
                                <p>
                                    Recorded window:{' '}
                                    {coverage.fromMonth.slice(0, 7)} to{' '}
                                    {coverage.throughMonth.slice(0, 7)}.
                                    Verified meter-months:{' '}
                                    {coverage.verifiedMeterPeriods} /{' '}
                                    {coverage.expectedMeterPeriods}. Missing:{' '}
                                    {coverage.missingMeterPeriods}; unverified:{' '}
                                    {coverage.unverifiedMeterPeriods}.
                                </p>
                                {coverage.incompleteMonths.length > 0 && (
                                    <div
                                        className="overflow-x-auto focus-visible:ring-2 focus-visible:ring-ring"
                                        role="region"
                                        aria-label="Incomplete meter-month coverage"
                                        tabIndex={0}
                                    >
                                        <table className="w-full min-w-[400px] text-left text-xs">
                                            <thead className="text-[11px] text-primary">
                                                <tr>
                                                    <th className="p-2">
                                                        Month
                                                    </th>
                                                    <th className="p-2">
                                                        Verified meters
                                                    </th>
                                                    <th className="p-2">
                                                        Missing meter IDs
                                                    </th>
                                                    <th className="p-2">
                                                        Unverified meter IDs
                                                    </th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {coverage.incompleteMonths.map(
                                                    (month) => (
                                                        <tr
                                                            key={month.month}
                                                            className="border-t"
                                                        >
                                                            <td className="p-2">
                                                                {month.month.slice(
                                                                    0,
                                                                    7,
                                                                )}
                                                            </td>
                                                            <td className="p-2">
                                                                {
                                                                    month.verifiedMeterCount
                                                                }{' '}
                                                                /{' '}
                                                                {
                                                                    coverage.requiredMeterCount
                                                                }
                                                            </td>
                                                            <td className="p-2">
                                                                {month.missingMeterIds.join(
                                                                    ', ',
                                                                ) || 'None'}
                                                            </td>
                                                            <td className="p-2">
                                                                {month.unverifiedMeterIds.join(
                                                                    ', ',
                                                                ) || 'None'}
                                                            </td>
                                                        </tr>
                                                    ),
                                                )}
                                            </tbody>
                                        </table>
                                    </div>
                                )}
                            </>
                        ) : (
                            <p>
                                Coverage is not assessable until a recorded
                                billing window exists.
                            </p>
                        )}
                        {readiness.incompleteWeatherMonths.length > 0 && (
                            <p>
                                Incomplete observed weather months:{' '}
                                {readiness.incompleteWeatherMonths
                                    .map((month) => month.slice(0, 7))
                                    .join(', ')}
                                .
                            </p>
                        )}
                        <p>
                            Latest complete verified month:{' '}
                            {readiness.latestCompleteMonth?.slice(0, 7) ??
                                'Unavailable'}
                            .
                        </p>
                    </div>
                </details>
            </CardContent>
        </Card>
    );
}
