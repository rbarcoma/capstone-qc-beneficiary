import { Head, Link, router, usePage } from '@inertiajs/react';
import SystemSettingsController from '@/actions/App/Http/Controllers/SystemSettingsController';
import SolarScenarioController from '@/actions/App/Http/Controllers/SolarScenarioController';
import {
    EnergyFilters,
    EnergyFooter,
    EnergyHeader,
    EnergyNotice,
} from '@/components/energy-workspace';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit as editProfile } from '@/routes/profile';
import type { EnergyBuilding } from '@/types';

type Configuration = {
    tariffPhpPerKwh: number | null;
    monthlyTargetKwh: number | null;
    changeReviewPercent: number | null;
    pvCapacityKwp: number | null;
    pvPerformanceRatio: number | null;
    ruleGenerationEnabled: boolean;
    policyState: string;
    minimumObservations: number;
    forecastHorizon: string;
    primaryModel: string;
    baseline: string;
    weatherSource: string;
    weatherParameters: string[];
    pipelineVersion: string;
};

export default function SystemSettings() {
    const {
        buildings,
        selectedBuilding,
        latestVerifiedPeriod,
        configuration: config,
    } = usePage().props as unknown as {
        buildings: EnergyBuilding[];
        selectedBuilding: string | null;
        latestVerifiedPeriod: string | null;
        configuration: Configuration;
    };
    const fields = [
        {
            id: 'tariff',
            label: 'Approved tariff (PHP / kWh)',
            value: config.tariffPhpPerKwh,
            description:
                'Budget Office source, demand-charge treatment and effective cycle required.',
        },
        {
            id: 'target',
            label: 'Monthly target (kWh)',
            value: config.monthlyTargetKwh,
            description:
                'Target ownership, baseline and effective-period contract required.',
        },
        {
            id: 'threshold',
            label: 'Change review threshold (%)',
            value: config.changeReviewPercent,
            description:
                'C5 rule mapping, valid trigger units and priority definitions required.',
        },
        {
            id: 'pv-capacity',
            label: 'PV capacity assumption (kWp)',
            value: config.pvCapacityKwp,
            description:
                'No saved building default. Supply an explicit screening assumption per calculation.',
        },
        {
            id: 'pv-ratio',
            label: 'PV performance ratio (0–1)',
            value: config.pvPerformanceRatio,
            description:
                'No saved building default. Requires an explicit paired scenario input.',
        },
    ];
    return (
        <>
            <Head title="System Settings" />
            <div className="flex flex-1 flex-col gap-6 px-7 pt-6 pb-10">
                <EnergyHeader
                    title="System Settings"
                    description="Read-only planning-parameter availability and implemented technical configuration."
                    actions={
                        <Button variant="outline" asChild>
                            <Link href={editProfile()}>Account settings</Link>
                        </Button>
                    }
                />
                <EnergyFilters
                    buildings={buildings}
                    selected={selectedBuilding}
                    latest={latestVerifiedPeriod}
                    onChange={(building) =>
                        router.get(
                            SystemSettingsController.index.url(),
                            { building },
                            { preserveScroll: true },
                        )
                    }
                />
                <div className="grid min-w-0 gap-4 xl:grid-cols-[minmax(0,1.84fr)_minmax(0,1fr)]">
                    <Card className="min-w-0 rounded-[13px] shadow-none">
                        <CardHeader>
                            <CardTitle className="text-[17px]">
                                Planning and decision parameters
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-5">
                            {!selectedBuilding && (
                                <p className="text-xs text-muted-foreground">
                                    No registered building is available.
                                </p>
                            )}
                            <div className="grid gap-5 sm:grid-cols-2">
                                {fields.map((field) => (
                                    <div
                                        key={field.id}
                                        className="min-w-0 space-y-2"
                                    >
                                        <Label
                                            htmlFor={`system-${field.id}`}
                                            className="text-[11px] font-bold text-muted-foreground uppercase"
                                        >
                                            {field.label}
                                        </Label>
                                        <Input
                                            id={`system-${field.id}`}
                                            readOnly
                                            aria-describedby={`system-${field.id}-description`}
                                            value={
                                                field.value ?? 'Not configured'
                                            }
                                            className="h-10 rounded-[9px] bg-card text-[13px] font-bold"
                                        />
                                        <p
                                            id={`system-${field.id}-description`}
                                            className="text-xs leading-5 text-muted-foreground"
                                        >
                                            {field.description}
                                        </p>
                                    </div>
                                ))}
                            </div>
                            <p className="text-xs text-muted-foreground">
                                This view cannot save or activate settings. The
                                prototype threshold is not an approved default.
                                Policy inputs require a reviewed
                                version/effective-date contract before editing
                                can be enabled.
                            </p>
                        </CardContent>
                    </Card>
                    <Card className="min-w-0 rounded-[13px] shadow-none">
                        <CardHeader>
                            <CardTitle className="text-[17px]">
                                Governance and release controls
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-5 text-xs leading-5">
                            <ul className="list-disc space-y-4 pl-4">
                                <li>
                                    Energy Manager reviews thresholds and target
                                    ownership.
                                </li>
                                <li>
                                    Budget Office confirms tariff sources and
                                    budget cycle.
                                </li>
                                <li>
                                    Document effective dates, evidence and
                                    versions.
                                </li>
                                <li>ICT approves hosting and security.</li>
                                <li>DPO reviews data privacy controls.</li>
                            </ul>
                            <EnergyNotice title="Recommendation policy">
                                {config.policyState}. Approving a version here
                                does not activate it for evaluation.
                            </EnergyNotice>
                            <Button variant="outline" asChild>
                                <Link
                                    href={SolarScenarioController.index({
                                        query: { building: selectedBuilding },
                                    })}
                                >
                                    Open explicit scenarios
                                </Link>
                            </Button>
                        </CardContent>
                    </Card>
                </div>
                <Card className="min-w-0 rounded-[13px] shadow-none">
                    <CardHeader>
                        <CardTitle className="text-[17px]">
                            Implemented technical configuration
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <dl className="grid gap-4 text-xs sm:grid-cols-2">
                            {[
                                ['Forecast horizon', config.forecastHorizon],
                                ['Primary model', config.primaryModel],
                                ['Comparison baseline', config.baseline],
                                [
                                    'Minimum source observations',
                                    `${config.minimumObservations} contiguous complete months — technical guard, not approved confidence policy`,
                                ],
                                [
                                    'Weather source',
                                    `${config.weatherSource}: ${config.weatherParameters.join(', ')}`,
                                ],
                                ['Dataset pipeline', config.pipelineVersion],
                            ].map(([label, value]) => (
                                <div
                                    key={label}
                                    className="min-w-0 rounded-[9px] border p-4"
                                >
                                    <dt className="font-semibold">{label}</dt>
                                    <dd className="mt-2 break-words text-muted-foreground">
                                        {value}
                                    </dd>
                                </div>
                            ))}
                        </dl>
                    </CardContent>
                </Card>
                <EnergyNotice
                    title="Solar screening is not solar feasibility"
                    warning
                >
                    Even with explicit PV inputs, a roof assessment and
                    engineering study are still required before real-world
                    adoption. Scenario calculations do not save building
                    defaults or change issued forecasts.
                </EnergyNotice>
                <EnergyFooter />
            </div>
        </>
    );
}
