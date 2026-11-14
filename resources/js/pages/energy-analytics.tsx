import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AnalyticsController from '@/actions/App/Http/Controllers/AnalyticsController';
import {
    EnergyFilters,
    EnergyFooter,
    EnergyHeader,
    EnergyMetric,
    EnergyNotice,
    formatEnergy,
} from '@/components/energy-workspace';
import { SyntheticDemoPreview } from '@/components/synthetic-demo-preview';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import type { AnalyticsData, EnergyBuilding } from '@/types';

export default function EnergyAnalytics() {
    const {
        buildings,
        selectedBuildingCode,
        analytics: data,
    } = usePage().props as unknown as {
        buildings: EnergyBuilding[];
        selectedBuildingCode: string | null;
        analytics: AnalyticsData | null;
    };
    const [demo, setDemo] = useState(false);
    const rows = data?.rows ?? [];
    const total =
        rows.length > 0 ? rows.reduce((sum, row) => sum + row.kwh, 0) : null;
    const demands = rows
        .map((row) => row.billedDemandKw)
        .filter((value): value is number => value !== null);
    const chart = rows.slice(-24);
    const maximum = Math.max(1, ...chart.map((row) => row.kwh));
    return (
        <>
            <Head title="Energy Analytics" />
            <div className="flex flex-1 flex-col gap-6 px-7 pt-6 pb-10">
                <EnergyHeader
                    title="Energy Analytics"
                    description="Building-level trends, change indicators and verified consumption evidence."
                    actions={
                        <>
                            <Button
                                variant="outline"
                                onClick={() => setDemo(true)}
                                disabled={demo}
                            >
                                Load synthetic demo
                            </Button>
                            {demo && (
                                <Button
                                    variant="outline"
                                    onClick={() => setDemo(false)}
                                >
                                    Reset demo
                                </Button>
                            )}
                        </>
                    }
                />
                <EnergyFilters
                    buildings={buildings}
                    selected={selectedBuildingCode}
                    latest={data?.summary?.month ?? null}
                    onChange={(building) =>
                        router.get(AnalyticsController.index.url(), {
                            building,
                        })
                    }
                />
                {demo && (
                    <SyntheticDemoPreview onReset={() => setDemo(false)} />
                )}
                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <EnergyMetric
                        title="Verified energy"
                        value={
                            total === null
                                ? 'Unavailable'
                                : `${formatEnergy(total)} kWh`
                        }
                        caption={
                            data?.dataset
                                ? `Dataset v${data.dataset.version} · ${rows.length} complete periods`
                                : 'Current processed dataset required'
                        }
                    />
                    <EnergyMetric
                        title="Average monthly"
                        value={
                            total === null
                                ? '—'
                                : `${formatEnergy(total / rows.length)} kWh`
                        }
                    />
                    <EnergyMetric
                        title="MoM change"
                        value={
                            data?.summary?.momPercent == null
                                ? '—'
                                : `${formatEnergy(data.summary.momPercent)}%`
                        }
                        caption="Latest vs exact previous calendar month"
                    />
                    <EnergyMetric
                        title="Peak billed demand"
                        value={
                            demands.length === 0
                                ? 'Unavailable'
                                : `${formatEnergy(Math.max(...demands))} kW`
                        }
                        caption="Single-meter billed demand only"
                    />
                </div>
                {rows.length === 0 && (
                    <EnergyNotice
                        title={
                            data?.stale
                                ? 'Dataset is out of date'
                                : 'No current verified building dataset'
                        }
                        warning
                    >
                        Upload and independently verify all required meter
                        records, then ask an Energy Manager to process the
                        building. Superseded data is not shown.
                    </EnergyNotice>
                )}
                <div className="grid gap-4 lg:grid-cols-[minmax(0,1.84fr)_minmax(0,1fr)]">
                    <Card className="min-w-0 rounded-[13px] shadow-none">
                        <CardHeader>
                            <CardTitle className="text-[17px]">
                                Actual monthly electricity use
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="mb-4 text-xs text-muted-foreground">
                                Only complete verified building periods are
                                actual values. Latest 24 available periods; gaps
                                are not filled.
                            </p>
                            {chart.length === 0 ? (
                                <div className="flex h-28 items-center justify-center rounded-[15px] border text-sm">
                                    No verified values to chart
                                </div>
                            ) : (
                                <div className="overflow-x-auto">
                                    <div
                                        role="img"
                                        aria-label="Verified monthly kWh bar chart"
                                        className="flex min-w-max items-end gap-3 border-b pt-4"
                                    >
                                        {chart.map((row) => (
                                            <div
                                                key={row.month}
                                                className="flex w-14 shrink-0 flex-col items-center gap-2"
                                            >
                                                <span className="text-[10px]">
                                                    {formatEnergy(row.kwh)}
                                                </span>
                                                <div
                                                    className="w-9 rounded-t bg-primary"
                                                    style={{
                                                        height: `${(row.kwh / maximum) * 160}px`,
                                                    }}
                                                />
                                                <span className="pb-2 text-[10px] text-muted-foreground">
                                                    {row.month.slice(0, 7)}
                                                </span>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                    <Card className="rounded-[13px] shadow-none">
                        <CardHeader>
                            <CardTitle className="text-[17px]">
                                Insight details
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <ul className="space-y-5 text-[12.5px]">
                                <li>
                                    Year-over-year:{' '}
                                    {data?.summary?.yoyPercent == null
                                        ? 'Unavailable without a comparable prior-year period'
                                        : `${formatEnergy(data.summary.yoyPercent)}%`}
                                </li>
                                <li>
                                    Latest kWh/m²:{' '}
                                    {formatEnergy(data?.summary?.kwhPerM2)}{' '}
                                    (requires known positive floor area)
                                </li>
                                <li>
                                    Latest load factor:{' '}
                                    {formatEnergy(data?.summary?.loadFactor, 6)}{' '}
                                    (single meter, calendar-month basis)
                                </li>
                                {data?.canViewCost && (
                                    <li>
                                        Latest actual bill cost:{' '}
                                        {data.summary?.costPhp == null
                                            ? 'Not supplied for all meters'
                                            : `PHP ${formatEnergy(data.summary.costPhp, 2)}`}
                                    </li>
                                )}
                                <li>
                                    Complete weather:{' '}
                                    {data?.dataset?.weather_complete_count ?? 0}
                                    /{rows.length} periods
                                </li>
                            </ul>
                        </CardContent>
                    </Card>
                </div>
                <Card className="min-w-0 rounded-[13px] shadow-none">
                    <CardHeader>
                        <CardTitle className="text-[17px]">
                            Verified monthly evidence
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    {[
                                        'Month',
                                        'Actual kWh',
                                        'MoM %',
                                        'YoY %',
                                        ...(data?.canViewCost
                                            ? ['Actual bill PHP']
                                            : []),
                                        'kWh/m²',
                                        'Load factor',
                                        'Weather',
                                    ].map((title) => (
                                        <TableHead
                                            key={title}
                                            className="bg-secondary text-[11px] text-primary"
                                        >
                                            {title}
                                        </TableHead>
                                    ))}
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {rows.length === 0 ? (
                                    <TableRow>
                                        <TableCell
                                            colSpan={data?.canViewCost ? 8 : 7}
                                            className="py-5 text-xs text-muted-foreground"
                                        >
                                            No records available. Upload, verify
                                            and process data to populate this
                                            view.
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    [...rows].reverse().map((row) => (
                                        <TableRow key={row.month}>
                                            <TableCell className="text-xs">
                                                {row.month.slice(0, 7)}
                                            </TableCell>
                                            <TableCell className="text-xs">
                                                {formatEnergy(row.kwh)}
                                            </TableCell>
                                            <TableCell className="text-xs">
                                                {formatEnergy(row.momPercent)}
                                            </TableCell>
                                            <TableCell className="text-xs">
                                                {formatEnergy(row.yoyPercent)}
                                            </TableCell>
                                            {data?.canViewCost && (
                                                <TableCell className="text-xs">
                                                    {formatEnergy(
                                                        row.costPhp,
                                                        2,
                                                    )}
                                                </TableCell>
                                            )}
                                            <TableCell className="text-xs">
                                                {formatEnergy(row.kwhPerM2)}
                                            </TableCell>
                                            <TableCell className="text-xs">
                                                {formatEnergy(
                                                    row.loadFactor,
                                                    6,
                                                )}
                                            </TableCell>
                                            <TableCell className="text-xs">
                                                {row.weatherComplete
                                                    ? 'Complete'
                                                    : 'Missing values'}
                                            </TableCell>
                                        </TableRow>
                                    ))
                                )}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
                <EnergyNotice title="Verified actuals and predicted history are different">
                    This view shows verified actuals. Historical backtests
                    belong to model evaluation and must remain distinct from the
                    single next-month operational forecast. Missing costs, floor
                    areas, weather and comparable periods remain unavailable
                    rather than zero.
                </EnergyNotice>
                <EnergyFooter />
            </div>
        </>
    );
}
