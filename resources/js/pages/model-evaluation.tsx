import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import ForecastController from '@/actions/App/Http/Controllers/ForecastController';
import {
    EnergyFilters,
    EnergyFooter,
    EnergyHeader,
    EnergyMetric,
    EnergyNotice,
    formatEnergy,
} from '@/components/energy-workspace';
import {
    BacktestEvidence,
    ForecastMetrics,
    forecastState,
} from '@/components/forecast-evidence';
import InputError from '@/components/input-error';
import { SyntheticDemoPreview } from '@/components/synthetic-demo-preview';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { ForecastPageData } from '@/types';

export default function ModelEvaluation() {
    const { buildings, selectedBuildingCode, data, operationResult } = usePage()
        .props as unknown as ForecastPageData;
    const [demo, setDemo] = useState(false);
    const reconcile = useForm({ building_id: '' });
    const selected = buildings.find(
        (building) => building.code === selectedBuildingCode,
    );
    const run = data?.evaluation;
    return (
        <>
            <Head title="Model Evaluation" />
            <div className="flex flex-1 flex-col gap-6 px-7 pt-6 pb-10">
                <EnergyHeader
                    title="Model Evaluation"
                    description="Walk-forward comparison using identical historical test windows; MAE, RMSE and R²."
                    actions={
                        <>
                            <Button asChild variant="outline">
                                <Link
                                    href={ForecastController.index({
                                        query: {
                                            building: selectedBuildingCode,
                                        },
                                    })}
                                >
                                    Forecasting
                                </Link>
                            </Button>
                            <Button
                                variant="outline"
                                onClick={() => setDemo(true)}
                                disabled={demo}
                            >
                                Load synthetic demo
                            </Button>
                            {data?.canRun && (
                                <Button
                                    variant="outline"
                                    disabled={
                                        !selected ||
                                        !data.history.length ||
                                        reconcile.processing
                                    }
                                    onClick={() => {
                                        reconcile.transform(() => ({
                                            building_id: selected?.id,
                                        }));
                                        reconcile.post(
                                            ForecastController.reconcile.url(),
                                            { preserveScroll: true },
                                        );
                                    }}
                                >
                                    Reconcile actuals
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
                        router.get(ForecastController.evaluation.url(), {
                            building,
                        })
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
                {Object.entries(reconcile.errors).map(([key, message]) => (
                    <InputError key={key} role="alert" message={message} />
                ))}
                {demo && (
                    <SyntheticDemoPreview onReset={() => setDemo(false)} />
                )}
                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <EnergyMetric
                        title="Complete verified periods"
                        value={data?.eligibility.observations ?? 0}
                        caption="Continuity/weather checked separately"
                    />
                    <EnergyMetric
                        title="Backtest training"
                        value="10+ rows"
                        caption="3 warm-up and 3 held-out months"
                    />
                    <EnergyMetric
                        title="Reference baseline"
                        value="Previous-three mean"
                    />
                    <EnergyMetric
                        title="Primary model"
                        value="Linear Regression"
                        caption="No automatic promotion"
                    />
                </div>
                {!!data?.history.length && (
                    <div className="space-y-1.5">
                        <Label
                            htmlFor="evaluation-run"
                            className="text-xs text-muted-foreground uppercase"
                        >
                            Recorded run
                        </Label>
                        <Select
                            value={run ? String(run.id) : undefined}
                            onValueChange={(id) =>
                                router.get(
                                    ForecastController.evaluation.url(),
                                    { building: selectedBuildingCode, run: id },
                                )
                            }
                        >
                            <SelectTrigger
                                id="evaluation-run"
                                className="w-full max-w-lg bg-card"
                            >
                                <SelectValue placeholder="Select a recorded run" />
                            </SelectTrigger>
                            <SelectContent>
                                {data.history.map((item) => (
                                    <SelectItem
                                        key={item.id}
                                        value={String(item.id)}
                                    >
                                        #{item.id} ·{' '}
                                        {item.targetMonth.slice(0, 7)} ·{' '}
                                        {forecastState(item.state)}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                )}
                <Card className="min-w-0 rounded-[13px] shadow-none">
                    <CardHeader>
                        <CardTitle className="text-[17px]">
                            Historical model candidates
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <ForecastMetrics run={run} />
                    </CardContent>
                </Card>
                <Card className="min-w-0 rounded-[13px] shadow-none">
                    <CardHeader>
                        <CardTitle className="text-[17px]">
                            Actual vs predicted historical evidence
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <BacktestEvidence run={run} />
                    </CardContent>
                </Card>
                <Card className="rounded-[13px] shadow-none">
                    <CardHeader>
                        <CardTitle className="text-[17px]">
                            Realized target error
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-2 text-sm">
                        {run?.actual.valid ? (
                            <>
                                <p>
                                    Target {run.targetMonth.slice(0, 7)} actual:{' '}
                                    {formatEnergy(run.actual.kwh)} kWh.
                                </p>
                                <p>
                                    Absolute error:{' '}
                                    {formatEnergy(run.actual.absoluteError)}{' '}
                                    kWh. Percentage error:{' '}
                                    {run.actual.percentageError === null
                                        ? 'Undefined for zero actual'
                                        : `${formatEnergy(run.actual.percentageError)}%`}
                                    .
                                </p>
                            </>
                        ) : (
                            <p className="text-muted-foreground">
                                {run
                                    ? run.actual.reason.replaceAll('_', ' ')
                                    : 'No recorded run/verified target actual available.'}
                                .
                            </p>
                        )}
                        <p className="text-xs text-muted-foreground">
                            These realized errors are separate from
                            training/backtest metrics. Partial, corrected or
                            source-superseded actuals are excluded until
                            reconciled/reverified; prior values remain in audit
                            history.
                        </p>
                    </CardContent>
                </Card>
                <EnergyNotice title="Model selection is not automatic" warning>
                    Linear Regression remains the primary method; the
                    three-month mean is a reference baseline. Negative R² is
                    possible and constant-target R² is undefined. No additional
                    model or calibrated confidence claim is inferred from the
                    demo.{' '}
                    {run?.sourceCurrent
                        ? run.validationLabel
                        : 'No current-source validation evidence selected.'}
                </EnergyNotice>
                <EnergyFooter />
            </div>
        </>
    );
}
