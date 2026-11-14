import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { toast } from 'sonner';
import PreprocessingController from '@/actions/App/Http/Controllers/PreprocessingController';
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
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import type { PreprocessingData } from '@/types';

export default function Preprocessing() {
    const data = usePage().props as unknown as PreprocessingData;
    const selected = data.buildings.find(
        (building) => building.code === data.selectedBuildingCode,
    );
    const [demo, setDemo] = useState(false);
    const [weatherOpen, setWeatherOpen] = useState(false);
    const build = useForm({ building_id: selected?.id ?? '' });
    const weather = useForm({
        start: data.lastCompletedMonth,
        end: data.lastCompletedMonth,
    });
    return (
        <>
            <Head title="Preprocessing" />
            <div className="flex flex-1 flex-col gap-6 px-7 pt-6 pb-10">
                <EnergyHeader
                    title="Preprocessing"
                    description="Review sorting, numeric checks, period continuity and climate feature readiness."
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
                            <Button
                                variant="outline"
                                onClick={() => setWeatherOpen(true)}
                            >
                                Sync NASA POWER
                            </Button>
                            <Button
                                disabled={
                                    !selected ||
                                    data.metrics.verified === 0 ||
                                    build.processing
                                }
                                onClick={() =>
                                    build.post(
                                        PreprocessingController.build.url(),
                                        {
                                            preserveScroll: true,
                                            onSuccess: () =>
                                                toast.success(
                                                    'Dataset inputs processed.',
                                                ),
                                        },
                                    )
                                }
                            >
                                {build.processing
                                    ? 'Processing…'
                                    : 'Process verified data'}
                            </Button>
                        </>
                    }
                />
                <EnergyFilters
                    buildings={data.buildings}
                    selected={data.selectedBuildingCode}
                    latest={data.latestVerifiedPeriod}
                    onChange={(building) =>
                        router.get(PreprocessingController.index.url(), {
                            building,
                        })
                    }
                />
                {demo && (
                    <SyntheticDemoPreview onReset={() => setDemo(false)} />
                )}
                {data.operationResult && (
                    <p
                        role="status"
                        className="rounded-lg bg-secondary p-4 text-sm"
                    >
                        {data.operationResult.message}
                    </p>
                )}
                {Object.entries(build.errors).map(([key, message]) => (
                    <InputError key={key} role="alert" message={message} />
                ))}
                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <EnergyMetric
                        title="Uploaded periods"
                        value={data.metrics.uploaded}
                    />
                    <EnergyMetric
                        title="Verified periods"
                        value={data.metrics.verified}
                        caption="All registered meters required"
                    />
                    <EnergyMetric title="Data gaps" value={data.metrics.gaps} />
                    <EnergyMetric
                        title="Climate source"
                        value="NASA POWER"
                        caption="Daily RE/LST → complete calendar months"
                    />
                </div>
                <div className="grid gap-4 lg:grid-cols-[minmax(0,1.84fr)_minmax(0,1fr)]">
                    <Card className="min-w-0 rounded-[13px] shadow-none">
                        <CardHeader>
                            <CardTitle className="text-[17px]">
                                Monthly preprocessing
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        {[
                                            'Step',
                                            'How the system handles it',
                                            'Status',
                                        ].map((title) => (
                                            <TableHead
                                                key={title}
                                                className="bg-secondary text-[11px] font-semibold text-primary"
                                            >
                                                {title}
                                            </TableHead>
                                        ))}
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {[
                                        [
                                            'Validate CSV schema',
                                            'Required fields, numeric limits and units at intake',
                                            'Available',
                                        ],
                                        [
                                            'Sort and deduplicate',
                                            'Unique meter/month; exact calendar order',
                                            'Available',
                                        ],
                                        [
                                            'Feature engineering',
                                            'Past-only Lag 1 / Lag 2 / prior trend / seasonality',
                                            data.currentDataset
                                                ? 'Processed'
                                                : 'Defined',
                                        ],
                                        [
                                            'Weather joining',
                                            'Raw values, units, missing-day flags and source snapshots',
                                            data.currentDataset
                                                ? `${data.currentDataset.weather_complete_count}/${data.currentDataset.rows_count} complete`
                                                : 'Awaiting dataset',
                                        ],
                                    ].map(([step, description, status]) => (
                                        <TableRow key={step}>
                                            <TableCell className="text-xs">
                                                {step}
                                            </TableCell>
                                            <TableCell className="text-xs whitespace-normal">
                                                {description}
                                            </TableCell>
                                            <TableCell>
                                                <Badge className="bg-[#e9f6f1] text-[10px] text-[#237f6a]">
                                                    {status}
                                                </Badge>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>
                    <Card className="rounded-[13px] shadow-none">
                        <CardHeader>
                            <CardTitle className="text-[17px]">
                                Data-quality guidance
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <ul className="space-y-5 text-[12.5px]">
                                {[
                                    'No future months in predictor lags',
                                    'Never silently impute bills or weather',
                                    'Keep outliers when verified bills confirm them',
                                    'Require all registered meters; no invented operating windows',
                                    'Do not sum non-coincident meter peaks',
                                ].map((text) => (
                                    <li key={text} className="flex gap-2.5">
                                        <span className="mt-1.5 size-2 shrink-0 rounded-full bg-[#2e61a1]" />
                                        {text}
                                    </li>
                                ))}
                            </ul>
                        </CardContent>
                    </Card>
                </div>
                <Card className="min-w-0 rounded-[13px] shadow-none">
                    <CardHeader>
                        <CardTitle className="text-[17px]">
                            Missing-month review
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead className="bg-secondary text-xs text-primary">
                                        Period
                                    </TableHead>
                                    <TableHead className="bg-secondary text-xs text-primary">
                                        Issue
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {data.issues.length === 0 ? (
                                    <TableRow>
                                        <TableCell
                                            colSpan={2}
                                            className="py-5 text-xs text-muted-foreground"
                                        >
                                            {data.metrics.uploaded === 0
                                                ? 'No records available. Upload and verify data to populate this view.'
                                                : 'No current billing or weather gaps detected.'}
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    data.issues.map((issue, index) => (
                                        <TableRow
                                            key={`${issue.month}-${index}`}
                                        >
                                            <TableCell className="text-xs">
                                                {issue.month.slice(0, 7)}
                                            </TableCell>
                                            <TableCell className="text-xs whitespace-normal">
                                                {issue.message}
                                            </TableCell>
                                        </TableRow>
                                    ))
                                )}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
                <EnergyNotice title="Data lineage">
                    Only complete verified building months enter a dataset.
                    Every version keeps source record IDs/versions, observed and
                    lagged NASA weather snapshots, units and hashes. A
                    correction or source change invalidates current analytics
                    until reprocessed. Source LST days are not relabeled as
                    civil time.
                </EnergyNotice>
                <Card className="min-w-0 rounded-[13px] shadow-none">
                    <CardHeader>
                        <CardTitle className="text-[17px]">
                            Dataset versions
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        {data.versions.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                No dataset versions yet.
                            </p>
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        {[
                                            'Version',
                                            'Cutoff',
                                            'Periods',
                                            'Lineage',
                                        ].map((title) => (
                                            <TableHead key={title}>
                                                {title}
                                            </TableHead>
                                        ))}
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {data.versions.map((version) => (
                                        <TableRow key={version.id}>
                                            <TableCell>
                                                v{version.version}{' '}
                                                {version.current && (
                                                    <Badge variant="secondary">
                                                        Current
                                                    </Badge>
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                {version.cutoff.slice(0, 7)}
                                            </TableCell>
                                            <TableCell>
                                                {version.rows}
                                            </TableCell>
                                            <TableCell>
                                                <a
                                                    className="text-primary underline"
                                                    href={PreprocessingController.lineage.url(
                                                        version.id,
                                                    )}
                                                    target="_blank"
                                                    rel="noreferrer"
                                                >
                                                    View lineage JSON
                                                </a>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                    </CardContent>
                </Card>
                <Card className="min-w-0 rounded-[13px] shadow-none">
                    <CardHeader>
                        <CardTitle className="text-[17px]">
                            NASA POWER monthly weather
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <p className="mb-3 text-xs text-muted-foreground">
                            Means use every daily value; monthly totals are
                            separately named. Missing parameters stay null.
                            Shared coarse-grid City Hall reference, not on-site
                            measurements.
                        </p>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    {[
                                        'Month',
                                        'T2M (°C)',
                                        'RH2M (%)',
                                        'Rain mean (mm/day)',
                                        'Rain total (mm)',
                                        'Solar mean (kWh/m²/day)',
                                        'Coverage / provenance',
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
                                {data.weather.data.length === 0 ? (
                                    <TableRow>
                                        <TableCell
                                            colSpan={7}
                                            className="py-4 text-sm text-muted-foreground"
                                        >
                                            No NASA POWER weather synced.
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    data.weather.data.map((row) => (
                                        <TableRow key={row.id}>
                                            <TableCell className="text-xs">
                                                {row.month.slice(0, 7)}
                                            </TableCell>
                                            {[
                                                'T2M',
                                                'RH2M',
                                                'PRECTOTCORR',
                                                'rainfall_total_mm',
                                                'ALLSKY_SFC_SW_DWN',
                                            ].map((parameter) => (
                                                <TableCell
                                                    key={parameter}
                                                    className="text-xs"
                                                >
                                                    {formatEnergy(
                                                        row.values[parameter],
                                                        4,
                                                    )}
                                                </TableCell>
                                            ))}
                                            <TableCell className="text-xs">
                                                <Badge variant="secondary">
                                                    {row.complete
                                                        ? 'Complete'
                                                        : 'Missing days'}
                                                </Badge>
                                                <details className="mt-1">
                                                    <summary className="cursor-pointer text-primary">
                                                        Source details
                                                    </summary>
                                                    <p className="max-w-sm whitespace-normal">
                                                        {
                                                            row.source.header
                                                                ?.api?.version
                                                        }{' '}
                                                        ·{' '}
                                                        {
                                                            row.source
                                                                .time_standard
                                                        }{' '}
                                                        ·{' '}
                                                        {
                                                            row.quality
                                                                .expected_days
                                                        }{' '}
                                                        expected days. Sources:{' '}
                                                        {row.source.header?.sources?.join(
                                                            ', ',
                                                        ) ?? 'See lineage'}
                                                        . Fetched{' '}
                                                        {new Date(
                                                            row.fetchedAt,
                                                        ).toLocaleString()}
                                                        .
                                                    </p>
                                                    {Object.entries(
                                                        row.quality
                                                            .missing_days ?? {},
                                                    )
                                                        .filter(
                                                            ([, days]) =>
                                                                days.length > 0,
                                                        )
                                                        .map(
                                                            ([
                                                                parameter,
                                                                days,
                                                            ]) => (
                                                                <p
                                                                    key={
                                                                        parameter
                                                                    }
                                                                >
                                                                    {parameter}:{' '}
                                                                    {
                                                                        days.length
                                                                    }{' '}
                                                                    missing/invalid
                                                                    days
                                                                </p>
                                                            ),
                                                        )}
                                                </details>
                                            </TableCell>
                                        </TableRow>
                                    ))
                                )}
                            </TableBody>
                        </Table>
                        <nav
                            className="mt-3 flex justify-end gap-2"
                            aria-label="Weather pagination"
                        >
                            {data.weather.prev_page_url && (
                                <Button asChild variant="outline">
                                    <Link href={data.weather.prev_page_url}>
                                        Previous
                                    </Link>
                                </Button>
                            )}
                            {data.weather.next_page_url && (
                                <Button asChild variant="outline">
                                    <Link href={data.weather.next_page_url}>
                                        Next
                                    </Link>
                                </Button>
                            )}
                        </nav>
                    </CardContent>
                </Card>
                <EnergyFooter />
            </div>
            <Dialog open={weatherOpen} onOpenChange={setWeatherOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Sync NASA POWER</DialogTitle>
                        <DialogDescription>
                            Fetch the four approved parameters for completed
                            months. Raw daily values and missing-day flags are
                            retained; existing location/month rows are updated
                            without duplicates.
                        </DialogDescription>
                    </DialogHeader>
                    <form
                        id="weather-sync"
                        className="space-y-4"
                        onSubmit={(event) => {
                            event.preventDefault();
                            weather.post(
                                PreprocessingController.syncWeather.url(),
                                {
                                    preserveScroll: true,
                                    onSuccess: () => {
                                        setWeatherOpen(false);
                                        toast.success(
                                            'NASA POWER weather synchronized.',
                                        );
                                    },
                                },
                            );
                        }}
                    >
                        <Label htmlFor="weather-start">First month</Label>
                        <Input
                            id="weather-start"
                            type="month"
                            min="1984-01"
                            max={data.lastCompletedMonth}
                            required
                            value={weather.data.start}
                            onChange={(event) =>
                                weather.setData('start', event.target.value)
                            }
                        />
                        <Label htmlFor="weather-end">Last month</Label>
                        <Input
                            id="weather-end"
                            type="month"
                            min="1984-01"
                            max={data.lastCompletedMonth}
                            required
                            value={weather.data.end}
                            onChange={(event) =>
                                weather.setData('end', event.target.value)
                            }
                        />
                        {Object.entries(weather.errors).map(
                            ([key, message]) => (
                                <InputError
                                    key={key}
                                    role="alert"
                                    message={message}
                                />
                            ),
                        )}
                    </form>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            disabled={weather.processing}
                            onClick={() => setWeatherOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            form="weather-sync"
                            disabled={weather.processing}
                        >
                            {weather.processing
                                ? 'Fetching source data…'
                                : 'Sync weather'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
