export type BuildingOption = {
    code: string;
    name: string;
};

export type ReadinessRow = {
    building: string;
    verifiedCoverage: number;
    latestMonth: string | null;
    forecastReady: boolean;
};

export type TrendPoint = {
    month: string;
    kwh: number;
};

export type DashboardData = {
    buildings: BuildingOption[];
    selectedBuildingCode: string | null;
    latestVerifiedPeriod: string | null;
    kpis: {
        pilotBuildings: number;
        verifiedMonths: number;
        nextForecast: string | null;
        pendingVerification: number;
    };
    readiness: ReadinessRow[];
    trend: TrendPoint[];
};
