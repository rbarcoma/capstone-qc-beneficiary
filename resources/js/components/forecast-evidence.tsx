import { formatEnergy } from '@/components/energy-workspace';
import { Badge } from '@/components/ui/badge';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import type { ForecastRun } from '@/types';

export function ForecastMetrics({
    run,
}: {
    run: ForecastRun | null | undefined;
}) {
    return (
        <>
            <Table aria-label="Forecast model metrics">
                <TableHeader>
                    <TableRow>
                        {[
                            'Model',
                            'MAE (kWh)',
                            'RMSE (kWh)',
                            'R²',
                            'Validation',
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
                    {(
                        [
                            [
                                'linear_regression',
                                'Linear Regression (primary)',
                            ],
                            [
                                'previous_3_month_average',
                                'Previous-three-month mean',
                            ],
                        ] as const
                    ).map(([key, label]) => {
                        const metric = run?.metrics?.[key];
                        return (
                            <TableRow key={key}>
                                <TableCell className="text-xs">
                                    {label}
                                </TableCell>
                                <TableCell className="text-xs">
                                    {formatEnergy(metric?.mae)}
                                </TableCell>
                                <TableCell className="text-xs">
                                    {formatEnergy(metric?.rmse)}
                                </TableCell>
                                <TableCell className="text-xs">
                                    {metric?.r2 === null
                                        ? 'Undefined'
                                        : formatEnergy(metric?.r2)}
                                </TableCell>
                                <TableCell>
                                    <Badge variant="secondary">
                                        {metric
                                            ? `${metric.test_months} walk-forward months`
                                            : 'No current evidence'}
                                    </Badge>
                                </TableCell>
                            </TableRow>
                        );
                    })}
                </TableBody>
            </Table>
            <p className="mt-3 text-xs text-muted-foreground">
                Identical historical periods for both methods. Undefined R² is
                not zero or a perfect score. Model selection is not automatic.
            </p>
        </>
    );
}

export function BacktestEvidence({
    run,
}: {
    run: ForecastRun | null | undefined;
}) {
    return (
        <Table aria-label="Walk-forward forecast evidence">
            <TableHeader>
                <TableRow>
                    {[
                        'Month',
                        'Verified actual kWh',
                        'Linear predicted kWh',
                        'Baseline kWh',
                        'Training ends',
                        'Training rows',
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
                {!run?.evidence.length ? (
                    <TableRow>
                        <TableCell
                            colSpan={6}
                            className="py-5 text-xs text-muted-foreground"
                        >
                            No current walk-forward evidence available.
                        </TableCell>
                    </TableRow>
                ) : (
                    run.evidence.map((row) => (
                        <TableRow key={row.month}>
                            <TableCell className="text-xs">
                                {row.month.slice(0, 7)}
                            </TableCell>
                            <TableCell className="text-xs">
                                {formatEnergy(row.actual_kwh)}
                            </TableCell>
                            <TableCell className="text-xs">
                                {formatEnergy(row.linear_kwh)}
                            </TableCell>
                            <TableCell className="text-xs">
                                {formatEnergy(row.baseline_kwh)}
                            </TableCell>
                            <TableCell className="text-xs">
                                {row.train_end_month.slice(0, 7)}
                            </TableCell>
                            <TableCell className="text-xs">
                                {row.training_rows}
                            </TableCell>
                        </TableRow>
                    ))
                )}
            </TableBody>
        </Table>
    );
}

export function forecastState(state: ForecastRun['state']): string {
    return {
        current: 'Current',
        historical: 'Historical',
        historical_engine: 'Historical model version',
        superseded_source: 'Source superseded — values withheld',
    }[state];
}

export function forecastReason(reason: string | null | undefined): string {
    return (
        (
            {
                missing_current_dataset:
                    'Process the complete verified building history first.',
                stale_dataset:
                    'Source inputs changed. Reprocess current verified data before running.',
                insufficient_history:
                    'Insufficient complete observations for training and held-out validation.',
                non_contiguous_verified_history:
                    'Resolve missing calendar months; no bill values are imputed.',
                latest_period_not_completely_verified:
                    'The latest uploaded period is not completely verified across all registered meters.',
                missing_or_unverified_weather:
                    'Resolve missing approved NASA POWER parameters or native units; weather is not imputed.',
            } as Record<string, string>
        )[reason ?? ''] ??
        'Review verified history, source weather and current dataset lineage.'
    );
}
