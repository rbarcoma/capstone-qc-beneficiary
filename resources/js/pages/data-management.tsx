import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useRef, useState } from 'react';
import { toast } from 'sonner';
import DataManagementController from '@/actions/App/Http/Controllers/DataManagementController';
import InputError from '@/components/input-error';
import { MonthlyRecordActions } from '@/components/monthly-record-actions';
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

const valueFields = [
    { key: 'kwh', label: 'Energy kWh', step: '0.001' },
    {
        key: 'billed_demand_kw',
        label: 'Billed demand kW (optional)',
        step: '0.001',
    },
    { key: 'cost_php', label: 'Cost PHP (optional)', step: '0.01' },
    {
        key: 'power_factor',
        label: 'Power factor (optional, 0–1)',
        step: '0.0001',
    },
] as const;

export default function DataManagement() {
    const {
        buildings,
        selectedBuildingCode,
        latestVerifiedPeriod,
        records,
        intakeResult,
        demoRequested,
    } = usePage().props as unknown as DataManagementData;
    const selected = buildings.find(
        (building) => building.code === selectedBuildingCode,
    );
    const upload = useForm<{ csv: File | null }>({ csv: null });
    const fileInput = useRef<HTMLInputElement>(null);
    const [manualOpen, setManualOpen] = useState(false);
    const [showDemo, setShowDemo] = useState(demoRequested);
    const manual = useForm({
        meter_id: '',
        month: '',
        kwh: '',
        billed_demand_kw: '',
        cost_php: '',
        power_factor: '',
        notes: '',
    });

    const openManual = () => {
        manual.reset();
        manual.clearErrors();
        manual.setData(
            'meter_id',
            selected?.meters.length === 1 ? String(selected.meters[0].id) : '',
        );
        setManualOpen(true);
    };

    return (
        <>
            <Head title="Data Management" />
            <div className="flex flex-1 flex-col gap-6 px-7 pt-6 pb-10">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p className="text-[11px] font-bold tracking-[0.06em] text-[#2e61a1] uppercase">
                            QC Energy Intelligence • Workspace
                        </p>
                        <h1 className="mt-1.5 text-[29px] leading-9 font-semibold">
                            Data Management
                        </h1>
                        <p className="mt-2 text-[14px] text-muted-foreground">
                            Standard monthly CSV intake, bill validation and
                            recorded corrections.
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2.5">
                        <Button
                            asChild
                            variant="outline"
                            className="h-10 rounded-[9px] bg-card text-[12px] font-semibold text-primary"
                        >
                            <a href={DataManagementController.template.url()}>
                                Download CSV template
                            </a>
                        </Button>
                        <Button
                            variant="outline"
                            className="h-10 rounded-[9px] bg-card text-[12px] font-semibold text-primary"
                            onClick={() => setShowDemo(true)}
                            disabled={showDemo}
                        >
                            Load synthetic demo
                        </Button>
                    </div>
                </div>

                <div className="flex flex-wrap items-end gap-4">
                    <div className="flex flex-col gap-1.5">
                        <Label
                            htmlFor="data-building"
                            className="text-[10px] font-bold tracking-[0.08em] text-muted-foreground uppercase"
                        >
                            Building
                        </Label>
                        <Select
                            value={selectedBuildingCode ?? undefined}
                            disabled={buildings.length === 0}
                            onValueChange={(building) =>
                                router.get(
                                    DataManagementController.index.url(),
                                    { building },
                                    { preserveScroll: true },
                                )
                            }
                        >
                            <SelectTrigger
                                id="data-building"
                                className="h-10 w-[185px] rounded-[9px] bg-card text-[13px] font-bold"
                            >
                                <SelectValue placeholder="No assigned buildings" />
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
                    <div className="flex flex-col gap-1.5">
                        <Label
                            htmlFor="latest-verified"
                            className="text-[10px] font-bold tracking-[0.08em] text-muted-foreground uppercase"
                        >
                            Latest verified period
                        </Label>
                        <Input
                            id="latest-verified"
                            readOnly
                            value={latestVerifiedPeriod ?? 'No verified record'}
                            className="h-10 w-[241px] rounded-[9px] bg-card text-[13px] font-bold"
                        />
                    </div>
                </div>

                <ol
                    aria-label="Monthly intake workflow"
                    className="flex flex-wrap gap-2 text-[11px] font-bold text-primary"
                >
                    {[
                        'Receive bill',
                        'Upload and validate',
                        'Independent verification',
                        'Forecast eligible',
                    ].map((step, index) => (
                        <li
                            key={step}
                            className="rounded-lg bg-secondary px-3 py-2"
                        >
                            {index + 1} · {step}
                        </li>
                    ))}
                </ol>

                {showDemo && (
                    <SyntheticDemoPreview onReset={() => setShowDemo(false)} />
                )}

                {intakeResult && (
                    <div
                        role="status"
                        className="rounded-[9px] border border-border bg-secondary p-4 text-sm"
                    >
                        <p>
                            {intakeResult.importedCount} monthly{' '}
                            {intakeResult.importedCount === 1
                                ? 'record saved'
                                : 'records saved'}{' '}
                            as Draft. Independent verification is required.
                        </p>
                        {intakeResult.warnings.map((warning) => (
                            <p key={warning} className="mt-2">
                                {warning}
                            </p>
                        ))}
                    </div>
                )}

                <div className="grid items-stretch gap-4 lg:grid-cols-[minmax(0,1.84fr)_minmax(0,1fr)]">
                    <Card className="min-w-0 rounded-[13px] shadow-none">
                        <CardHeader>
                            <CardTitle className="text-[17px] font-semibold">
                                Upload monthly dataset
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <form
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    upload.post(
                                        DataManagementController.importMethod.url(),
                                        {
                                            forceFormData: true,
                                            preserveScroll: true,
                                            onSuccess: () => {
                                                upload.reset();
                                                if (fileInput.current)
                                                    fileInput.current.value =
                                                        '';
                                                toast.success(
                                                    'Monthly records saved as drafts.',
                                                );
                                            },
                                        },
                                    );
                                }}
                            >
                                <div className="flex flex-col items-center gap-4 rounded-xl border-2 border-dashed border-[#adbfdd] bg-[#f7faff] px-5 py-10 text-center">
                                    <span
                                        aria-hidden="true"
                                        className="text-[43px] leading-none text-[#2e61a1]"
                                    >
                                        ▧
                                    </span>
                                    <Label
                                        htmlFor="monthly-csv"
                                        className="text-[18px] font-semibold"
                                    >
                                        Select a monthly CSV dataset
                                    </Label>
                                    <div className="text-[14px] text-muted-foreground">
                                        <p>
                                            Required: building_code, month, kwh.
                                            Recommended: billed_demand_kw.
                                        </p>
                                        <p>
                                            Optional: cost_php, power_factor and
                                            notes.
                                        </p>
                                    </div>
                                    <Input
                                        ref={fileInput}
                                        id="monthly-csv"
                                        type="file"
                                        accept=".csv,text/csv"
                                        className="max-w-sm bg-card"
                                        onChange={(event) => {
                                            upload.clearErrors();
                                            upload.setData(
                                                'csv',
                                                event.target.files?.[0] ?? null,
                                            );
                                        }}
                                    />
                                    <p className="text-[11px] text-muted-foreground">
                                        Validated by the server. UTF-8 CSV, up
                                        to 2 MB and 1,000 rows.
                                    </p>
                                </div>
                                <ErrorList errors={upload.errors} />
                                <div className="mt-4 flex flex-wrap justify-end gap-2.5">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={openManual}
                                        disabled={!selected?.meters.length}
                                    >
                                        Manual monthly entry
                                    </Button>
                                    <Button
                                        type="submit"
                                        disabled={
                                            !upload.data.csv ||
                                            upload.processing ||
                                            buildings.length === 0
                                        }
                                    >
                                        {upload.processing
                                            ? 'Validating…'
                                            : 'Upload and validate'}
                                    </Button>
                                </div>
                            </form>
                        </CardContent>
                    </Card>
                    <Card className="rounded-[13px] shadow-none">
                        <CardHeader>
                            <CardTitle className="text-[17px] font-semibold">
                                Input safeguards
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <ul className="space-y-6 text-[12.5px]">
                                {[
                                    'Invalid or duplicated periods reject the entire upload',
                                    'Missing history is flagged; values are never filled silently',
                                    'Incomplete current and future months are rejected',
                                    'CSV mapping requires exactly one registered meter per building',
                                    'Outliers require human bill review; no automatic correction',
                                    'Records stay in Draft until independently verified',
                                ].map((text) => (
                                    <li
                                        key={text}
                                        className="flex items-start gap-2.5"
                                    >
                                        <span className="mt-1.5 size-2 shrink-0 rounded-full bg-[#2e61a1]" />
                                        {text}
                                    </li>
                                ))}
                            </ul>
                            <p className="mt-6 text-xs text-muted-foreground">
                                Weather values are separate from this upload
                                template. No weather import runs here.
                            </p>
                        </CardContent>
                    </Card>
                </div>

                <Card className="min-w-0 rounded-[13px] shadow-none">
                    <CardHeader>
                        <CardTitle className="text-[17px] font-semibold">
                            Uploaded billing records
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <Table aria-label="Monthly billing records">
                            <TableHeader>
                                <TableRow>
                                    {[
                                        'Period',
                                        'Energy kWh',
                                        'Demand kW',
                                        'Verification',
                                        'Notes / action',
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
                                            <TableCell className="px-3 text-[12px]">
                                                {record.month.slice(0, 7)}
                                                <span className="block text-muted-foreground">
                                                    {record.meterLabel}
                                                </span>
                                            </TableCell>
                                            <TableCell className="px-3 text-[12px]">
                                                {Number(
                                                    record.kwh,
                                                ).toLocaleString(undefined, {
                                                    maximumFractionDigits: 3,
                                                })}
                                            </TableCell>
                                            <TableCell className="px-3 text-[12px]">
                                                {record.billedDemandKw ??
                                                    'Not provided'}
                                            </TableCell>
                                            <TableCell>
                                                <Badge variant="secondary">
                                                    {record.verificationStatus ===
                                                    'verified'
                                                        ? 'Verified'
                                                        : 'Draft'}
                                                </Badge>
                                            </TableCell>
                                            <TableCell className="max-w-xs text-[12px] whitespace-normal">
                                                {record.notes ??
                                                    'Awaiting bill review'}
                                                <MonthlyRecordActions
                                                    record={record}
                                                />
                                            </TableCell>
                                        </TableRow>
                                    ))
                                )}
                            </TableBody>
                        </Table>
                        {(records.prev_page_url || records.next_page_url) && (
                            <nav
                                aria-label="Billing records pagination"
                                className="mt-4 flex items-center justify-between"
                            >
                                <span className="text-xs text-muted-foreground">
                                    {records.total} records
                                </span>
                                <div className="flex gap-2">
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
                                </div>
                            </nav>
                        )}
                    </CardContent>
                </Card>
                <div className="rounded-[9px] bg-secondary px-6 py-4">
                    <p className="text-[14px] font-semibold text-primary">
                        Source specification
                    </p>
                    <p className="mt-1 text-[12px] leading-4.5 text-[#52647e]">
                        Appendix A of the proposed solution defines the monthly
                        data fields: building_code / month / kwh. Upload only
                        authorized billing data. Synthetic demo values must
                        remain isolated from official records.
                    </p>
                </div>
                <footer className="border-t border-border pt-4 text-[11px] text-muted-foreground">
                    QC Energy Intelligence • Quezon City Hall Energy Analytics,
                    Forecasting, and Decision Support System. Reference:
                    technical proposal dated October 25, 2026.
                </footer>
            </div>

            <Dialog open={manualOpen} onOpenChange={setManualOpen}>
                <DialogContent className="max-h-[90vh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>Manual monthly entry</DialogTitle>
                        <DialogDescription>
                            Choose the meter for {selected?.name}. Entries are
                            saved as Draft.
                        </DialogDescription>
                    </DialogHeader>
                    <form
                        id="manual-entry"
                        className="space-y-3"
                        onSubmit={(event) => {
                            event.preventDefault();
                            manual.post(DataManagementController.store.url(), {
                                preserveScroll: true,
                                onSuccess: () => {
                                    setManualOpen(false);
                                    manual.reset();
                                    toast.success(
                                        'Monthly record saved as Draft.',
                                    );
                                },
                            });
                        }}
                    >
                        <div className="space-y-1.5">
                            <Label htmlFor="manual-meter">Meter</Label>
                            <Select
                                value={manual.data.meter_id || undefined}
                                onValueChange={(value) =>
                                    manual.setData('meter_id', value)
                                }
                            >
                                <SelectTrigger id="manual-meter">
                                    <SelectValue placeholder="Select a meter" />
                                </SelectTrigger>
                                <SelectContent>
                                    {selected?.meters.map((meter) => (
                                        <SelectItem
                                            key={meter.id}
                                            value={String(meter.id)}
                                        >
                                            #{meter.id} · {meter.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={manual.errors.meter_id} />
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="manual-month">Billing month</Label>
                            <Input
                                id="manual-month"
                                type="month"
                                required
                                value={manual.data.month.slice(0, 7)}
                                onChange={(event) =>
                                    manual.setData(
                                        'month',
                                        event.target.value
                                            ? `${event.target.value}-01`
                                            : '',
                                    )
                                }
                            />
                        </div>
                        {valueFields.map((field) => (
                            <div key={field.key} className="space-y-1.5">
                                <Label htmlFor={`manual-${field.key}`}>
                                    {field.label}
                                </Label>
                                <Input
                                    id={`manual-${field.key}`}
                                    type="number"
                                    min="0"
                                    step={field.step}
                                    max={
                                        field.key === 'power_factor'
                                            ? '1'
                                            : undefined
                                    }
                                    required={field.key === 'kwh'}
                                    value={manual.data[field.key]}
                                    onChange={(event) =>
                                        manual.setData(
                                            field.key,
                                            event.target.value,
                                        )
                                    }
                                />
                            </div>
                        ))}
                        <div className="space-y-1.5">
                            <Label htmlFor="manual-notes">
                                Notes (optional)
                            </Label>
                            <Input
                                id="manual-notes"
                                value={manual.data.notes}
                                onChange={(event) =>
                                    manual.setData('notes', event.target.value)
                                }
                            />
                        </div>
                        <ErrorList errors={manual.errors} />
                    </form>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setManualOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            form="manual-entry"
                            disabled={manual.processing}
                        >
                            {manual.processing ? 'Saving…' : 'Save draft'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

function ErrorList({ errors }: { errors: Record<string, string> }) {
    return Object.keys(errors).length > 0 ? (
        <div
            role="alert"
            className="mt-3 rounded-lg border border-destructive p-3 text-sm text-destructive"
        >
            <p className="font-semibold">
                No records were saved. Fix the following:
            </p>
            <ul className="mt-1 list-inside list-disc">
                {Object.entries(errors).map(([key, message]) => (
                    <li key={key}>{message}</li>
                ))}
            </ul>
        </div>
    ) : null;
}
