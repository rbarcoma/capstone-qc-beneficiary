export type RoleCard = {
    name: string;
    status: 'Existing' | 'Proposed';
};

export type MatrixRow = {
    capability: string;
    admin: string;
    energyManager: string;
    facilities: string;
    budgetViewer: string;
};

export type UsersAccessData = {
    roles: RoleCard[];
    matrix: MatrixRow[];
    users: UserAccountPage;
    filters: { search: string; role: string; status: string; building: string };
    counts: {
        total: number;
        active: number;
        setupRequired: number;
        deactivated: number;
    };
    buildings: { id: number; name: string; code: string }[];
    roleOptions: {
        value: UserRole;
        label: string;
        buildingRestricted: boolean;
    }[];
    moduleCatalogue: ModuleOption[];
    temporaryPasswordMinutes: number;
};

export type ModuleOption = {
    key: string;
    label: string;
    roles: UserRole[];
    description: string;
    requiredFor: UserRole[];
};

export type UserAccount = {
    id: number;
    name: string;
    email: string;
    role: UserRole;
    roleLabel: string;
    version: number;
    isActive: boolean;
    setupRequired: boolean;
    setupExpired: boolean;
    emailVerified: boolean;
    temporaryPasswordExpiresAt: string | null;
    deactivatedAt: string | null;
    buildingIds: number[];
    buildingNames: string[];
    restrictedModules: string[];
};

export type UserAccountPage = {
    data: UserAccount[];
    current_page: number;
    last_page: number;
    total: number;
    from: number | null;
    to: number | null;
    prev_page_url: string | null;
    next_page_url: string | null;
};
export type AccountHistory = {
    data: {
        id: number;
        event: string;
        reason: string | null;
        before: Record<string, unknown> | null;
        after: Record<string, unknown> | null;
        createdAt: string;
        actorName: string;
    }[];
    prev_page_url: string | null;
    next_page_url: string | null;
};
import type { UserRole } from './auth';
