import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { toast } from 'sonner';
import InputError from '@/components/input-error';
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
import BuildingsController from '@/actions/App/Http/Controllers/BuildingsController';
import { buildingsMeters } from '@/routes';
import type { BuildingRow, MeterRow, RegistryData } from '@/types';

export default function BuildingsMeters() {
    const { buildings, selectedBuildingCode, canManage, latestVerifiedPeriod } =
        usePage().props as unknown as RegistryData;
    const isFacilities =
        usePage().props.auth.user.role === 'facilities_personnel';

    const [buildingDialogOpen, setBuildingDialogOpen] = useState(false);
    const [meterDialogOpen, setMeterDialogOpen] = useState(false);
    const [editingBuildingId, setEditingBuildingId] = useState<number | null>(
        null,
    );
    const [editingMeterId, setEditingMeterId] = useState<number | null>(null);
    const [removal, setRemoval] = useState<{
        kind: 'building' | 'meter';
        id: number;
        label: string;
    } | null>(null);
    const removalForm = useForm({ registry: '' });
    const visibleBuildings = selectedBuildingCode
        ? buildings.filter((building) => building.code === selectedBuildingCode)
        : buildings;

    const buildingForm = useForm({
        name: '',
        code: '',
        floor_area_m2: '',
        operating_hours: '',
        notes: '',
    });

    const meterForm = useForm({
        building_id: '',
        meralco_account_ref: '',
        tariff_class: '',
        status: 'active',
    });

    const pilotCaption =
        buildings.length > 0
            ? buildings.map((building) => building.name).join(' and ')
            : 'No pilot buildings registered yet';

    const openBuilding = (building?: BuildingRow) => {
        buildingForm.clearErrors();
        setEditingBuildingId(building?.id ?? null);
        buildingForm.setData({
            name: building?.name ?? '',
            code: building?.code ?? '',
            floor_area_m2:
                building?.floorAreaM2 == null
                    ? ''
                    : String(building.floorAreaM2),
            operating_hours: building?.operatingHours ?? '',
            notes: building?.notes ?? '',
        });
        setBuildingDialogOpen(true);
    };

    const openMeter = (building?: BuildingRow, meter?: MeterRow) => {
        meterForm.clearErrors();
        setEditingMeterId(meter?.id ?? null);
        meterForm.setData({
            building_id: building ? String(building.id) : '',
            meralco_account_ref: '',
            tariff_class: meter?.tariffClass ?? '',
            status: meter?.status ?? 'active',
        });
        setMeterDialogOpen(true);
    };

    const registerBuilding = () => {
        if (buildingForm.processing) return;
        const options = {
            onSuccess: () => {
                buildingForm.reset();
                setBuildingDialogOpen(false);
                toast.success(
                    editingBuildingId
                        ? 'Building updated.'
                        : 'Building registered.',
                );
            },
        };
        if (editingBuildingId !== null) {
            buildingForm.patch(
                BuildingsController.update(editingBuildingId).url,
                options,
            );
        } else {
            buildingForm.post(BuildingsController.store.form().action, options);
        }
    };

    const registerMeter = () => {
        if (meterForm.processing) return;
        const options = {
            onSuccess: () => {
                meterForm.reset();
                setMeterDialogOpen(false);
                toast.success(
                    editingMeterId ? 'Meter updated.' : 'Meter registered.',
                );
            },
        };
        if (editingMeterId !== null) {
            meterForm.patch(
                BuildingsController.updateMeter(editingMeterId).url,
                options,
            );
        } else {
            meterForm.post(
                BuildingsController.storeMeter.form().action,
                options,
            );
        }
    };

    const removeEntry = () => {
        if (!removal) return;
        const action =
            removal.kind === 'building'
                ? BuildingsController.destroy(removal.id)
                : BuildingsController.destroyMeter(removal.id);
        removalForm.delete(action.url, {
            onSuccess: () => {
                setRemoval(null);
                toast.success('Registry entry removed.');
            },
        });
    };

    const confirmRemoval = (
        kind: 'building' | 'meter',
        id: number,
        label: string,
    ) => {
        removalForm.clearErrors();
        setRemoval({ kind, id, label });
    };

    const filterBuilding = (code: string) => {
        router.get(
            buildingsMeters(),
            code === 'all' ? {} : { building: code },
            {
                preserveState: true,
            },
        );
    };

    return (
        <>
            <Head title="Buildings & Meters" />
            <div className="flex flex-1 flex-col gap-6 px-7 pt-6 pb-10">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p className="text-[11px] font-bold tracking-[0.06em] text-[#2e61a1] uppercase">
                            QC Energy Intelligence • Workspace
                        </p>
                        <h1 className="mt-1.5 text-[29px] leading-9 font-semibold text-[#1c2b40]">
                            Buildings &amp; Meters
                        </h1>
                        <p className="mt-2 text-[14px] text-muted-foreground">
                            Portfolio registry: complex → building →
                            meter/account → monthly records.
                        </p>
                    </div>
                    {canManage && (
                        <div className="flex items-center gap-2.5">
                            <Button
                                className="h-10 rounded-[9px] bg-primary px-4 text-[12px] font-semibold"
                                onClick={() => openBuilding()}
                            >
                                Add building
                            </Button>
                            <Button
                                variant="outline"
                                className="h-10 rounded-[9px] border-border bg-card px-4 text-[12px] font-semibold text-primary"
                                onClick={() => openMeter()}
                                disabled={buildings.length === 0}
                            >
                                Register meter
                            </Button>
                        </div>
                    )}
                </div>

                {isFacilities && (
                    <p className="text-[12px] text-muted-foreground">
                        Assigned Building Operations • Facilities Personnel
                    </p>
                )}
                <div className="flex flex-wrap items-end gap-4">
                    <div className="flex flex-col gap-1.5">
                        <Label
                            htmlFor="registry-building"
                            className="text-[10px] font-bold tracking-[0.08em] text-muted-foreground uppercase"
                        >
                            {isFacilities ? 'Assigned building' : 'Building'}
                        </Label>
                        <Select
                            value={selectedBuildingCode ?? 'all'}
                            onValueChange={filterBuilding}
                            disabled={buildings.length === 0}
                        >
                            <SelectTrigger
                                id="registry-building"
                                className="h-10 w-[185px] rounded-[9px] border-input bg-card text-[13px] font-bold text-[#1c2b40]"
                            >
                                <SelectValue placeholder="All buildings" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">
                                    All buildings
                                </SelectItem>
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
                    {!isFacilities && (
                        <div className="flex flex-col gap-1.5">
                            <Label
                                htmlFor="registry-period"
                                className="text-[10px] font-bold tracking-[0.08em] text-muted-foreground uppercase"
                            >
                                Latest verified period
                            </Label>
                            <Input
                                id="registry-period"
                                readOnly
                                value={
                                    latestVerifiedPeriod ?? 'No verified record'
                                }
                                className="h-10 w-[241px] rounded-[9px] border-input bg-card text-[13px] font-bold text-[#1c2b40]"
                            />
                        </div>
                    )}
                </div>

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <MetricCard
                        title="Registered pilot buildings"
                        value={String(buildings.length)}
                        caption={pilotCaption}
                    />
                    <MetricCard
                        title="Meralco accounts"
                        value="Restricted"
                        caption="Masked in UI"
                    />
                    <MetricCard
                        title="Floor-area intensity"
                        value="Conditional"
                        caption="Requires m²"
                    />
                    <MetricCard
                        title="Operating hours"
                        value="Optional"
                        caption="For context"
                    />
                </div>

                <Card className="rounded-[13px] border-border bg-card">
                    <CardHeader className="pb-3">
                        <CardTitle className="text-[17px] font-semibold text-[#1c2b40]">
                            Building registry
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <Table aria-label="Building and meter registry">
                            <TableHeader>
                                <TableRow className="border-0 hover:bg-transparent">
                                    <TableHead className="h-10 rounded-l-[6px] bg-[#eff4fb] px-3 text-[11px] font-semibold text-primary">
                                        Building
                                    </TableHead>
                                    <TableHead className="h-10 bg-[#eff4fb] px-3 text-[11px] font-semibold text-primary">
                                        Code
                                    </TableHead>
                                    <TableHead className="h-10 bg-[#eff4fb] px-3 text-[11px] font-semibold text-primary">
                                        Floor area
                                    </TableHead>
                                    <TableHead className="h-10 bg-[#eff4fb] px-3 text-[11px] font-semibold text-primary">
                                        Account reference
                                    </TableHead>
                                    <TableHead className="h-10 rounded-r-[6px] bg-[#eff4fb] px-3 text-[11px] font-semibold text-primary">
                                        Data coverage
                                    </TableHead>
                                    {canManage && (
                                        <TableHead className="h-10 bg-secondary px-3 text-[11px] font-semibold text-primary">
                                            Actions
                                        </TableHead>
                                    )}
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {visibleBuildings.length === 0 && (
                                    <TableRow>
                                        <TableCell
                                            colSpan={canManage ? 6 : 5}
                                            className="py-6 text-center text-muted-foreground"
                                        >
                                            {isFacilities
                                                ? 'No buildings assigned to your account.'
                                                : 'No buildings registered yet.'}
                                        </TableCell>
                                    </TableRow>
                                )}
                                {visibleBuildings.map((building) => (
                                    <TableRow
                                        key={building.id}
                                        className="border-0 hover:bg-transparent"
                                    >
                                        <TableCell className="h-11 px-3 text-[12px] text-[#1c2b40]">
                                            {building.name}
                                        </TableCell>
                                        <TableCell className="h-11 px-3 text-[12px] text-[#1c2b40]">
                                            {building.code}
                                        </TableCell>
                                        <TableCell className="h-11 px-3 text-[12px] text-[#1c2b40]">
                                            {building.floorAreaM2 === null
                                                ? 'Not provided'
                                                : `${building.floorAreaM2} m²`}
                                        </TableCell>
                                        <TableCell className="h-11 px-3 text-[12px] text-[#1c2b40]">
                                            {building.meters.length === 0 ? (
                                                'Not registered'
                                            ) : (
                                                <ul className="space-y-2">
                                                    {building.meters.map(
                                                        (meter) => (
                                                            <li key={meter.id}>
                                                                <span>
                                                                    {
                                                                        meter.accountRefMasked
                                                                    }{' '}
                                                                    ·{' '}
                                                                    {
                                                                        meter.status
                                                                    }
                                                                </span>
                                                                {canManage && (
                                                                    <div className="flex gap-2">
                                                                        <Button
                                                                            variant="link"
                                                                            size="sm"
                                                                            className="h-auto p-0 text-xs"
                                                                            onClick={() =>
                                                                                openMeter(
                                                                                    building,
                                                                                    meter,
                                                                                )
                                                                            }
                                                                            aria-label={`Edit meter ${meter.accountRefMasked}`}
                                                                        >
                                                                            Edit
                                                                        </Button>
                                                                        <Button
                                                                            variant="link"
                                                                            size="sm"
                                                                            className="h-auto p-0 text-xs text-destructive"
                                                                            onClick={() =>
                                                                                confirmRemoval(
                                                                                    'meter',
                                                                                    meter.id,
                                                                                    meter.accountRefMasked,
                                                                                )
                                                                            }
                                                                            aria-label={`Remove meter ${meter.accountRefMasked}`}
                                                                        >
                                                                            Remove
                                                                        </Button>
                                                                    </div>
                                                                )}
                                                            </li>
                                                        ),
                                                    )}
                                                </ul>
                                            )}
                                        </TableCell>
                                        <TableCell className="h-11 px-3 text-[12px] text-[#1c2b40]">
                                            {building.verifiedMonths} verified{' '}
                                            {building.verifiedMonths === 1
                                                ? 'month'
                                                : 'months'}
                                        </TableCell>
                                        {canManage && (
                                            <TableCell className="px-3">
                                                <div className="flex flex-wrap gap-2">
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        onClick={() =>
                                                            openBuilding(
                                                                building,
                                                            )
                                                        }
                                                        aria-label={`Edit ${building.name}`}
                                                    >
                                                        Edit
                                                    </Button>
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        onClick={() =>
                                                            openMeter(building)
                                                        }
                                                        aria-label={`Register meter for ${building.name}`}
                                                    >
                                                        Register meter
                                                    </Button>
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        className="text-destructive"
                                                        onClick={() =>
                                                            confirmRemoval(
                                                                'building',
                                                                building.id,
                                                                building.name,
                                                            )
                                                        }
                                                        aria-label={`Remove ${building.name}`}
                                                    >
                                                        Remove
                                                    </Button>
                                                </div>
                                            </TableCell>
                                        )}
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                <div className="rounded-[9px] bg-[#eff4fb] px-6 py-4">
                    <p className="text-[14px] font-semibold text-primary">
                        Building-level governance
                    </p>
                    <p className="mt-1 text-[12px] leading-4.5 text-[#52647e]">
                        Facilities access is assigned per building; account
                        references are restricted. Additional buildings can be
                        onboarded without changing the hierarchy.
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

            <Dialog
                open={buildingDialogOpen}
                onOpenChange={setBuildingDialogOpen}
            >
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>
                            {editingBuildingId
                                ? 'Edit building'
                                : 'Add building'}
                        </DialogTitle>
                        <DialogDescription>
                            Register a City Hall building. The code is used by
                            the CSV template (P4), e.g. HIGHRISE.
                        </DialogDescription>
                    </DialogHeader>
                    <form
                        id="building-form"
                        className="flex flex-col gap-3"
                        onSubmit={(event) => {
                            event.preventDefault();
                            registerBuilding();
                        }}
                    >
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="building-name">Name</Label>
                            <Input
                                id="building-name"
                                aria-invalid={!!buildingForm.errors.name}
                                aria-describedby={
                                    buildingForm.errors.name
                                        ? 'building-name-error'
                                        : undefined
                                }
                                value={buildingForm.data.name}
                                onChange={(event) =>
                                    buildingForm.setData(
                                        'name',
                                        event.target.value,
                                    )
                                }
                            />
                            <InputError
                                id="building-name-error"
                                role="alert"
                                message={buildingForm.errors.name}
                            />
                        </div>
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="building-code">
                                Code (uppercase, e.g. HIGHRISE)
                            </Label>
                            <Input
                                id="building-code"
                                aria-invalid={!!buildingForm.errors.code}
                                aria-describedby={
                                    buildingForm.errors.code
                                        ? 'building-code-error'
                                        : undefined
                                }
                                value={buildingForm.data.code}
                                onChange={(event) =>
                                    buildingForm.setData(
                                        'code',
                                        event.target.value.toUpperCase(),
                                    )
                                }
                            />
                            <InputError
                                id="building-code-error"
                                role="alert"
                                message={buildingForm.errors.code}
                            />
                        </div>
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="building-floor-area">
                                Floor area (m², optional)
                            </Label>
                            <Input
                                id="building-floor-area"
                                aria-invalid={
                                    !!buildingForm.errors.floor_area_m2
                                }
                                aria-describedby={
                                    buildingForm.errors.floor_area_m2
                                        ? 'building-floor-area-error'
                                        : undefined
                                }
                                type="number"
                                min="0"
                                step="0.01"
                                value={buildingForm.data.floor_area_m2}
                                onChange={(event) =>
                                    buildingForm.setData(
                                        'floor_area_m2',
                                        event.target.value,
                                    )
                                }
                            />
                            <InputError
                                id="building-floor-area-error"
                                role="alert"
                                message={buildingForm.errors.floor_area_m2}
                            />
                        </div>
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="building-operating-hours">
                                Operating hours (optional)
                            </Label>
                            <Input
                                id="building-operating-hours"
                                aria-invalid={
                                    !!buildingForm.errors.operating_hours
                                }
                                aria-describedby={
                                    buildingForm.errors.operating_hours
                                        ? 'building-operating-hours-error'
                                        : undefined
                                }
                                value={buildingForm.data.operating_hours}
                                onChange={(event) =>
                                    buildingForm.setData(
                                        'operating_hours',
                                        event.target.value,
                                    )
                                }
                            />
                            <InputError
                                id="building-operating-hours-error"
                                role="alert"
                                message={buildingForm.errors.operating_hours}
                            />
                        </div>
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="building-notes">
                                Notes (optional)
                            </Label>
                            <Input
                                id="building-notes"
                                aria-invalid={!!buildingForm.errors.notes}
                                aria-describedby={
                                    buildingForm.errors.notes
                                        ? 'building-notes-error'
                                        : undefined
                                }
                                value={buildingForm.data.notes}
                                onChange={(event) =>
                                    buildingForm.setData(
                                        'notes',
                                        event.target.value,
                                    )
                                }
                            />
                            <InputError
                                id="building-notes-error"
                                role="alert"
                                message={buildingForm.errors.notes}
                            />
                        </div>
                    </form>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setBuildingDialogOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            form="building-form"
                            disabled={buildingForm.processing}
                        >
                            {buildingForm.processing
                                ? 'Saving…'
                                : editingBuildingId
                                  ? 'Save building'
                                  : 'Add building'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog open={meterDialogOpen} onOpenChange={setMeterDialogOpen}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>
                            {editingMeterId ? 'Edit meter' : 'Register meter'}
                        </DialogTitle>
                        <DialogDescription>
                            Attach a Meralco account to a building. Account
                            references are confidential and only shown masked in
                            the UI.
                        </DialogDescription>
                    </DialogHeader>
                    <form
                        id="meter-form"
                        className="flex flex-col gap-3"
                        onSubmit={(event) => {
                            event.preventDefault();
                            registerMeter();
                        }}
                    >
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="meter-building">Building</Label>
                            <Select
                                disabled={editingMeterId !== null}
                                value={meterForm.data.building_id || undefined}
                                onValueChange={(value) =>
                                    meterForm.setData('building_id', value)
                                }
                            >
                                <SelectTrigger
                                    id="meter-building"
                                    aria-invalid={
                                        !!meterForm.errors.building_id
                                    }
                                    aria-describedby={
                                        meterForm.errors.building_id
                                            ? 'meter-building-error'
                                            : undefined
                                    }
                                >
                                    <SelectValue placeholder="Select a building" />
                                </SelectTrigger>
                                <SelectContent>
                                    {buildings.map((building) => (
                                        <SelectItem
                                            key={building.id}
                                            value={String(building.id)}
                                        >
                                            {building.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError
                                id="meter-building-error"
                                role="alert"
                                message={meterForm.errors.building_id}
                            />
                        </div>
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="meter-account-ref">
                                {editingMeterId
                                    ? 'Replacement account reference (optional)'
                                    : 'Meralco account reference'}
                            </Label>
                            <Input
                                id="meter-account-ref"
                                aria-invalid={
                                    !!meterForm.errors.meralco_account_ref
                                }
                                aria-describedby={
                                    meterForm.errors.meralco_account_ref
                                        ? 'meter-account-error'
                                        : undefined
                                }
                                value={meterForm.data.meralco_account_ref}
                                onChange={(event) =>
                                    meterForm.setData(
                                        'meralco_account_ref',
                                        event.target.value,
                                    )
                                }
                            />
                            {editingMeterId && (
                                <p className="text-xs text-muted-foreground">
                                    Leave blank to keep the existing reference.
                                </p>
                            )}
                            <InputError
                                id="meter-account-error"
                                role="alert"
                                message={meterForm.errors.meralco_account_ref}
                            />
                        </div>
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="meter-tariff-class">
                                Tariff class (optional)
                            </Label>
                            <Input
                                id="meter-tariff-class"
                                aria-invalid={!!meterForm.errors.tariff_class}
                                aria-describedby={
                                    meterForm.errors.tariff_class
                                        ? 'meter-tariff-error'
                                        : undefined
                                }
                                value={meterForm.data.tariff_class}
                                onChange={(event) =>
                                    meterForm.setData(
                                        'tariff_class',
                                        event.target.value,
                                    )
                                }
                            />
                            <InputError
                                id="meter-tariff-error"
                                role="alert"
                                message={meterForm.errors.tariff_class}
                            />
                        </div>
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="meter-status">Status</Label>
                            <Select
                                value={meterForm.data.status}
                                onValueChange={(value) =>
                                    meterForm.setData('status', value)
                                }
                            >
                                <SelectTrigger
                                    id="meter-status"
                                    aria-invalid={!!meterForm.errors.status}
                                    aria-describedby={
                                        meterForm.errors.status
                                            ? 'meter-status-error'
                                            : undefined
                                    }
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="active">
                                        Active
                                    </SelectItem>
                                    <SelectItem value="inactive">
                                        Inactive
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <InputError
                                id="meter-status-error"
                                role="alert"
                                message={meterForm.errors.status}
                            />
                        </div>
                    </form>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setMeterDialogOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            form="meter-form"
                            disabled={meterForm.processing}
                        >
                            {meterForm.processing
                                ? 'Saving…'
                                : editingMeterId
                                  ? 'Save meter'
                                  : 'Register meter'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
            <Dialog
                open={removal !== null}
                onOpenChange={(open) => {
                    if (!open && !removalForm.processing) setRemoval(null);
                }}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Remove {removal?.kind}</DialogTitle>
                        <DialogDescription>
                            Remove {removal?.label} from the active registry?
                            Its stored row will be retained. Remove registered
                            meters before removing a building.
                        </DialogDescription>
                    </DialogHeader>
                    <InputError
                        role="alert"
                        message={removalForm.errors.registry}
                    />
                    <DialogFooter>
                        <Button
                            variant="outline"
                            disabled={removalForm.processing}
                            onClick={() => setRemoval(null)}
                        >
                            Cancel
                        </Button>
                        <Button
                            variant="destructive"
                            disabled={removalForm.processing}
                            onClick={removeEntry}
                        >
                            {removalForm.processing
                                ? 'Removing…'
                                : 'Confirm removal'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
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
    caption?: string;
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
                {caption && (
                    <span className="text-[11px] text-muted-foreground">
                        {caption}
                    </span>
                )}
            </CardContent>
        </Card>
    );
}
