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
};
