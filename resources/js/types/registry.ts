export type MeterRow = {
    id: number;
    accountRefMasked: string;
    tariffClass: string | null;
    status: 'active' | 'inactive';
};

export type BuildingRow = {
    id: number;
    name: string;
    code: string;
    floorAreaM2: number | null;
    operatingHours: string | null;
    notes: string | null;
    meters: MeterRow[];
    metersCount: number;
    accountRefMasked: string | null;
    verifiedMonths: number;
};

export type RegistryData = {
    buildings: BuildingRow[];
    selectedBuildingCode: string | null;
    canManage: boolean;
    latestVerifiedPeriod: string | null;
};
