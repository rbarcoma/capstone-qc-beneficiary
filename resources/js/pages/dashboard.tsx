import { Head, router, usePage } from '@inertiajs/react';
import {
    Card,
    CardContent,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { dashboard } from '@/routes';
import { cn } from '@/lib/utils';
import type { DashboardData } from '@/types';

const routineSteps = [
    'Facilities receives the Meralco bill',
    'Monthly record is uploaded and validated',
    'Energy Manager verifies against the bill',
    'Model forecasts next eligible month',
    'Manager reviews DSS advice and Budget Officer exports',
];

export default function Dashboard() {
    const page = usePage();
    const data = page.props as unknown as DashboardData;
    const {
        buildings,
        selectedBuildingCode,
        latestVerifiedPeriod,
        kpis,
        readiness,
        trend,
    } = data;

    const pilotCaption =
        buildings.length > 0
            ? buildings.map((building) => building.name).join(' and ')
            : 'No pilot buildings registered yet';
    const forecastValue = kpis.nextForecast ?? 'Not issued';
    const routineHighlight = kpis.verifiedMonths === 0 ? 0 : null;

    const selectBuilding = (code: string) => {
        router.get(dashboard(), { building: code }, { preserveState: true });
    };

    return (
        <>
            <Head title="Dashboard" />
            <div className="flex flex-1 flex-col gap-6 px-7 pt-6 pb-10">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p className="text-[11px] font-bold tracking-[0.06em] text-[#2e61a1] uppercase">
                            QC Energy Intelligence • Workspace
                        </p>
                        <h1 className="mt-1.5 text-[29px] leading-9 font-semibold text-[#1c2b40]">
                            Dashboard
                        </h1>
                        <p className="mt-2 text-[14px] text-muted-foreground">
                            A practical view of building consumption, upload
                            readiness, forecasts and decisions.
                        </p>
                    </div>
                    <div className="flex items-center gap-2.5">
                        <Button
                            className="h-10 rounded-[9px] bg-primary px-4 text-[12px] font-semibold"
                            disabled
                            title="Uploads arrive with the Data Management module"
                        >
                            Upload monthly bill
                        </Button>
                        <Button
                            variant="outline"
                            className="h-10 rounded-[9px] border-border bg-card px-4 text-[12px] font-semibold text-primary"
                            disabled
                            title="Demo data loading arrives with the Data Management module"
                        >
                            Load synthetic demo
                        </Button>
                    </div>
                </div>

                <div className="flex flex-wrap items-end gap-4">
                    <div className="flex flex-col gap-1.5">
                        <span className="text-[10px] font-bold tracking-[0.08em] text-muted-foreground uppercase">
                            Building
                        </span>
                        <Select
                            value={selectedBuildingCode ?? undefined}
                            onValueChange={selectBuilding}
                            disabled={buildings.length === 0}
                        >
                            <SelectTrigger className="h-10 w-[185px] rounded-[9px] border-input bg-card text-[13px] font-bold text-[#1c2b40]">
                                <SelectValue placeholder="No buildings registered" />
                            </SelectTrigger>
                            <SelectContent>
                                {buildings.map((building) => (
                                    <SelectItem
                                        key={building.code}
                                        value={building.code}
                                    >
                                        {building.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                    <div className="flex flex-col gap-1.5">
                        <span className="text-[10px] font-bold tracking-[0.08em] text-muted-foreground uppercase">
                            Latest verified period
                        </span>
                        <Input
                            readOnly
                            value={latestVerifiedPeriod ?? 'No verified record'}
                            className="h-10 w-[241px] rounded-[9px] border-input bg-card text-[13px] font-bold text-[#1c2b40]"
                        />
                    </div>
                </div>

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <MetricCard
                        title="Pilot buildings"
                        value={String(kpis.pilotBuildings)}
                        caption={pilotCaption}
                    />
                    <MetricCard
                        title="Verified months"
                        value={String(kpis.verifiedMonths)}
                        caption="Selected building"
                    />
                    <MetricCard
                        title="Next forecast"
                        value={forecastValue}
                        caption="Requires verified history"
                    />
                    <MetricCard
                        title="Pending verification"
                        value={String(kpis.pendingVerification)}
                        caption="Draft billing records"
                    />
                </div>

                <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                    <Card className="rounded-[13px] border-border bg-card lg:col-span-2">
                        <CardHeader className="gap-1 pb-2">
                            <CardTitle className="text-[17px] font-semibold text-[#1c2b40]">
                                Monthly electricity trend
                            </CardTitle>
                            <p className="text-[12px] text-muted-foreground">
                                Verified monthly readings only
                            </p>
                        </CardHeader>
                        <CardContent>
                            {trend.length === 0 ? (
                                <div className="flex h-[103px] items-center justify-center rounded-[15px] border border-border bg-card">
                                    <p className="text-[14px] text-[#1c2b40]">
                                        No verified values to chart
                                    </p>
                                </div>
                            ) : (
                                <div className="flex h-[160px] items-end gap-2">
                                    {trend.map((point) => (
                                        <div
                                            key={point.month}
                                            className="flex flex-1 flex-col items-center gap-1"
                                        >
                                            <div
                                                className="w-full max-w-10 rounded-t-[4px] bg-primary"
                                                style={{
                                                    height: `${Math.max(4, (point.kwh / Math.max(...trend.map((p) => p.kwh))) * 140)}px`,
                                                }}
                                            />
                                            <span className="text-[10px] text-muted-foreground">
                                                {point.month}
                                            </span>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    <Card className="rounded-[13px] border-border bg-card">
                        <CardHeader className="pb-2">
                            <CardTitle className="text-[17px] font-semibold text-[#1c2b40]">
                                Monthly operating routine
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-6">
                            {routineSteps.map((step, index) => (
                                <div
                                    key={step}
                                    className="flex items-start gap-2.5"
                                >
                                    <span
                                        className={cn(
                                            'mt-[5px] size-2 shrink-0 rounded-full',
                                            routineHighlight === index
                                                ? 'bg-[#d9a633]'
                                                : 'bg-[#2e61a1]',
                                        )}
                                    />
                                    <p className="text-[12.5px] leading-4.5 text-[#1c2b40]">
                                        {index + 1}. {step}
                                    </p>
                                </div>
                            ))}
                        </CardContent>
                    </Card>
                </div>

                <Card className="rounded-[13px] border-border bg-card">
                    <CardHeader className="pb-3">
                        <CardTitle className="text-[17px] font-semibold text-[#1c2b40]">
                            Pilot-building readiness
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        {readiness.length === 0 ? (
                            <div className="flex h-[92px] items-center justify-center rounded-[9px] border border-border bg-card text-[14px] text-[#1c2b40]">
                                No pilot buildings registered yet
                            </div>
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow className="border-0 hover:bg-transparent">
                                        <TableHead className="h-10 rounded-l-[6px] bg-[#eff4fb] px-3 text-[11px] font-semibold text-primary">
                                            Building
                                        </TableHead>
                                        <TableHead className="h-10 bg-[#eff4fb] px-3 text-[11px] font-semibold text-primary">
                                            Verified coverage
                                        </TableHead>
                                        <TableHead className="h-10 bg-[#eff4fb] px-3 text-[11px] font-semibold text-primary">
                                            Latest month
                                        </TableHead>
                                        <TableHead className="h-10 rounded-r-[6px] bg-[#eff4fb] px-3 text-[11px] font-semibold text-primary">
                                            Forecast
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {readiness.map((row) => (
                                        <TableRow
                                            key={row.building}
                                            className="border-0 hover:bg-transparent"
                                        >
                                            <TableCell className="h-12 px-3 text-[12px] font-semibold text-[#1c2b40]">
                                                {row.building}
                                            </TableCell>
                                            <TableCell className="h-12 px-3 text-[12px] text-[#1c2b40]">
                                                {row.verifiedCoverage} months
                                            </TableCell>
                                            <TableCell className="h-12 px-3 text-[12px] text-[#1c2b40]">
                                                {row.latestMonth ?? '—'}
                                            </TableCell>
                                            <TableCell className="h-12 px-3">
                                                <Badge
                                                    className={cn(
                                                        'h-6 rounded-full px-2.5 text-[10px] font-bold tracking-[0.04em] uppercase',
                                                        row.forecastReady
                                                            ? 'bg-[#eff4fb] text-[#173a7a]'
                                                            : 'bg-[#fff5e3] text-[#8a5c0d]',
                                                    )}
                                                >
                                                    {row.forecastReady
                                                        ? 'Ready'
                                                        : 'Insufficient'}
                                                </Badge>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                    </CardContent>
                </Card>

                <div className="rounded-[9px] bg-[#eff4fb] px-6 py-4">
                    <p className="text-[14px] font-semibold text-primary">
                        Data integrity notice
                    </p>
                    <p className="mt-1 text-[12px] leading-4.5 text-[#52647e]">
                        Official analytics use verified monthly billing records
                        only. Synthetic demo values, when loaded, are clearly
                        flagged and excluded from official analytics and
                        forecasts. Do not upload confidential Meralco account
                        details beyond the roles that require them.
                    </p>
                </div>

                <footer className="border-t border-border pt-4 pb-2">
                    <p className="text-[11px] leading-4 text-muted-foreground">
                        QC Energy Intelligence • Quezon City Hall Energy
                        Analytics, Forecasting, and Decision Support System.
                        Reference: technical proposal dated October 25, 2026.
                    </p>
                </footer>
            </div>
        </>
    );
}

function MetricCard({
    title,
    value,
    caption,
}: {
    title: string;
    value: string;
    caption: string;
}) {
    return (
        <Card className="rounded-[12px] border-border bg-card shadow-none">
            <CardContent className="flex flex-col gap-2 px-5 py-4">
                <span className="text-[12px] font-semibold text-muted-foreground">
                    {title}
                </span>
                <span className="text-[23px] leading-8 font-bold text-[#1c2b40]">
                    {value}
                </span>
                <span className="text-[11px] text-muted-foreground">
                    {caption}
                </span>
            </CardContent>
        </Card>
    );
}

Dashboard.layout = {
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: dashboard(),
        },
    ],
};
