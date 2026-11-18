export type ForecastComparison =
    | { state: 'current_forecast_unavailable' | 'reference_unavailable' }
    | {
          state: 'available';
          forecastId: number;
          datasetId: number;
          datasetVersion: number;
          datasetFingerprint: string;
          modelVersion: string;
          targetMonth: string;
          referenceMonth: string;
          forecastKwh: number;
          referenceKwh: number;
          differenceKwh: number;
          differencePercent: number | null;
          referenceRecords: {
              recordId: number;
              meterId: number;
              version: number;
          }[];
      };

export type DecisionReadiness = {
    state: 'current_forecast' | 'eligible_not_issued' | 'not_eligible';
    asOf: string;
    eligible: boolean;
    eligibilityReason: string | null;
    observations: number;
    requiredObservations: number;
    latestCompleteMonth: string | null;
    coverage: {
        fromMonth: string | null;
        throughMonth: string | null;
        requiredMeterCount: number;
        expectedMeterPeriods: number | null;
        verifiedMeterPeriods: number | null;
        missingMeterPeriods: number | null;
        unverifiedMeterPeriods: number | null;
        incompleteMonths: {
            month: string;
            verifiedMeterCount: number;
            missingMeterIds: number[];
            unverifiedMeterIds: number[];
        }[];
    };
    incompleteWeatherMonths: string[];
    notices: { code: string; message: string }[];
};

export type HistoryPeriod = {
    month: string;
    state:
        | 'verified'
        | 'missing'
        | 'incomplete'
        | 'unverified'
        | 'demand_unavailable';
    value: number | null;
    sources: { recordId: number; meterId: number; version: number }[];
    missingMeterIds: number[];
    unverifiedMeterIds: number[];
};

export type HistoryComparison = {
    actual: HistoryPeriod;
    references: HistoryPeriod[];
    referenceAverage: number | null;
    difference: number | null;
    absoluteDifference: number | null;
    differencePercent: number | null;
    percentageReason: 'comparison_unavailable' | 'zero_baseline' | null;
};

export type ExploratoryHistory = {
    assessedMonth: string | null;
    asOf: string;
    sourceFingerprint: string;
    referenceMonths: string[];
    consumption: HistoryComparison | null;
    meters: (HistoryComparison & { meterId: number })[];
};

export type CatalogueRequirement = {
    label: string;
    state: 'available' | 'unavailable' | 'not_assessed';
};

export type ConservationCatalogueData = {
    version: string;
    asOf: string;
    entries: {
        id: string;
        title: string;
        description: string;
        approvalState: 'unapproved';
        eligibilityState: 'not_assessed';
        eligibility: string;
        evidenceRules: ('R2' | 'R3' | 'R4')[];
        requirements: CatalogueRequirement[];
        missingRequirements: CatalogueRequirement[];
        limitations: string[];
        proposedOwners: string[];
    }[];
};

export type HistoricalRateSource = {
    meterId: number;
    recordId: number | null;
    recordVersion: number | null;
    evidenceId: number | null;
    evidenceVersion: number | null;
    state: string;
    periodStart?: string;
    periodEnd?: string;
    pages?: number[];
};

export type HistoricalRateAmounts = {
    importKwh: string;
    grossPhp: string;
    netServicePhp: string;
    grossRate: string | null;
    netServiceRate: string | null;
};

export type HistoricalRates = {
    asOf: string;
    method: string;
    requiredMeterCount: number;
    fromMonth: string | null;
    throughMonth: string | null;
    includedMonths: string[];
    excludedMonths: string[];
    weighted: HistoricalRateAmounts | null;
    rows: {
        month: string;
        state: 'available' | 'zero_import' | 'incomplete';
        importKwh: string | null;
        grossPhp: string | null;
        netServicePhp: string | null;
        exportCreditPhp: string | null;
        excludedOtherPhp: string | null;
        excludedCreditsPhp: string | null;
        grossRate: string | null;
        netServiceRate: string | null;
        sources: HistoricalRateSource[];
    }[];
};
