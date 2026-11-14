import { Head, usePage } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { usersAccess } from '@/routes';
import type { UsersAccessData } from '@/types';

export default function UsersAccess() {
    const { roles, matrix } = usePage().props as unknown as UsersAccessData;

    return (
        <>
            <Head title="Users & Access" />
            <div className="flex flex-1 flex-col gap-6 px-7 pt-6 pb-10">
                <div>
                    <p className="text-[11px] font-bold tracking-[0.06em] text-[#2e61a1] uppercase">
                        QC Energy Intelligence • Workspace
                    </p>
                    <h1 className="mt-1.5 text-[29px] leading-9 font-semibold text-[#1c2b40]">
                        Users &amp; Access
                    </h1>
                    <p className="mt-2 text-[14px] text-muted-foreground">
                        Manage five proposed operational roles, least privilege
                        and building assignment.
                    </p>
                </div>

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    {roles.map((role) => (
                        <Card
                            key={role.name}
                            className="rounded-[12px] border-border bg-card shadow-none"
                        >
                            <CardContent className="flex flex-col gap-2 px-5 py-4">
                                <span className="text-[12px] font-semibold text-muted-foreground">
                                    {role.name}
                                </span>
                                <span className="text-[23px] leading-8 font-bold text-[#1c2b40]">
                                    {role.status}
                                </span>
                            </CardContent>
                        </Card>
                    ))}
                </div>

                <Card className="rounded-[13px] border-border bg-card">
                    <CardHeader className="pb-3">
                        <CardTitle className="text-[17px] font-semibold text-[#1c2b40]">
                            Role-based permission matrix
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <Table>
                            <TableHeader>
                                <TableRow className="border-0 hover:bg-transparent">
                                    <TableHead className="h-10 rounded-l-[6px] bg-[#eff4fb] px-3 text-[11px] font-semibold text-primary">
                                        Capability
                                    </TableHead>
                                    <TableHead className="h-10 bg-[#eff4fb] px-3 text-[11px] font-semibold text-primary">
                                        Admin
                                    </TableHead>
                                    <TableHead className="h-10 bg-[#eff4fb] px-3 text-[11px] font-semibold text-primary">
                                        Energy Manager
                                    </TableHead>
                                    <TableHead className="h-10 bg-[#eff4fb] px-3 text-[11px] font-semibold text-primary">
                                        Facilities
                                    </TableHead>
                                    <TableHead className="h-10 rounded-r-[6px] bg-[#eff4fb] px-3 text-[11px] font-semibold text-primary">
                                        Budget / Viewer
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {matrix.map((row) => (
                                    <TableRow
                                        key={row.capability}
                                        className="border-0 hover:bg-transparent"
                                    >
                                        <TableCell className="h-11 px-3 text-[12px] text-[#1c2b40]">
                                            {row.capability}
                                        </TableCell>
                                        <TableCell className="h-11 px-3 text-[12px] text-[#1c2b40]">
                                            {row.admin}
                                        </TableCell>
                                        <TableCell className="h-11 px-3 text-[12px] text-[#1c2b40]">
                                            {row.energyManager}
                                        </TableCell>
                                        <TableCell className="h-11 px-3 text-[12px] text-[#1c2b40]">
                                            {row.facilities}
                                        </TableCell>
                                        <TableCell className="h-11 px-3 text-[12px] text-[#1c2b40]">
                                            {row.budgetViewer}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                <div className="rounded-[9px] bg-[#fff5e3] px-6 py-4">
                    <p className="text-[14px] font-semibold text-primary">
                        Permission matrix is proposed — pending City Hall
                        confirmation
                    </p>
                    <p className="mt-1 text-[12px] leading-4.5 text-[#52647e]">
                        This matrix reflects the technical proposal (§6.2) and
                        the Admin frame. It is enforced server-side by Laravel
                        roles and policies, never by hidden frontend controls
                        alone. Deploying real user accounts requires ICT
                        approval and a Data Privacy Officer review of user data
                        handling (RA 10173) before go-live.
                    </p>
                </div>

                <footer className="border-t border-border pt-4 pb-2">
                    <p className="text-[11px] leading-4 text-muted-foreground">
                        QC Energy Intelligence • Quezon City Hall Energy
                        Analytics, Forecasting, and Decision Support System.
                        Reference: technical proposal dated October 25, 2026.
                    </p>
                </footer>
            </div>
        </>
    );
}

UsersAccess.layout = {
    breadcrumbs: [
        {
            title: 'Users & Access',
            href: usersAccess(),
        },
    ],
};
