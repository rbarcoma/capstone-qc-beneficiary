import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AnalyticsController from '@/actions/App/Http/Controllers/AnalyticsController';
import {
    EnergyFooter,
    EnergyHeader,
    EnergyMetric,
    EnergyNotice,
    formatEnergy,
} from '@/components/energy-workspace';
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
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';

type ComparisonRow = {
    id: number;
    name: string;
    kwh: number | null;
    costPhp: number | null;
    kwhPerM2: number | null;
    loadFactor: number | null;
    latestMonth: string | null;
    stale: boolean;
    datasetVersion: number | null;
    kwhRank: number | null;
    costPhpRank: number | null;
    kwhPerM2Rank: number | null;
};

export default function BuildingComparison() {
    const {
        rows,
        periods,
        selectedPeriod,
        portfolioKwh,
        floorAreaCount,
        canViewCost,
    } = usePage().props as unknown as {
        rows: ComparisonRow[];
        periods: string[];
        selectedPeriod: string | null;
        portfolioKwh: number | null;
        floorAreaCount: number;
        canViewCost: boolean;
    };
    const [demo, setDemo] = useState(false);
    const ranked = (value: number | null, rank: number | null) => (
        <>
            {formatEnergy(value)}
            {rank !== null && (
                <span className="block text-[10px] text-muted-foreground">
                    Rank {rank} (high → low)
                </span>
            )}
        </>
    );
    return (
        <>
            <Head title="Building Comparison" />
            <div className="flex flex-1 flex-col gap-6 px-7 pt-6 pb-10">
                <EnergyHeader
                    title="Building Comparison"
                    description="Cross-building totals, fair context, cost, floor-area intensity and load factor."
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
                {demo && (
                    <SyntheticDemoPreview onReset={() => setDemo(false)} />
                )}
                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <EnergyMetric
                        title="Compared buildings"
                        value={rows.length}
                        caption="Authorized registered buildings"
                    />
                    <EnergyMetric
                        title="Portfolio kWh"
                        value={
                            portfolioKwh === null
                                ? 'Unavailable'
                                : formatEnergy(portfolioKwh)
                        }
                        caption="Same verified period across every building"
                    />
                    <EnergyMetric
                        title="Floor-area information"
                        value={`${floorAreaCount} / ${rows.length}`}
                    />
                    <EnergyMetric
                        title="Tariff source"
                        value="Not approved"
                        caption="Actual bill costs only; no estimated tariff"
                    />
                </div>
                <div className="space-y-1.5">
                    <Label
                        htmlFor="comparison-period"
                        className="text-xs text-muted-foreground uppercase"
                    >
                        Common verified period
                    </Label>
                    <Select
                        disabled={periods.length === 0}
                        value={selectedPeriod ?? undefined}
                        onValueChange={(period) =>
                            router.get(AnalyticsController.comparison.url(), {
                                period,
                            })
                        }
                    >
                        <SelectTrigger
                            id="comparison-period"
                            className="w-[241px] bg-card"
                        >
                            <SelectValue placeholder="No comparable period" />
                        </SelectTrigger>
                        <SelectContent>
                            {periods.map((period) => (
                                <SelectItem key={period} value={period}>
                                    {period.slice(0, 7)}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>
                <Card className="min-w-0 rounded-[13px] shadow-none">
                    <CardHeader>
                        <CardTitle className="text-[17px]">
                            Building-level comparison
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <Table aria-label="Common-period building comparison">
                            <TableHeader>
                                <TableRow>
                                    {[
                                        'Building',
                                        'Total kWh',
                                        ...(canViewCost
                                            ? ['Actual bill cost']
                                            : []),
                                        'kWh/m²',
                                        'Load factor',
                                        'Latest month',
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
                                            colSpan={canViewCost ? 6 : 5}
                                            className="py-5 text-sm text-muted-foreground"
                                        >
                                            No assigned buildings available.
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    rows.map((row) => (
                                        <TableRow key={row.id}>
                                            <TableCell className="text-xs">
                                                {row.name}
                                                {row.stale && (
                                                    <span className="block text-muted-foreground">
                                                        Reprocessing required
                                                    </span>
                                                )}
                                            </TableCell>
                                            <TableCell className="text-xs">
                                                {ranked(row.kwh, row.kwhRank)}
                                            </TableCell>
                                            {canViewCost && (
                                                <TableCell className="text-xs">
                                                    {row.costPhp === null
                                                        ? 'Not supplied / not comparable'
                                                        : ranked(
                                                              row.costPhp,
                                                              row.costPhpRank,
                                                          )}
                                                </TableCell>
                                            )}
                                            <TableCell className="text-xs">
                                                {row.kwhPerM2 === null
                                                    ? 'Missing m² / not comparable'
                                                    : ranked(
                                                          row.kwhPerM2,
                                                          row.kwhPerM2Rank,
                                                      )}
                                            </TableCell>
                                            <TableCell className="text-xs">
                                                {formatEnergy(
                                                    row.loadFactor,
                                                    6,
                                                )}
                                            </TableCell>
                                            <TableCell className="text-xs">
                                                {row.latestMonth?.slice(0, 7) ??
                                                    '—'}
                                            </TableCell>
                                        </TableRow>
                                    ))
                                )}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
                <EnergyNotice title="Ranking must include context" warning>
                    Comparisons are fair only with comparable functions,
                    operating schedules and sufficient verified data. Values and
                    rankings use a common complete period from current datasets.
                    Unknown intensity/cost are not ranked, different months are
                    not added together, and a high usage rank is not an
                    efficiency judgment. Multi-meter coincident peak demand is
                    unknown.
                </EnergyNotice>
                <EnergyFooter />
            </div>
        </>
    );
}
