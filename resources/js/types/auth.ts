export type UserRole =
    | 'admin'
    | 'energy_manager'
    | 'facilities_personnel'
    | 'budget_officer'
    | 'viewer';

export type User = {
    id: number;
    name: string;
    email: string;
    role: UserRole;
    roleLabel: string;
    allowedBuildingIds: number[] | null;
    avatar?: string;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};

export type Auth = {
    user: User;
};
