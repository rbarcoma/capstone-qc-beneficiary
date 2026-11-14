export type MonthlyRecordRow = {
    id: number;
    month: string;
    kwh: string;
    billedDemandKw: string | null;
    costPhp: string | null;
    powerFactor: string | null;
    notes: string | null;
    verificationStatus: 'draft' | 'verified';
    meterLabel: string;
    version: number;
    canVerify: boolean;
    canCorrect: boolean;
};

export type DataManagementData = {
    buildings: {
        id: number;
        code: string;
        name: string;
        meters: { id: number; label: string }[];
    }[];
    selectedBuildingCode: string | null;
    latestVerifiedPeriod: string | null;
    records: {
        data: MonthlyRecordRow[];
        prev_page_url: string | null;
        next_page_url: string | null;
        total: number;
    };
    intakeResult: { importedCount: number; warnings: string[] } | null;
    demoRequested: boolean;
};
