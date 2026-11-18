import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import SolarScenarioController from '@/actions/App/Http/Controllers/SolarScenarioController';
import ForecastController from '@/actions/App/Http/Controllers/ForecastController';
import {
    EnergyFilters,
    EnergyFooter,
    EnergyHeader,
    EnergyMetric,
    EnergyNotice,
    formatEnergy,
} from '@/components/energy-workspace';
import InputError from '@/components/input-error';
import { SyntheticDemoPreview } from '@/components/synthetic-demo-preview';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { EnergyBuilding, ForecastRun } from '@/types';

type ScenarioResult = {
    forecastId: number;
    targetMonth: string;
    climateProxyMonth: string;
    issuedKwh: number;
    reductionPercent: number;
    scenarioKwh: number;
    assumedDifferenceKwh: number;
    costDifferencePhp: null;
    solarState: string;
    installation?: InstallationInputs & {
        capacityKwp: string;
        totalPhp: string;
    };
    solar: {
        capacityKwp: number;
        performanceRatio: number;
        historicalDailySolar: number;
        targetCalendarDays: number;
        generationKwh: number;
        offsetCeilingKwh: number;
        offsetCeilingPercent: number | null;
        gridEnergyLowerBoundKwh: number;
        formula: string;
    } | null;
};

const documentation = {
    reference: 'Source reference',
    date: 'Source date',
    inclusions: 'Included costs',
    exclusions: 'Excluded costs',
} as const;
type InstallationInputs = Record<'rate' | keyof typeof documentation, string>;

export default function SolarScenarios() {
    const {
        buildings,
        selectedBuildingCode,
        latestVerifiedPeriod,
        currentForecast,
        canViewInstallationCost,
        scenarioResult: result,
    } = usePage().props as unknown as {
        buildings: EnergyBuilding[];
        selectedBuildingCode: string | null;
        latestVerifiedPeriod: string | null;
        currentForecast: ForecastRun | null;
        canViewInstallationCost: boolean;
        scenarioResult: ScenarioResult | null;
    };
    const selected = buildings.find(
        (building) => building.code === selectedBuildingCode,
    );
    const [demo, setDemo] = useState(false);
    const form = useForm({
        building_id: selected?.id ?? '',
        expected_forecast_id: currentForecast?.id ?? '',
        reduction_percent: '',
        capacity_kwp: '',
        performance_ratio: '',
        installation: {
            rate: '',
            reference: '',
            date: '',
            inclusions: '',
            exclusions: '',
        } satisfies InstallationInputs,
    });
    return (
        <>
            <Head title="Solar & Scenarios" />
            <div className="flex flex-1 flex-col gap-6 px-7 pt-6 pb-10">
                <EnergyHeader
                    title="Solar & Scenarios"
                    description="Assumption-driven what-if arithmetic and screening-level renewable energy pre-assessment."
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
                    latest={latestVerifiedPeriod}
                    onChange={(building) =>
                        router.get(SolarScenarioController.index.url(), {
                            building,
                        })
                    }
                />
                {demo && (
                    <SyntheticDemoPreview onReset={() => setDemo(false)} />
                )}
                {!currentForecast && (
                    <EnergyNotice
                        title="Current issued forecast required"
                        warning
                    >
                        Review and issue an eligible forecast before
                        calculating. Historical or source-superseded forecasts
                        cannot be used.{' '}
                        <Link
                            className="underline"
                            href={ForecastController.index({
                                query: { building: selectedBuildingCode },
                            })}
                        >
                            Open Forecasting
                        </Link>
                    </EnergyNotice>
                )}
                <form
                    className="grid gap-4 lg:grid-cols-[minmax(0,1.84fr)_minmax(0,1fr)]"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.transform(({ installation, ...values }) => ({
                            ...values,
                            ...(canViewInstallationCost &&
                            installation.rate !== ''
                                ? { installation }
                                : {}),
                            building_id: selected?.id,
                            expected_forecast_id: currentForecast?.id,
                        }));
                        form.post(SolarScenarioController.calculate.url(), {
                            preserveScroll: true,
                        });
                    }}
                >
                    <Card className="min-w-0 rounded-[13px] shadow-none">
                        <CardHeader>
                            <CardTitle className="text-[17px]">
                                What-if conservation scenario
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-5">
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="space-y-1.5">
                                    <Label
                                        htmlFor="scenario-reduction"
                                        className="text-xs text-muted-foreground uppercase"
                                    >
                                        Assumed consumption reduction (%)
                                    </Label>
                                    <Input
                                        id="scenario-reduction"
                                        type="number"
                                        min="0"
                                        max="100"
                                        step="0.1"
                                        required
                                        value={form.data.reduction_percent}
                                        onChange={(event) =>
                                            form.setData(
                                                'reduction_percent',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </div>
                                <div className="space-y-1.5">
                                    <Label
                                        htmlFor="scenario-tariff"
                                        className="text-xs text-muted-foreground uppercase"
                                    >
                                        Expected cost / kWh
                                    </Label>
                                    <Input
                                        id="scenario-tariff"
                                        readOnly
                                        value="Tariff not approved"
                                    />
                                </div>
                            </div>
                            <div className="grid gap-3 border-t pt-5 sm:grid-cols-2 xl:grid-cols-4 [&_p]:break-words">
                                <EnergyMetric
                                    title="Issued baseline"
                                    value={
                                        currentForecast
                                            ? `${formatEnergy(currentForecast.predictionKwh)} kWh`
                                            : '—'
                                    }
                                />
                                <EnergyMetric
                                    title="Scenario result"
                                    value={
                                        result
                                            ? `${formatEnergy(result.scenarioKwh)} kWh`
                                            : '—'
                                    }
                                />
                                <EnergyMetric
                                    title="Assumed difference"
                                    value={
                                        result
                                            ? `${formatEnergy(result.assumedDifferenceKwh)} kWh`
                                            : '—'
                                    }
                                />
                                <EnergyMetric
                                    title="Cost difference"
                                    value="—"
                                    caption="Approved tariff unavailable"
                                />
                            </div>
                            {result && (
                                <p
                                    role="status"
                                    className="text-xs text-muted-foreground"
                                >
                                    Last calculation: {result.reductionPercent}%
                                    assumed reduction of source run #
                                    {result.forecastId}, target{' '}
                                    {result.targetMonth.slice(0, 7)}. This did
                                    not modify the issued forecast.
                                </p>
                            )}
                            {Object.entries(form.errors).map(
                                ([key, message]) => (
                                    <InputError
                                        key={key}
                                        role="alert"
                                        message={message}
                                    />
                                ),
                            )}
                            <Button
                                type="submit"
                                disabled={!currentForecast || form.processing}
                            >
                                {form.processing
                                    ? 'Calculating…'
                                    : 'Recalculate'}
                            </Button>
                        </CardContent>
                    </Card>
                    <Card className="min-w-0 rounded-[13px] shadow-none">
                        <CardHeader>
                            <CardTitle className="text-[17px]">
                                Solar pre-assessment
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4 text-xs">
                            <p>
                                NASA POWER historical solar energy is a
                                coarse-grid scenario proxy, not target-month
                                weather or an on-site survey.
                            </p>
                            <div className="space-y-1.5">
                                <Label htmlFor="scenario-capacity">
                                    Assumed PV capacity (kWp, optional)
                                </Label>
                                <Input
                                    id="scenario-capacity"
                                    type="number"
                                    min="0"
                                    max="1000000"
                                    step="0.1"
                                    value={form.data.capacity_kwp}
                                    onChange={(event) =>
                                        form.setData(
                                            'capacity_kwp',
                                            event.target.value,
                                        )
                                    }
                                />
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor="scenario-ratio">
                                    Performance ratio (0–1, optional)
                                </Label>
                                <Input
                                    id="scenario-ratio"
                                    type="number"
                                    min="0"
                                    max="1"
                                    step="0.01"
                                    value={form.data.performance_ratio}
                                    onChange={(event) =>
                                        form.setData(
                                            'performance_ratio',
                                            event.target.value,
                                        )
                                    }
                                />
                            </div>
                            <p>
                                Provide both explicitly. No capacity,
                                roof/structural suitability or performance ratio
                                is inferred.
                            </p>
                            {canViewInstallationCost && (
                                <fieldset className="space-y-3 rounded-[9px] border p-3">
                                    <legend className="px-1 font-semibold">
                                        Installation estimate (optional)
                                    </legend>
                                    <Label htmlFor="installation-rate">
                                        Installed cost per kWp (PHP/kWp)
                                    </Label>
                                    <Input
                                        id="installation-rate"
                                        type="text"
                                        inputMode="decimal"
                                        maxLength={21}
                                        value={form.data.installation.rate}
                                        onChange={(event) =>
                                            form.setData('installation', {
                                                ...form.data.installation,
                                                rate: event.target.value,
                                            })
                                        }
                                    />
                                    <p className="text-muted-foreground">
                                        Leave blank for energy screening only.
                                    </p>
                                    {form.data.installation.rate !== '' &&
                                        (
                                            Object.keys(
                                                documentation,
                                            ) as (keyof typeof documentation)[]
                                        ).map((key) => (
                                            <div
                                                className="space-y-1.5"
                                                key={key}
                                            >
                                                <Label
                                                    htmlFor={`installation-${key}`}
                                                >
                                                    {documentation[key]}
                                                </Label>
                                                <Input
                                                    id={`installation-${key}`}
                                                    type={
                                                        key === 'date'
                                                            ? 'date'
                                                            : 'text'
                                                    }
                                                    maxLength={2000}
                                                    required
                                                    value={
                                                        form.data.installation[
                                                            key
                                                        ]
                                                    }
                                                    onChange={(event) =>
                                                        form.setData(
                                                            'installation',
                                                            {
                                                                ...form.data
                                                                    .installation,
                                                                [key]: event
                                                                    .target
                                                                    .value,
                                                            },
                                                        )
                                                    }
                                                />
                                            </div>
                                        ))}
                                </fieldset>
                            )}
                            {canViewInstallationCost &&
                                result?.installation && (
                                    <section
                                        aria-labelledby="installation-result"
                                        className="space-y-2 rounded-[9px] bg-secondary p-3 [overflow-wrap:anywhere]"
                                    >
                                        <h3
                                            id="installation-result"
                                            className="font-semibold"
                                        >
                                            Last installation estimate
                                        </h3>
                                        <p className="text-lg font-semibold">
                                            ₱{result.installation.totalPhp}
                                        </p>
                                        <p>
                                            {result.installation.capacityKwp}{' '}
                                            kWp × ₱{result.installation.rate}
                                            /kWp
                                        </p>
                                        <p>
                                            Source:{' '}
                                            {result.installation.reference} ·{' '}
                                            {result.installation.date}
                                        </p>
                                        <p>
                                            Includes:{' '}
                                            {result.installation.inclusions}
                                        </p>
                                        <p>
                                            Excludes:{' '}
                                            {result.installation.exclusions}
                                        </p>
                                    </section>
                                )}
                            {result?.solar ? (
                                <div className="space-y-2 rounded-lg border p-3">
                                    <p>
                                        Historical proxy:{' '}
                                        {result.climateProxyMonth.slice(0, 7)},{' '}
                                        {formatEnergy(
                                            result.solar.historicalDailySolar,
                                        )}{' '}
                                        kWh/m²/day. Target days:{' '}
                                        {result.solar.targetCalendarDays}.
                                    </p>
                                    <p>
                                        Assumptions: {result.solar.capacityKwp}{' '}
                                        kWp × {result.solar.performanceRatio}{' '}
                                        performance ratio.
                                    </p>
                                    <p className="font-semibold">
                                        Scenario generation:{' '}
                                        {formatEnergy(
                                            result.solar.generationKwh,
                                        )}{' '}
                                        kWh.
                                    </p>
                                    <p>
                                        Energy-equivalent offset ceiling:{' '}
                                        {formatEnergy(
                                            result.solar.offsetCeilingKwh,
                                        )}{' '}
                                        kWh (
                                        {result.solar.offsetCeilingPercent ===
                                        null
                                            ? 'percentage undefined at zero load'
                                            : `${formatEnergy(result.solar.offsetCeilingPercent)}%`}
                                        ).
                                    </p>
                                    <p>
                                        Equivalent grid-energy lower bound:{' '}
                                        {formatEnergy(
                                            result.solar
                                                .gridEnergyLowerBoundKwh,
                                        )}{' '}
                                        kWh.
                                    </p>
                                    <p className="break-words text-muted-foreground">
                                        {result.solar.formula}
                                    </p>
                                </div>
                            ) : (
                                <p className="text-muted-foreground">
                                    {result
                                        ? result.solarState.replaceAll('_', ' ')
                                        : 'Solar scenario unavailable until explicit inputs and a current source forecast are supplied.'}
                                </p>
                            )}
                            <EnergyNotice
                                title="Not a full engineering study"
                                warning
                            >
                                Arithmetic screening is not a feasibility, ROI,
                                procurement or guaranteed self-consumption
                                outcome. No export/revenue assumption is made.
                            </EnergyNotice>
                        </CardContent>
                    </Card>
                </form>
                <EnergyNotice title="No ML scenario claims">
                    The percentage adjustment is arithmetic on an issued
                    forecast, not a prediction of an energy-saving intervention.
                    Solar uses an explicitly stated historical weather proxy.
                    The two scenarios independently reference the unchanged
                    issued baseline; their reductions are not combined. No
                    monetary saving is computed without an approved tariff.
                </EnergyNotice>
                <EnergyFooter />
            </div>
        </>
    );
}
