export type EnergyBuilding = { id: number; code: string; name: string };
export type EnergyIssue = { month: string; kind: string; message: string };
export type DatasetSummary = {
    id: number;
    version: number;
    fingerprint: string;
    rows_count: number;
    weather_complete_count: number;
};
export type AnalyticsRow = {
    month: string;
    kwh: number;
    costPhp: number | null;
    momPercent: number | null;
    yoyPercent: number | null;
    costMomPercent: number | null;
    kwhPerM2: number | null;
    loadFactor: number | null;
    billedDemandKw: number | null;
    weatherComplete: boolean;
};
export type AnalyticsData = {
    dataset: DatasetSummary | null;
    stale: boolean;
    rows: AnalyticsRow[];
    summary: AnalyticsRow | null;
    canViewCost: boolean;
    issues: EnergyIssue[];
};
export type PreprocessingData = {
    buildings: EnergyBuilding[];
    selectedBuildingCode: string | null;
    latestVerifiedPeriod: string | null;
    metrics: { uploaded: number; verified: number; gaps: number };
    issues: EnergyIssue[];
    currentDataset: DatasetSummary | null;
    versions: {
        id: number;
        version: number;
        cutoff: string;
        rows: number;
        current: boolean;
    }[];
    lastCompletedMonth: string;
    operationResult: { message: string } | null;
    weather: {
        total: number;
        prev_page_url: string | null;
        next_page_url: string | null;
        data: {
            id: number;
            month: string;
            values: Record<string, number | null>;
            units: Record<string, string>;
            complete: boolean;
            quality: {
                expected_days: number;
                missing_days?: Record<string, string[]>;
            };
            source: {
                community?: string;
                time_standard?: string;
                aggregation?: string;
                coordinate_reference?: string;
                header?: { api?: { version?: string }; sources?: string[] };
            };
            fetchedAt: string;
        }[];
    };
};
