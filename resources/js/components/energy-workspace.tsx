import type { ReactNode } from 'react';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { EnergyBuilding } from '@/types';

export function EnergyHeader({
    title,
    description,
    actions,
}: {
    title: string;
    description: string;
    actions?: ReactNode;
}) {
    return (
        <div className="flex flex-wrap items-start justify-between gap-4">
            <div>
                <p className="text-[11px] font-bold tracking-[0.06em] text-[#2e61a1] uppercase">
                    QC Energy Intelligence • Workspace
                </p>
                <h1 className="mt-1.5 text-[29px] leading-9 font-semibold">
                    {title}
                </h1>
                <p className="mt-2 text-[14px] text-muted-foreground">
                    {description}
                </p>
            </div>
            {actions && (
                <div className="flex flex-wrap items-center gap-2.5">
                    {actions}
                </div>
            )}
        </div>
    );
}

export function EnergyFilters({
    buildings,
    selected,
    latest,
    onChange,
}: {
    buildings: EnergyBuilding[];
    selected: string | null;
    latest: string | null;
    onChange: (value: string) => void;
}) {
    return (
        <div className="flex flex-wrap items-end gap-4">
            <div className="space-y-1.5">
                <Label
                    htmlFor="energy-building"
                    className="text-[10px] font-bold tracking-[0.08em] text-muted-foreground uppercase"
                >
                    Building
                </Label>
                <Select
                    value={selected ?? undefined}
                    disabled={buildings.length === 0}
                    onValueChange={onChange}
                >
                    <SelectTrigger
                        id="energy-building"
                        className="h-10 w-[185px] rounded-[9px] bg-card text-[13px] font-bold"
                    >
                        <SelectValue placeholder="No assigned buildings" />
                    </SelectTrigger>
                    <SelectContent>
                        {buildings.map((building) => (
                            <SelectItem key={building.id} value={building.code}>
                                {building.name}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </div>
            <div className="space-y-1.5">
                <Label
                    htmlFor="energy-latest"
                    className="text-[10px] font-bold tracking-[0.08em] text-muted-foreground uppercase"
                >
                    Latest verified period
                </Label>
                <Input
                    id="energy-latest"
                    readOnly
                    value={latest ?? 'No verified record'}
                    className="h-10 w-[241px] rounded-[9px] bg-card text-[13px] font-bold"
                />
            </div>
        </div>
    );
}

export function EnergyMetric({
    title,
    value,
    caption,
}: {
    title: string;
    value: string | number;
    caption?: string;
}) {
    return (
        <Card className="rounded-xl shadow-none">
            <CardContent className="flex flex-col gap-2 px-5 py-4">
                <p className="text-[12px] font-semibold text-muted-foreground">
                    {title}
                </p>
                <p className="text-[23px] leading-8 font-bold">{value}</p>
                {caption && (
                    <p className="text-[11px] text-muted-foreground">
                        {caption}
                    </p>
                )}
            </CardContent>
        </Card>
    );
}

export function EnergyNotice({
    title,
    children,
    warning = false,
}: {
    title: string;
    children: ReactNode;
    warning?: boolean;
}) {
    return (
        <div
            className={`rounded-[9px] px-6 py-4 ${warning ? 'bg-[#fff5e3]' : 'bg-secondary'}`}
        >
            <p className="text-[14px] font-semibold text-primary">{title}</p>
            <div className="mt-1 text-[12px] leading-4.5 text-[#52647e]">
                {children}
            </div>
        </div>
    );
}

export function EnergyFooter() {
    return (
        <footer className="border-t border-border pt-4 text-[11px] text-muted-foreground">
            QC Energy Intelligence • Quezon City Hall Energy Analytics,
            Forecasting, and Decision Support System. Reference: technical
            proposal dated October 25, 2026.
        </footer>
    );
}

export function formatEnergy(
    value: number | null | undefined,
    digits = 3,
): string {
    return value === null || value === undefined || !Number.isFinite(value)
        ? '—'
        : value.toLocaleString(undefined, { maximumFractionDigits: digits });
}
