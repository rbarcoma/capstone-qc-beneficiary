import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import type { HistoricalRates } from '@/types';

// Financial values arrive as decimal strings. Do not round-trip through Number.
function decimal(value: string | null) {
    if (value === null) return 'Unavailable';
    const [integer, fraction] = value.split('.');
    return `${integer.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}${fraction === undefined ? '' : `.${fraction}`}`;
}

const money = (value: string | null) =>
    value === null ? 'Unavailable' : `₱${decimal(value)}`;
const rate = (value: string | null) =>
    value === null
        ? 'Undefined — zero import energy'
        : `₱${decimal(value)}/import-kWh`;
const monthLabel = (value: string) => value.slice(0, 7);

const sourceState = (state: string) =>
    ({
        verified: 'Verified, current and reconciled',
        monthly_record_missing: 'Monthly record missing',
        monthly_record_unverified: 'Monthly record unverified',
        billing_evidence_missing: 'Billing evidence missing',
        independent_verification_required:
            'Independent evidence verification required',
        source_changed:
            'Record or identity source changed — new evidence required',
        evidence_changed: 'Evidence changed after verification',
        synthetic_evidence_excluded:
            'Synthetic evidence excluded from this environment',
        service_mapping_unavailable: 'Explicit service mapping unavailable',
        billing_period_mismatch: 'Billing-period month mismatch',
        import_energy_mismatch:
            'Import energy does not match the monthly record',
        missing_financial_inputs: 'Financial inputs incomplete',
        unreconciled_statement: 'Statement reconciliation requires review',
        unreconciled_components: 'Component reconciliation requires review',
        unclassified_component: 'Financial classification requires review',
    })[state] ?? 'Evidence requires review';

export default function HistoricalEffectiveRates({
    history,
}: {
    history: HistoricalRates | null;
}) {
    if (!history) return null;
    return (
        <Card
            className="min-w-0 rounded-[13px] shadow-none"
            role="region"
            aria-labelledby="r1-history-title"
        >
            <CardHeader>
                <CardTitle className="text-[17px]">
                    <h2 id="r1-history-title">
                        R1 · Historical effective rates
                    </h2>
                </CardTitle>
                <Badge variant="secondary">
                    Historical · Not a future tariff
                </Badge>
            </CardHeader>
            <CardContent className="space-y-4 text-xs leading-relaxed">
                <p>
                    Reconciled, independently verified billing evidence for this
                    building only. Gross rate = Total Energy Amount ÷ import
                    kWh. Net-service rate = (Total Energy Amount + signed export
                    credit) ÷ import kWh. Previous balances, separately listed
                    deposits and applied account refunds are excluded.
                </p>
                <p className="text-muted-foreground">
                    Gross service charges retain embedded adjustments, demand,
                    fixed charges and taxes as billed. These are descriptive
                    historical ratios, not marginal savings rates, normalized
                    costs or approved future tariffs. No forecast-cost estimate
                    or budget warning is produced.
                </p>
                <p>
                    Required meters: {history.requiredMeterCount}.{' '}
                    {history.fromMonth && history.throughMonth
                        ? `Recorded window: ${monthLabel(history.fromMonth)}–${monthLabel(history.throughMonth)}.`
                        : 'No recorded billing window.'}
                </p>
                {history.rows.length === 0 ? (
                    <p>No historical billing evidence is available.</p>
                ) : (
                    <>
                        <section
                            aria-label="Weighted historical rates"
                            className="space-y-3 rounded-[9px] bg-secondary p-3"
                        >
                            <h3 className="font-semibold text-primary">
                                Weighted history — complete periods only
                            </h3>
                            <p>
                                Included periods:{' '}
                                {history.includedMonths.length}; excluded
                                incomplete periods:{' '}
                                {history.excludedMonths.length}. Weighted rates
                                use sums of matched charges and import kWh, not
                                averages of rounded rates. A zero-import period
                                retains its charges in a complete window; the
                                window rate requires positive total import kWh.
                            </p>
                            {history.weighted ? (
                                <dl className="grid gap-3 sm:grid-cols-2">
                                    {[
                                        [
                                            'Gross weighted rate',
                                            rate(history.weighted.grossRate),
                                        ],
                                        [
                                            'Net-service weighted rate',
                                            rate(
                                                history.weighted.netServiceRate,
                                            ),
                                        ],
                                        [
                                            'Matched import energy',
                                            `${decimal(history.weighted.importKwh)} kWh`,
                                        ],
                                        [
                                            'Matched gross service amount',
                                            money(history.weighted.grossPhp),
                                        ],
                                    ].map(([label, value]) => (
                                        <div
                                            key={label}
                                            className="min-w-0 break-words"
                                        >
                                            <dt className="text-muted-foreground">
                                                {label}
                                            </dt>
                                            <dd className="mt-1 font-semibold">
                                                {value}
                                            </dd>
                                        </div>
                                    ))}
                                </dl>
                            ) : (
                                <p>
                                    No complete reconciled period is available.
                                </p>
                            )}
                            {history.excludedMonths.length > 0 && (
                                <p>
                                    Excluded months:{' '}
                                    {history.excludedMonths
                                        .map(monthLabel)
                                        .join(', ')}
                                    . No missing period is filled or replaced.
                                </p>
                            )}
                        </section>
                        <div
                            className="overflow-x-auto focus-visible:ring-2 focus-visible:ring-ring"
                            role="region"
                            aria-label="Historical effective-rate table"
                            tabIndex={0}
                        >
                            <table className="w-full min-w-[760px] text-left text-xs">
                                <thead className="bg-secondary text-[11px] text-primary">
                                    <tr>
                                        {[
                                            'Billing month',
                                            'Availability',
                                            'Import kWh',
                                            'Gross service PHP',
                                            'Net service PHP',
                                            'Gross PHP/import-kWh',
                                            'Net PHP/import-kWh',
                                        ].map((heading) => (
                                            <th
                                                key={heading}
                                                scope="col"
                                                className="p-3"
                                            >
                                                {heading}
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody>
                                    {history.rows.map((row) => (
                                        <tr
                                            key={row.month}
                                            className="border-t align-top"
                                        >
                                            <th
                                                scope="row"
                                                className="p-3 font-medium"
                                            >
                                                {monthLabel(row.month)}
                                            </th>
                                            <td className="p-3">
                                                {row.state === 'incomplete'
                                                    ? 'Incomplete — totals withheld'
                                                    : row.state ===
                                                        'zero_import'
                                                      ? 'Reconciled; zero import'
                                                      : 'Verified and reconciled'}
                                            </td>
                                            <td className="p-3">
                                                {decimal(row.importKwh)}
                                            </td>
                                            <td className="p-3">
                                                {money(row.grossPhp)}
                                            </td>
                                            <td className="p-3">
                                                {money(row.netServicePhp)}
                                            </td>
                                            <td className="p-3">
                                                {row.state === 'incomplete'
                                                    ? 'Unavailable'
                                                    : decimal(row.grossRate) ===
                                                        'Unavailable'
                                                      ? 'Undefined — zero import'
                                                      : decimal(row.grossRate)}
                                            </td>
                                            <td className="p-3">
                                                {row.state === 'incomplete'
                                                    ? 'Unavailable'
                                                    : decimal(
                                                            row.netServiceRate,
                                                        ) === 'Unavailable'
                                                      ? 'Undefined — zero import'
                                                      : decimal(
                                                            row.netServiceRate,
                                                        )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        <details className="rounded-[9px] bg-secondary p-3">
                            <summary className="cursor-pointer font-semibold text-primary">
                                R1 source coverage and excluded financial items
                            </summary>
                            <div className="mt-3 space-y-4">
                                {history.rows.map((row) => (
                                    <section
                                        key={row.month}
                                        className="space-y-1 border-b pb-3 last:border-0"
                                    >
                                        <h3 className="font-semibold">
                                            {monthLabel(row.month)}
                                        </h3>
                                        <p>
                                            Signed export credit:{' '}
                                            {money(row.exportCreditPhp)};
                                            separately excluded deposits/other
                                            items: {money(row.excludedOtherPhp)}
                                            ; separately excluded account
                                            credits/refunds:{' '}
                                            {money(row.excludedCreditsPhp)}.
                                        </p>
                                        <ul className="space-y-2">
                                            {row.sources.map((source) => (
                                                <li key={source.meterId}>
                                                    Meter #{source.meterId} ·{' '}
                                                    {sourceState(source.state)}.
                                                    {source.recordId !== null &&
                                                        ` Record #${source.recordId}, version ${source.recordVersion}.`}
                                                    {source.evidenceId !==
                                                        null &&
                                                        ` Evidence #${source.evidenceId}, version ${source.evidenceVersion}.`}
                                                    {source.periodStart &&
                                                        ` Billing period ${source.periodStart}–${source.periodEnd}; source pages ${source.pages?.join(', ')}.`}
                                                </li>
                                            ))}
                                        </ul>
                                    </section>
                                ))}
                            </div>
                        </details>
                    </>
                )}
                <p className="text-muted-foreground">
                    Every registered non-removed meter, including inactive
                    meters, requires current verified records and independently
                    verified matching evidence. Corrections withdraw earlier
                    evidence; an older verified bill is never used instead of a
                    newer draft. As of {new Date(history.asOf).toLocaleString()}
                    .
                </p>
            </CardContent>
        </Card>
    );
}
