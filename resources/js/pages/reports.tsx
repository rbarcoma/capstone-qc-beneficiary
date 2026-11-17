import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import ReportController from '@/actions/App/Http/Controllers/ReportController';
import {
    EnergyFilters,
    EnergyFooter,
    EnergyHeader,
    EnergyMetric,
    EnergyNotice,
} from '@/components/energy-workspace';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import type { EnergyBuilding } from '@/types';

type Report = {
    buildingId: number;
    fingerprint: string;
    latestPeriod: string | null;
    datasetVersion: number | null;
    stale: boolean;
    billingRows: number;
    forecastId: number | null;
    recommendationState: string;
    budgetState: string;
    canViewCost: boolean;
};

function ReportExports({ report }: { report: Report | null }) {
    const [pending, setPending] = useState<string | null>(null);
    const [error, setError] = useState('');
    const [result, setResult] = useState('');
    async function download(type: string) {
        if (!report || pending) return;
        setPending(type);
        setError('');
        setResult('');
        try {
            const token = document.cookie
                .split('; ')
                .find((cookie) => cookie.startsWith('XSRF-TOKEN='))
                ?.slice(11);
            const response = await fetch(
                ReportController.download.url({
                    building: report.buildingId,
                    type,
                }),
                {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-XSRF-TOKEN': decodeURIComponent(token ?? ''),
                    },
                    body: JSON.stringify({ fingerprint: report.fingerprint }),
                },
            );
            if (!response.ok) {
                const body = await response.json().catch(() => null);
                throw new Error(
                    body?.errors?.report?.[0] ??
                        body?.errors?.fingerprint?.[0] ??
                        (response.status === 403
                            ? 'Your access changed. Refresh this page.'
                            : 'Export failed. Refresh the page and try again.'),
                );
            }
            const extension = type === 'summary' ? 'pdf' : 'xlsx';
            if (
                !response.headers
                    .get('content-type')
                    ?.includes(
                        type === 'summary'
                            ? 'application/pdf'
                            : 'spreadsheetml',
                    )
            )
                throw new Error(
                    'Unexpected export response. Sign in again and retry.',
                );
            const blob = await response.blob();
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download =
                response.headers
                    .get('content-disposition')
                    ?.match(/filename="([^"]+)"/)?.[1] ??
                `qc-energy-internal-${type}.${extension}`;
            document.body.appendChild(link);
            link.click();
            link.remove();
            setTimeout(() => URL.revokeObjectURL(url), 1000);
            setResult(
                'Internal export prepared and audited. Your browser has been asked to save it.',
            );
        } catch (error) {
            setError(
                error instanceof Error
                    ? error.message
                    : 'Export failed. Please retry.',
            );
        } finally {
            setPending(null);
        }
    }
    const types = [
        {
            type: 'summary',
            name: 'Monthly executive',
            audience: 'Viewer / department heads',
            sections:
                'Latest 12 available months, current forecast, decisions, open actions',
            label: 'Download PDF',
            enabled: !!report,
        },
        {
            type: 'billing',
            name: 'Verified billing data',
            audience: 'Authorized building personnel',
            sections: `Complete verified months, kWh, demand${report?.canViewCost ? ', actual cost' : '; cost excluded'}`,
            label: 'Billing Excel',
            enabled: !!report?.billingRows,
        },
        {
            type: 'evidence',
            name: 'Forecast / model evidence',
            audience: 'Authorized forecast readers',
            sections: 'Current issued method, MAE, RMSE, R², held-out evidence',
            label: 'Evidence Excel',
            enabled: !!report?.forecastId,
        },
    ];
    return (
        <>
            <Card className="min-w-0 rounded-[13px] shadow-none">
                <CardHeader>
                    <CardTitle className="text-[17px]">
                        Available report types
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    <div
                        className="overflow-x-auto focus-visible:ring-2 focus-visible:ring-ring"
                        tabIndex={0}
                        role="region"
                        aria-label="Report catalogue table"
                    >
                        <table className="w-full min-w-[800px] text-left text-xs">
                            <thead className="bg-secondary text-[11px] text-primary">
                                <tr>
                                    {[
                                        'Report',
                                        'Audience',
                                        'Included sections',
                                        'Export',
                                    ].map((title) => (
                                        <th
                                            className="px-3 py-3 font-semibold"
                                            key={title}
                                        >
                                            {title}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {types.map((item) => (
                                    <tr key={item.type} className="border-b">
                                        <td className="px-3 py-4">
                                            {item.name}
                                        </td>
                                        <td className="px-3 py-4">
                                            {item.audience}
                                        </td>
                                        <td className="max-w-80 px-3 py-4">
                                            {item.sections}
                                        </td>
                                        <td className="px-3 py-4">
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                disabled={
                                                    !item.enabled ||
                                                    pending !== null
                                                }
                                                onClick={() =>
                                                    download(item.type)
                                                }
                                            >
                                                {pending === item.type
                                                    ? 'Preparing…'
                                                    : item.label}
                                            </Button>
                                        </td>
                                    </tr>
                                ))}
                                <tr className="border-b">
                                    <td className="px-3 py-4">
                                        Budget planning
                                    </td>
                                    <td className="px-3 py-4">
                                        Budget Officer
                                    </td>
                                    <td className="px-3 py-4">
                                        Approved tariff and budget cycle
                                        required
                                    </td>
                                    <td className="px-3 py-4 text-muted-foreground">
                                        Unavailable
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div className="mt-4 space-y-2 text-xs" aria-live="polite">
                        {!report && (
                            <p>
                                No accessible building is available. No export
                                can be generated.
                            </p>
                        )}
                        {report && (
                            <p className="text-muted-foreground">
                                {report.stale
                                    ? 'Stored dataset superseded; reprocess before exporting billing or forecast evidence.'
                                    : `${report.billingRows} current complete-month billing rows · dataset ${report.datasetVersion ?? 'unavailable'}.`}{' '}
                                Missing data is left unavailable.
                            </p>
                        )}
                        <InputError message={error} role="alert" />
                        {error && (
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => router.reload()}
                            >
                                Refresh report sources
                            </Button>
                        )}
                        {result && (
                            <p role="status" className="text-primary">
                                {result}
                            </p>
                        )}
                    </div>
                </CardContent>
            </Card>
        </>
    );
}

export default function Reports() {
    const { buildings, selectedBuilding, report } = usePage()
        .props as unknown as {
        buildings: EnergyBuilding[];
        selectedBuilding: string | null;
        report: Report | null;
    };
    return (
        <>
            <Head title="Reports" />
            <div className="flex flex-1 flex-col gap-6 px-7 pt-6 pb-10">
                <EnergyHeader
                    title="Reports"
                    description="Executive summaries, detailed billing exports, forecasting evidence and audit-aware downloads."
                />
                <EnergyFilters
                    buildings={buildings}
                    selected={selectedBuilding}
                    latest={report?.latestPeriod ?? null}
                    onChange={(building) =>
                        router.get(
                            ReportController.index.url(),
                            { building },
                            { preserveScroll: true },
                        )
                    }
                />
                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <EnergyMetric
                        title="Executive summary"
                        value="PDF"
                        caption="Internal generated snapshot"
                    />
                    <EnergyMetric
                        title="Detailed data"
                        value="Excel"
                        caption="Current authorized verified data"
                    />
                    <EnergyMetric
                        title="Budget view"
                        value="Unavailable"
                        caption="Tariff source pending"
                    />
                    <EnergyMetric
                        title="Classification"
                        value="Internal"
                        caption="Authorized institutional use"
                    />
                </div>
                <ReportExports
                    key={`${selectedBuilding}-${report?.fingerprint}`}
                    report={report}
                />
                <EnergyNotice title="Export scope and accountability">
                    <p>
                        PDF and Excel exports use current complete verified
                        datasets and current issued forecasts. Draft, partial
                        and superseded values are excluded. Download preparation
                        is audited; receipt by the browser is not proof of
                        distribution.
                    </p>
                    <p className="mt-2">
                        {report?.recommendationState ??
                            'C5 rule generation remains disabled.'}{' '}
                        {report?.budgetState ??
                            'Budget estimates require approved tariff inputs.'}{' '}
                        Open accepted actions are historical follow-through, not
                        automatically measured savings.{' '}
                        {report &&
                            !report.canViewCost &&
                            'Cost fields are excluded for your role.'}
                    </p>
                </EnergyNotice>
                <EnergyFooter />
            </div>
        </>
    );
}
