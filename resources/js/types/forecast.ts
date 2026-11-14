import type { EnergyBuilding } from './energy';

export type ForecastMetric = {
    mae: number;
    rmse: number;
    r2: number | null;
    test_months: number;
};
export type ForecastEvidence = {
    month: string;
    actual_kwh: number;
    linear_kwh: number;
    baseline_kwh: number;
    train_end_month: string;
    training_rows: number;
};
export type ForecastRun = {
    id: number;
    datasetVersion: number;
    targetMonth: string;
    trainingEndMonth: string;
    issuedAt: string;
    modelVersion: string;
    sourceCurrent: boolean;
    state: 'current' | 'historical' | 'historical_engine' | 'superseded_source';
    predictionKwh: number | null;
    baselineKwh: number | null;
    lowerKwh: number | null;
    upperKwh: number | null;
    validationLabel: string;
    metrics: Record<
        'linear_regression' | 'previous_3_month_average',
        ForecastMetric
    > | null;
    evidence: ForecastEvidence[];
    actual: {
        valid: boolean;
        reason: string;
        kwh: number | null;
        absoluteError: number | null;
        percentageError: number | null;
    };
};
export type ForecastPageData = {
    buildings: EnergyBuilding[];
    selectedBuildingCode: string | null;
    operationResult: { message: string } | null;
    data: {
        eligibility: {
            eligible: boolean;
            reason: string | null;
            observations: number;
            required: number;
            target_month: string | null;
            last_month: string | null;
            dataset_id: number | null;
            datasetFingerprint: string | null;
        };
        current: ForecastRun | null;
        evaluation: ForecastRun | null;
        history: ForecastRun[];
        canRun: boolean;
        latestVerifiedPeriod: string | null;
        observations: { month: string; kwh: number }[];
    } | null;
};
