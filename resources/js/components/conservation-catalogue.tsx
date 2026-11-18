import {
    ComparisonNumbers,
    SourceEvidence,
} from '@/components/decision-support-history';
import { formatEnergy } from '@/components/energy-workspace';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import type {
    CatalogueRequirement,
    ConservationCatalogueData,
    ExploratoryHistory,
    ForecastComparison,
} from '@/types';

const requirementLabel = (state: CatalogueRequirement['state']) =>
    ({
        available: 'Available system evidence',
        unavailable: 'Unavailable system evidence',
        not_assessed: 'Not supplied / not assessed by this prototype',
    })[state];

function CatalogueEvidence({
    entry,
    comparison,
    history,
}: {
    entry: ConservationCatalogueData['entries'][number];
    comparison: ForecastComparison;
    history: ExploratoryHistory;
}) {
    return (
        <div className="space-y-4">
            {entry.evidenceRules.map((rule) => (
                <section
                    key={rule}
                    className="space-y-3 border-t pt-3"
                    aria-labelledby={`${entry.id}-${rule}-title`}
                >
                    <h4
                        id={`${entry.id}-${rule}-title`}
                        className="font-semibold"
                    >
                        {rule} evidence for {entry.id}
                    </h4>
                    {rule === 'R2' &&
                        (comparison.state === 'available' ? (
                            <div className="space-y-2">
                                <p>
                                    Forecast{' '}
                                    {comparison.targetMonth.slice(0, 7)}:{' '}
                                    {formatEnergy(comparison.forecastKwh)} kWh;
                                    verified reference{' '}
                                    {comparison.referenceMonth.slice(0, 7)}:{' '}
                                    {formatEnergy(comparison.referenceKwh)} kWh.
                                    Signed difference:{' '}
                                    {formatEnergy(comparison.differenceKwh)}{' '}
                                    kWh; percentage:{' '}
                                    {comparison.differencePercent === null
                                        ? 'Undefined — zero reference'
                                        : `${formatEnergy(comparison.differencePercent)}%`}
                                    .
                                </p>
                                <p>
                                    Current run #{comparison.forecastId} ·
                                    Dataset #{comparison.datasetId}, version{' '}
                                    {comparison.datasetVersion}.
                                </p>
                                <p className="break-all">
                                    Source: {comparison.datasetFingerprint} ·
                                    Model: {comparison.modelVersion}
                                </p>
                                <ul className="list-disc pl-4">
                                    {comparison.referenceRecords.map(
                                        (record) => (
                                            <li key={record.recordId}>
                                                Meter #{record.meterId} ·
                                                Verified record #
                                                {record.recordId}, version{' '}
                                                {record.version}
                                            </li>
                                        ),
                                    )}
                                </ul>
                                <p className="text-muted-foreground">
                                    Descriptive forecast comparison; no
                                    operational cause or conservation need
                                    inferred.
                                </p>
                            </div>
                        ) : (
                            <p>
                                R2 comparison unavailable. A current eligible
                                issued forecast and matching verified reference
                                are required; historical values are not
                                substituted.
                            </p>
                        ))}
                    {rule === 'R3' &&
                        (history.consumption ? (
                            <>
                                <p>
                                    Assessed actual:{' '}
                                    {history.assessedMonth?.slice(0, 7)}.
                                    Unapproved exploratory
                                    three-prior-calendar-month mean, not an
                                    anomaly classification.
                                </p>
                                <ComparisonNumbers
                                    comparison={history.consumption}
                                    unit="kWh"
                                />
                                <SourceEvidence
                                    comparison={history.consumption}
                                    unit="kWh"
                                    label={`${entry.id} R3 source periods`}
                                />
                            </>
                        ) : (
                            <p>
                                R3 evidence unavailable: no recorded building
                                period. No actual or reference value is
                                invented.
                            </p>
                        ))}
                    {rule === 'R4' &&
                        (history.meters.length > 0 ? (
                            history.meters.map((meter) => (
                                <div
                                    key={meter.meterId}
                                    className="space-y-3 border-t pt-3"
                                >
                                    <p className="font-semibold">
                                        Meter #{meter.meterId} ·{' '}
                                        {history.assessedMonth?.slice(0, 7)}
                                    </p>
                                    <p>
                                        Unapproved exploratory same-meter mean;
                                        no demand spike, timing or equipment
                                        overlap inferred.
                                    </p>
                                    <ComparisonNumbers
                                        comparison={meter}
                                        unit="kW"
                                    />
                                    <SourceEvidence
                                        comparison={meter}
                                        unit="kW"
                                        label={`${entry.id} R4 source periods for meter ${meter.meterId}`}
                                    />
                                </div>
                            ))
                        ) : (
                            <p>
                                R4 evidence unavailable: no recorded meter
                                period.
                            </p>
                        ))}
                </section>
            ))}
            <p className="break-all text-muted-foreground">
                Current inspection fingerprint: {history.sourceFingerprint}
            </p>
        </div>
    );
}

export default function ConservationCatalogue({
    catalogue,
    comparison,
    history,
}: {
    catalogue: ConservationCatalogueData | null;
    comparison: ForecastComparison | null;
    history: ExploratoryHistory | null;
}) {
    if (!catalogue || !comparison || !history) return null;
    return (
        <Card
            className="min-w-0 rounded-[13px] shadow-none"
            role="region"
            aria-labelledby="r5-title"
        >
            <CardHeader>
                <CardTitle className="text-[17px]">
                    <h2 id="r5-title">R5 · Conservation catalogue</h2>
                </CardTitle>
                <Badge variant="secondary">
                    Unapproved · review-only prototype
                </Badge>
            </CardHeader>
            <CardContent className="space-y-5 text-xs leading-relaxed">
                <p>
                    These are topics for review. None is selected or ranked;
                    available evidence does not establish eligibility or
                    savings.
                </p>
                <div className="divide-y rounded-[9px] border">
                    {catalogue.entries.map((entry) => (
                        <details key={entry.id} className="min-w-0 p-3">
                            <summary className="cursor-pointer text-sm font-semibold text-primary">
                                {entry.id} · {entry.title}
                            </summary>
                            <div className="mt-3 space-y-2">
                                <p>{entry.description}</p>
                                <p>{entry.eligibility}</p>
                                <p className="text-muted-foreground">
                                    Requirements unavailable or not assessed:{' '}
                                    {entry.missingRequirements.length}. This is
                                    not an approval score.
                                </p>
                                <div className="mt-3 space-y-4">
                                    <div>
                                        <h4 className="font-semibold">
                                            Proposed eligibility checklist
                                        </h4>
                                        <ul className="mt-2 list-disc space-y-2 pl-4">
                                            {entry.requirements.map(
                                                (requirement) => (
                                                    <li key={requirement.label}>
                                                        <span className="font-semibold">
                                                            {requirementLabel(
                                                                requirement.state,
                                                            )}
                                                            :
                                                        </span>{' '}
                                                        {requirement.label}
                                                    </li>
                                                ),
                                            )}
                                        </ul>
                                    </div>
                                    <div>
                                        <h4 className="font-semibold">
                                            Limitations
                                        </h4>
                                        <ul className="mt-2 list-disc space-y-2 pl-4">
                                            {entry.limitations.map(
                                                (limitation) => (
                                                    <li key={limitation}>
                                                        {limitation}
                                                    </li>
                                                ),
                                            )}
                                        </ul>
                                    </div>
                                    <div>
                                        <h4 className="font-semibold">
                                            Proposed approval owners — names and
                                            authority unconfirmed
                                        </h4>
                                        <ul className="mt-2 list-disc space-y-2 pl-4">
                                            {entry.proposedOwners.map(
                                                (owner) => (
                                                    <li key={owner}>{owner}</li>
                                                ),
                                            )}
                                        </ul>
                                    </div>
                                    <details className="min-w-0 border-t pt-3">
                                        <summary className="cursor-pointer font-semibold text-primary">
                                            {entry.id} current evidence
                                        </summary>
                                        <div className="mt-3">
                                            <CatalogueEvidence
                                                entry={entry}
                                                comparison={comparison}
                                                history={history}
                                            />
                                        </div>
                                    </details>
                                </div>
                            </div>
                        </details>
                    ))}
                </div>
                <p className="text-muted-foreground">
                    Catalogue {catalogue.version} · As of{' '}
                    {new Date(catalogue.asOf).toLocaleString()}.
                </p>
            </CardContent>
        </Card>
    );
}
