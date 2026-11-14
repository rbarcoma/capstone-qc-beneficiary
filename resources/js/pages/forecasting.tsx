import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { toast } from 'sonner';
import ForecastController from '@/actions/App/Http/Controllers/ForecastController';
import DataManagementController from '@/actions/App/Http/Controllers/DataManagementController';
import {
    EnergyFilters,
    EnergyFooter,
    EnergyHeader,
    EnergyMetric,
    EnergyNotice,
    formatEnergy,
} from '@/components/energy-workspace';
import {
    ForecastMetrics,
    forecastReason,
    forecastState,
} from '@/components/forecast-evidence';
import InputError from '@/components/input-error';
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
import type { ForecastPageData } from '@/types';

export default function Forecasting() {
    const { buildings, selectedBuildingCode, data, operationResult } = usePage()
        .props as unknown as ForecastPageData;
    const selected = buildings.find(
        (building) => building.code === selectedBuildingCode,
    );
    const run = useForm({
        building_id: selected?.id ?? '',
        expected_fingerprint: data?.eligibility.datasetFingerprint ?? '',
    });
    const current = data?.current;
    const observationBars = data?.observations.slice(-6) ?? [];
    const max = Math.max(
        1,
        current?.predictionKwh ?? 0,
        ...observationBars.map((row) => row.kwh),
    );
    const start = () => {
        run.transform(() => ({
            building_id: selected?.id,
            expected_fingerprint: data?.eligibility.datasetFingerprint,
        }));
        run.post(ForecastController.issue.url(), {
            preserveScroll: true,
            onSuccess: () => toast.success('Forecast request completed.'),
        });
    };
    return (
        <>
            <Head title="Forecasting" />
            <div className="flex flex-1 flex-col gap-6 px-7 pt-6 pb-10">
                <EnergyHeader
                    title="Forecasting"
                    description="Strict next-month energy forecast with evidence, issue status and operational safeguards."
                    actions={
                        <>
                            <Button
                                asChild
                                variant="outline"
                                className="h-10 rounded-[9px] text-[12px]"
                            >
                                <Link
                                    href={ForecastController.evaluation({
                                        query: {
                                            building: selectedBuildingCode,
                                        },
                                    })}
                                >
                                    Model evaluation
                                </Link>
                            </Button>
                            {data?.canRun && (
                                <Button
                                    className="h-10 rounded-[9px] text-[12px]"
                                    disabled={
                                        !data.eligibility.eligible ||
                                        run.processing
                                    }
                                    onClick={start}
                                >
                                    {run.processing
                                        ? 'Evaluating…'
                                        : 'Run next-month forecast'}
                                </Button>
                            )}
                        </>
                    }
                />
                <EnergyFilters
                    buildings={buildings}
                    selected={selectedBuildingCode}
                    latest={data?.latestVerifiedPeriod ?? null}
                    onChange={(building) =>
                        router.get(ForecastController.index.url(), { building })
                    }
                />
                {operationResult && (
                    <p
                        role="status"
                        className="rounded-lg bg-secondary p-4 text-sm"
                    >
                        {operationResult.message}
                    </p>
                )}
                {Object.entries(run.errors).map(([key, message]) => (
                    <InputError key={key} role="alert" message={message} />
                ))}
                {!current ? (
                    <Card className="flex min-h-[395px] items-center justify-center rounded-[15px] shadow-none">
                        <CardContent className="flex max-w-[600px] flex-col items-center gap-5 p-7 text-center">
                            <span
                                aria-hidden="true"
                                className="flex size-[72px] items-center justify-center rounded-full bg-secondary text-[34px] text-primary"
                            >
                                ◈
                            </span>
                            <h2 className="text-[24px] font-semibold">
                                {data?.eligibility.eligible
                                    ? 'Eligible forecast not issued'
                                    : 'No eligible next-month forecast'}
                            </h2>
                            <p className="text-[14px] text-muted-foreground">
                                {data?.eligibility.eligible
                                    ? `Only eligible target: ${data.eligibility.target_month?.slice(0, 7)}. An authorized manager may evaluate and issue this single month.`
                                    : forecastReason(data?.eligibility.reason)}
                            </p>
                            <p className="text-xs text-muted-foreground">
                                Technical v1 guard: at least 16 consecutive
                                complete verified observations, approved lagged
                                weather, 10 training rows and 3 held-out months.
                                Available complete periods:{' '}
                                {data?.eligibility.observations ?? 0}.
                            </p>
                            {data?.canRun && (
                                <Button asChild>
                                    <Link
                                        href={DataManagementController.index()}
                                    >
                                        Open Data Management
                                    </Link>
                                </Button>
                            )}
                        </CardContent>
                    </Card>
                ) : (
                    <>
                        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                            <EnergyMetric
                                title="Latest verified"
                                value={current.trainingEndMonth.slice(0, 7)}
                            />
                            <EnergyMetric
                                title="Only eligible target"
                                value={current.targetMonth.slice(0, 7)}
                                caption="One month later; no horizon selector"
                            />
                            <EnergyMetric
                                title="Selected method"
                                value="Linear Regression"
                                caption="Three-month mean is the reference baseline"
                            />
                            <EnergyMetric
                                title="Issued forecast"
                                value={`${formatEnergy(current.predictionKwh)} kWh`}
                                caption={`Dataset v${current.datasetVersion}`}
                            />
                        </div>
                        <div className="grid gap-4 lg:grid-cols-[minmax(0,1.84fr)_minmax(0,1fr)]">
                            <Card className="min-w-0 rounded-[13px] shadow-none">
                                <CardHeader>
                                    <CardTitle className="text-[17px]">
                                        Observed kWh and target horizon
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <p className="mb-4 text-xs text-muted-foreground">
                                        Latest six observations. Navy: verified
                                        actuals. Gold: issued prediction, not an
                                        actual observation.
                                    </p>
                                    <div
                                        className="overflow-x-auto"
                                        ref={(element) => {
                                            if (element)
                                                element.scrollLeft =
                                                    element.scrollWidth;
                                        }}
                                    >
                                        <div
                                            role="img"
                                            aria-label="Verified observations and separately marked forecast"
                                            className="flex min-w-max items-end gap-3 border-b pt-4"
                                        >
                                            {[
                                                ...observationBars.map(
                                                    (row) => ({
                                                        ...row,
                                                        forecast: false,
                                                    }),
                                                ),
                                                {
                                                    month: current.targetMonth,
                                                    kwh:
                                                        current.predictionKwh ??
                                                        0,
                                                    forecast: true,
                                                },
                                            ].map((row) => (
                                                <div
                                                    key={row.month}
                                                    className="flex w-14 shrink-0 flex-col items-center gap-2"
                                                >
                                                    <span className="text-[10px]">
                                                        {formatEnergy(row.kwh)}
                                                    </span>
                                                    <div
                                                        className={`w-9 rounded-t ${row.forecast ? 'bg-[#d9a633]' : 'bg-primary'}`}
                                                        style={{
                                                            height: `${(row.kwh / max) * 160}px`,
                                                        }}
                                                    />
                                                    <span className="pb-2 text-[10px] text-muted-foreground">
                                                        {row.month.slice(0, 7)}
                                                        {row.forecast
                                                            ? ' *'
                                                            : ''}
                                                    </span>
                                                </div>
                                            ))}
                                        </div>
                                    </div>
                                </CardContent>
                            </Card>
                            <Card className="rounded-[13px] shadow-none">
                                <CardHeader>
                                    <CardTitle className="text-[17px]">
                                        Method and controls
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-4 text-xs">
                                    <p>
                                        Repeating the same inputs reuses the
                                        recorded run; it does not advance the
                                        target.
                                    </p>
                                    <p>
                                        Issued{' '}
                                        {new Date(
                                            current.issuedAt,
                                        ).toLocaleString()}
                                        .
                                    </p>
                                    <p>
                                        Worker version:{' '}
                                        <span className="break-all">
                                            {current.modelVersion.slice(0, 16)}
                                        </span>
                                        .
                                    </p>
                                    <p>
                                        Empirical error band:{' '}
                                        {formatEnergy(current.lowerKwh)}–
                                        {formatEnergy(current.upperKwh)} kWh.
                                    </p>
                                    <p>{current.validationLabel}</p>
                                </CardContent>
                            </Card>
                        </div>
                        <Card className="min-w-0 rounded-[13px] shadow-none">
                            <CardHeader>
                                <CardTitle className="text-[17px]">
                                    Evaluation evidence
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <ForecastMetrics run={current} />
                            </CardContent>
                        </Card>
                    </>
                )}
                {!!data?.history.length && (
                    <Card className="min-w-0 rounded-[13px] shadow-none">
                        <CardHeader>
                            <CardTitle className="text-[17px]">
                                Recorded forecast runs
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="mb-3 text-xs text-muted-foreground">
                                Latest 20 runs plus any active/selected run.
                                Historical predictions are not the current
                                operational forecast; source-superseded values
                                are withheld.
                            </p>
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        {[
                                            'Target',
                                            'Dataset',
                                            'Issued kWh',
                                            'Status',
                                            'Evidence',
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
                                    {data.history.map((item) => (
                                        <TableRow key={item.id}>
                                            <TableCell className="text-xs">
                                                {item.targetMonth.slice(0, 7)}
                                            </TableCell>
                                            <TableCell className="text-xs">
                                                v{item.datasetVersion}
                                            </TableCell>
                                            <TableCell className="text-xs">
                                                {formatEnergy(
                                                    item.predictionKwh,
                                                )}
                                            </TableCell>
                                            <TableCell className="text-xs whitespace-normal">
                                                {forecastState(item.state)}
                                            </TableCell>
                                            <TableCell>
                                                <Link
                                                    className="text-xs text-primary underline"
                                                    href={ForecastController.evaluation(
                                                        {
                                                            query: {
                                                                building:
                                                                    selectedBuildingCode,
                                                                run: item.id,
                                                            },
                                                        },
                                                    )}
                                                >
                                                    View run #{item.id}
                                                </Link>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>
                )}
                <EnergyNotice title="Forecast publication controls">
                    Only current complete verified source data can be issued.
                    Inputs and runtime are rechecked under locks after
                    computation. Issuance, model version, evidence and
                    corrections are audited. The empirical error band is not a
                    calibrated confidence interval.
                </EnergyNotice>
                <EnergyFooter />
            </div>
        </>
    );
}
