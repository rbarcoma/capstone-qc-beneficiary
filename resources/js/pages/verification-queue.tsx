import { Head, Link, router, usePage } from '@inertiajs/react';
import DataManagementController from '@/actions/App/Http/Controllers/DataManagementController';
import VerificationController from '@/actions/App/Http/Controllers/VerificationController';
import { MonthlyRecordActions } from '@/components/monthly-record-actions';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
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
import type { DataManagementData } from '@/types';

type QueueData = Pick<
    DataManagementData,
    'buildings' | 'selectedBuildingCode' | 'latestVerifiedPeriod' | 'records'
> & { metrics: { draft: number; verified: number; corrections: number } };

export default function VerificationQueue() {
    const {
        buildings,
        selectedBuildingCode,
        latestVerifiedPeriod,
        records,
        metrics,
    } = usePage().props as unknown as QueueData;
    return (
        <>
            <Head title="Verification Queue" />
            <div className="flex flex-1 flex-col gap-6 px-7 pt-6 pb-10">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p className="text-[11px] font-bold tracking-[0.06em] text-[#2e61a1] uppercase">
                            QC Energy Intelligence • Workspace
                        </p>
                        <h1 className="mt-1.5 text-[29px] leading-9 font-semibold">
                            Verification Queue
                        </h1>
                        <p className="mt-2 text-[14px] text-muted-foreground">
                            Second-person verification of the data recorded from
                            each original Meralco bill.
                        </p>
                    </div>
                    <Button
                        asChild
                        className="h-10 rounded-[9px] text-[12px] font-semibold"
                    >
                        <Link
                            href={DataManagementController.index({
                                query: { building: selectedBuildingCode },
                            })}
                        >
                            Open uploads
                        </Link>
                    </Button>
                </div>
                <div className="flex flex-wrap items-end gap-4">
                    <div className="space-y-1.5">
                        <Label
                            htmlFor="queue-building"
                            className="text-[10px] font-bold tracking-[0.08em] text-muted-foreground uppercase"
                        >
                            Building
                        </Label>
                        <Select
                            disabled={buildings.length === 0}
                            value={selectedBuildingCode ?? undefined}
                            onValueChange={(building) =>
                                router.get(VerificationController.index.url(), {
                                    building,
                                })
                            }
                        >
                            <SelectTrigger
                                id="queue-building"
                                className="h-10 w-[185px] rounded-[9px] bg-card text-[13px] font-bold"
                            >
                                <SelectValue placeholder="No buildings registered" />
                            </SelectTrigger>
                            <SelectContent>
                                {buildings.map((building) => (
                                    <SelectItem
                                        key={building.id}
                                        value={building.code}
                                    >
                                        {building.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                    <div className="space-y-1.5">
                        <Label
                            htmlFor="queue-period"
                            className="text-[10px] font-bold tracking-[0.08em] text-muted-foreground uppercase"
                        >
                            Latest verified period
                        </Label>
                        <Input
                            id="queue-period"
                            readOnly
                            value={latestVerifiedPeriod ?? 'No verified record'}
                            className="h-10 w-[241px] rounded-[9px] bg-card text-[13px] font-bold"
                        />
                    </div>
                </div>
                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    {[
                        { title: 'Draft records', value: metrics.draft },
                        { title: 'Verified records', value: metrics.verified },
                        {
                            title: 'Overdue uploads',
                            value: 'Not assessed',
                            note: 'Policy timing TBD',
                        },
                        {
                            title: 'Correction history',
                            value: metrics.corrections,
                        },
                    ].map((metric) => (
                        <Card
                            key={metric.title}
                            className="rounded-xl shadow-none"
                        >
                            <CardContent className="flex flex-col gap-2 px-5 py-4">
                                <p className="text-[12px] font-semibold text-muted-foreground">
                                    {metric.title}
                                </p>
                                <p className="text-[23px] leading-8 font-bold">
                                    {metric.value}
                                </p>
                                {metric.note && (
                                    <p className="text-[11px] text-muted-foreground">
                                        {metric.note}
                                    </p>
                                )}
                            </CardContent>
                        </Card>
                    ))}
                </div>
                <Card className="min-w-0 rounded-[13px] shadow-none">
                    <CardHeader>
                        <CardTitle className="text-[17px] font-semibold">
                            Pending monthly verification
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    {[
                                        'Period',
                                        'Uploaded kWh',
                                        'Billed demand',
                                        'Status',
                                        'Reviewer action',
                                    ].map((label) => (
                                        <TableHead
                                            key={label}
                                            className="h-10 bg-secondary px-3 text-[11px] font-semibold text-primary"
                                        >
                                            {label}
                                        </TableHead>
                                    ))}
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {records.data.length === 0 ? (
                                    <TableRow>
                                        <TableCell
                                            colSpan={5}
                                            className="py-5 text-[12px] text-muted-foreground"
                                        >
                                            No records available. Upload and
                                            verify data to populate this view.
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    records.data.map((record) => (
                                        <TableRow key={record.id}>
                                            <TableCell className="text-xs">
                                                {record.month.slice(0, 7)}
                                                <span className="block text-muted-foreground">
                                                    {record.meterLabel}
                                                </span>
                                            </TableCell>
                                            <TableCell className="text-xs">
                                                {record.kwh}
                                            </TableCell>
                                            <TableCell className="text-xs">
                                                {record.billedDemandKw ??
                                                    'Not provided'}
                                            </TableCell>
                                            <TableCell>
                                                <Badge variant="secondary">
                                                    Draft
                                                </Badge>
                                            </TableCell>
                                            <TableCell>
                                                <MonthlyRecordActions
                                                    record={record}
                                                />
                                            </TableCell>
                                        </TableRow>
                                    ))
                                )}
                            </TableBody>
                        </Table>
                        <nav
                            aria-label="Verification pagination"
                            className="mt-3 flex justify-end gap-2"
                        >
                            {records.prev_page_url && (
                                <Button asChild variant="outline">
                                    <Link href={records.prev_page_url}>
                                        Previous
                                    </Link>
                                </Button>
                            )}
                            {records.next_page_url && (
                                <Button asChild variant="outline">
                                    <Link href={records.next_page_url}>
                                        Next
                                    </Link>
                                </Button>
                            )}
                        </nav>
                    </CardContent>
                </Card>
                <div className="rounded-[9px] bg-[#fff5e3] px-6 py-4">
                    <p className="text-[14px] font-semibold text-primary">
                        Verification protects forecast quality
                    </p>
                    <p className="mt-1 text-[12px] leading-4.5 text-[#52647e]">
                        Check totals against the original billing statement. A
                        different person must verify the latest entered or
                        corrected values. Corrections retain previous values and
                        return the record to Draft. Forecast eligibility will be
                        assessed by the forecasting module.
                    </p>
                </div>
                <footer className="border-t border-border pt-4 text-[11px] text-muted-foreground">
                    QC Energy Intelligence • Quezon City Hall Energy Analytics,
                    Forecasting, and Decision Support System. Reference:
                    technical proposal dated October 25, 2026.
                </footer>
            </div>
        </>
    );
}
