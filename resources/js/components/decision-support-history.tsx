import { formatEnergy } from '@/components/energy-workspace';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import type {
    ExploratoryHistory,
    HistoryComparison,
    HistoryPeriod,
} from '@/types';

const periodLabel = (state: HistoryPeriod['state']) =>
    ({
        verified: 'Verified',
        missing: 'Missing period',
        incomplete: 'Incomplete meter coverage',
        unverified: 'Unverified bill',
        demand_unavailable: 'Billed demand unavailable',
    })[state];

export function ComparisonNumbers({
    comparison,
    unit,
}: {
    comparison: HistoryComparison;
    unit: string;
}) {
    const signed = (value: number | null) =>
        value === null
            ? 'Unavailable'
            : `${value > 0 ? '+' : ''}${formatEnergy(value)} ${unit}`;
    const values = [
        [
            'Actual',
            comparison.actual.value === null
                ? periodLabel(comparison.actual.state)
                : `${formatEnergy(comparison.actual.value)} ${unit}`,
        ],
        [
            'Three-month reference average',
            comparison.referenceAverage === null
                ? 'Unavailable — all 3 months required'
                : `${formatEnergy(comparison.referenceAverage, 6)} ${unit}`,
        ],
        ['Signed difference (actual − average)', signed(comparison.difference)],
        [
            'Absolute difference |actual − average|',
            comparison.absoluteDifference === null
                ? 'Unavailable'
                : `${formatEnergy(comparison.absoluteDifference)} ${unit}`,
        ],
        [
            'Percentage difference',
            comparison.differencePercent === null
                ? comparison.percentageReason === 'zero_baseline'
                    ? 'Undefined — reference average is zero'
                    : 'Unavailable'
                : `${comparison.differencePercent > 0 ? '+' : ''}${formatEnergy(comparison.differencePercent)}%`,
        ],
    ];
    return (
        <dl className="grid grid-cols-2 gap-4 sm:grid-cols-3">
            {values.map(([label, value]) => (
                <div key={label} className="min-w-0">
                    <dt className="text-muted-foreground">{label}</dt>
                    <dd className="mt-1 font-semibold">{value}</dd>
                </div>
            ))}
        </dl>
    );
}

export function SourceEvidence({
    comparison,
    unit,
    label,
}: {
    comparison: HistoryComparison;
    unit: string;
    label: string;
}) {
    return (
        <details className="min-w-0 rounded-[9px] bg-secondary p-3">
            <summary className="cursor-pointer font-semibold text-primary">
                {label}
            </summary>
            <div
                className="mt-3 overflow-x-auto focus-visible:ring-2 focus-visible:ring-ring"
                role="region"
                aria-label={label}
                tabIndex={0}
            >
                <table className="w-full min-w-[650px] text-left text-xs">
                    <thead className="text-[11px] text-primary">
                        <tr>
                            {[
                                'Month',
                                'Use',
                                `Value (${unit})`,
                                'Availability',
                                'Verified source evidence',
                            ].map((heading) => (
                                <th key={heading} scope="col" className="p-2">
                                    {heading}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {[...comparison.references, comparison.actual].map(
                            (period, index) => (
                                <tr
                                    key={period.month}
                                    className="border-t align-top"
                                >
                                    <th scope="row" className="p-2 font-normal">
                                        {period.month.slice(0, 7)}
                                    </th>
                                    <td className="p-2">
                                        {index === 3
                                            ? 'Assessed actual'
                                            : 'Reference'}
                                    </td>
                                    <td className="p-2">
                                        {formatEnergy(period.value)}
                                    </td>
                                    <td className="p-2">
                                        {periodLabel(period.state)}
                                    </td>
                                    <td className="p-2">
                                        {period.sources.length > 0 ? (
                                            <ul className="space-y-1">
                                                {period.sources.map(
                                                    (source) => (
                                                        <li
                                                            key={
                                                                source.recordId
                                                            }
                                                        >
                                                            Meter #
                                                            {source.meterId} ·
                                                            Record #
                                                            {source.recordId},
                                                            version{' '}
                                                            {source.version}
                                                        </li>
                                                    ),
                                                )}
                                            </ul>
                                        ) : (
                                            <p>
                                                No complete verified value used.
                                            </p>
                                        )}
                                        {period.missingMeterIds.length > 0 && (
                                            <p>
                                                Missing meter IDs:{' '}
                                                {period.missingMeterIds.join(
                                                    ', ',
                                                )}
                                                .
                                            </p>
                                        )}
                                        {period.unverifiedMeterIds.length >
                                            0 && (
                                            <p>
                                                Unverified meter IDs:{' '}
                                                {period.unverifiedMeterIds.join(
                                                    ', ',
                                                )}
                                                .
                                            </p>
                                        )}
                                    </td>
                                </tr>
                            ),
                        )}
                    </tbody>
                </table>
            </div>
        </details>
    );
}

export default function DecisionSupportHistory({
    history,
}: {
    history: ExploratoryHistory | null;
}) {
    if (!history) return null;
    return (
        <div className="space-y-6">
            <Card
                className="min-w-0 rounded-[13px] shadow-none"
                role="region"
                aria-labelledby="r3-title"
            >
                <CardHeader>
                    <CardTitle className="text-[17px]">
                        <h2 id="r3-title">
                            R3 · Historical consumption comparison
                        </h2>
                    </CardTitle>
                    <Badge variant="secondary">
                        Unapproved exploratory method
                    </Badge>
                </CardHeader>
                <CardContent className="space-y-4 text-xs leading-relaxed">
                    <p>
                        Same-building verified kWh versus the arithmetic mean of
                        the three immediately preceding calendar months. This is
                        an exploratory comparison, not an approved QC City Hall
                        anomaly method.
                    </p>
                    {history.consumption ? (
                        <>
                            <p className="font-semibold">
                                Assessed period:{' '}
                                {history.assessedMonth?.slice(0, 7)} · Latest
                                recorded period
                            </p>
                            <ComparisonNumbers
                                comparison={history.consumption}
                                unit="kWh"
                            />
                            <SourceEvidence
                                comparison={history.consumption}
                                unit="kWh"
                                label="R3 source evidence"
                            />
                        </>
                    ) : (
                        <p>
                            No recorded billing period is available for an
                            exploratory comparison.
                        </p>
                    )}
                    <p className="text-muted-foreground">
                        Every required meter must be verified for each building
                        total. Partial totals are withheld. Missing months are
                        not skipped or filled, and an incomplete latest period
                        never falls back to an older actual. No weather/seasonal
                        adjustment, anomaly classification, priority or action
                        is inferred.
                    </p>
                </CardContent>
            </Card>
            <Card
                className="min-w-0 rounded-[13px] shadow-none"
                role="region"
                aria-labelledby="r4-title"
            >
                <CardHeader>
                    <CardTitle className="text-[17px]">
                        <h2 id="r4-title">
                            R4 · Historical billed demand comparison
                        </h2>
                    </CardTitle>
                    <Badge variant="secondary">
                        Unapproved exploratory method
                    </Badge>
                </CardHeader>
                <CardContent className="space-y-4 text-xs leading-relaxed">
                    <p>
                        Each meter's verified billed kW versus its own three
                        immediately preceding calendar months. The assessed
                        period matches R3. Each meter is checked independently,
                        even when building coverage is incomplete.
                    </p>
                    {history.meters.length === 0 && (
                        <p>
                            No recorded meter period is available for an
                            exploratory comparison.
                        </p>
                    )}
                    {history.meters.map((comparison) => (
                        <section
                            key={comparison.meterId}
                            className="space-y-4 border-t pt-4"
                            aria-labelledby={`r4-meter-${comparison.meterId}`}
                        >
                            <h3
                                id={`r4-meter-${comparison.meterId}`}
                                className="font-semibold"
                            >
                                Meter #{comparison.meterId} ·{' '}
                                {history.assessedMonth?.slice(0, 7)}
                            </h3>
                            <ComparisonNumbers
                                comparison={comparison}
                                unit="kW"
                            />
                            <SourceEvidence
                                comparison={comparison}
                                unit="kW"
                                label={`R4 source evidence for meter ${comparison.meterId}`}
                            />
                        </section>
                    ))}
                    <p className="text-muted-foreground">
                        No meters are summed into a simultaneous building peak.
                        Monthly billed demand is not a future peak prediction.
                        Missing demand is not zero; a zero reference average
                        leaves the percentage undefined. No demand-spike
                        classification, priority or recommendation is generated.
                    </p>
                    <details className="min-w-0 rounded-[9px] bg-secondary p-3">
                        <summary className="cursor-pointer font-semibold text-primary">
                            Exploratory calculation method
                        </summary>
                        <div className="mt-2 space-y-2 break-words">
                            <p>
                                Reference average = (value[m−3] + value[m−2] +
                                value[m−1]) ÷ 3. All three verified values are
                                required.
                            </p>
                            <p>
                                Signed difference = actual − average; absolute
                                difference = |actual − average|; percentage =
                                100 × (actual − average) ÷ positive average.
                            </p>
                            <p>
                                Averages display up to six decimals; differences
                                and percentages display up to three.
                                Calculations use the unrounded average.
                            </p>
                            <p>
                                As of {new Date(history.asOf).toLocaleString()}.
                                These actual-only calculations do not require an
                                issued forecast or processed dataset.
                            </p>
                            <p className="break-all">
                                Current source fingerprint:{' '}
                                {history.sourceFingerprint}
                            </p>
                        </div>
                    </details>
                </CardContent>
            </Card>
        </div>
    );
}
