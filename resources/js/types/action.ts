export type ActionStatus = 'open' | 'completed';

export type TrackedAction = {
    id: number;
    version: number;
    title: string;
    status: ActionStatus;
    outcome: string | null;
    ownerName: string;
    dueDate: string;
    completedAt: string | null;
    ruleCode: string;
    recommendationId: number;
    forecastId: number;
    targetMonth: string;
    buildingName: string;
    canUpdate: boolean;
};

export type ActionHistoryPage = {
    data: {
        id: number;
        actorId: number | null;
        actorName: string;
        createdAt: string | null;
        version: number;
        before: { status: ActionStatus; outcome: string | null };
        after: { status: ActionStatus; outcome: string | null };
    }[];
    next_page_url: string | null;
    prev_page_url: string | null;
};
