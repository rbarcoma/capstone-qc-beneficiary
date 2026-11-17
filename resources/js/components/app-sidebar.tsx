import { Link, usePage } from '@inertiajs/react';
import {
    BarChart3,
    Bell,
    Building2,
    Columns3,
    FileText,
    Gauge,
    HeartPulse,
    LayoutGrid,
    Lightbulb,
    ListChecks,
    PhilippinePeso,
    ScrollText,
    Settings,
    ShieldCheck,
    SlidersHorizontal,
    SunMedium,
    TrendingUp,
    Upload,
    Users,
} from 'lucide-react';
import { NavGroups } from '@/components/nav-groups';
import {
    Sidebar,
    SidebarContent,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { buildingsMeters, dashboard, usersAccess } from '@/routes';
import DataManagementController from '@/actions/App/Http/Controllers/DataManagementController';
import VerificationController from '@/actions/App/Http/Controllers/VerificationController';
import PreprocessingController from '@/actions/App/Http/Controllers/PreprocessingController';
import AnalyticsController from '@/actions/App/Http/Controllers/AnalyticsController';
import ForecastController from '@/actions/App/Http/Controllers/ForecastController';
import SolarScenarioController from '@/actions/App/Http/Controllers/SolarScenarioController';
import DecisionSupportController from '@/actions/App/Http/Controllers/DecisionSupportController';
import AuditLogController from '@/actions/App/Http/Controllers/AuditLogController';
import ActionTrackerController from '@/actions/App/Http/Controllers/ActionTrackerController';
import ReportController from '@/actions/App/Http/Controllers/ReportController';
import NotificationController from '@/actions/App/Http/Controllers/NotificationController';
import SystemHealthController from '@/actions/App/Http/Controllers/SystemHealthController';
import SystemSettingsController from '@/actions/App/Http/Controllers/SystemSettingsController';
import type { NavGroup, UserRole } from '@/types';

// Provisional role visibility per the proposed permission matrix
// (docs/ROLES_AND_PERMISSIONS.md §2 — pending City Hall confirmation).
// Items without `roles` are visible to every role. Server-side routes
// enforce the same rules; this filter is presentation only.
const ADMIN: UserRole[] = ['admin'];
const ADMIN_EM: UserRole[] = ['admin', 'energy_manager'];
const ADMIN_EM_FACILITIES: UserRole[] = [
    'admin',
    'energy_manager',
    'facilities_personnel',
];
const BUDGET_VIEWERS: UserRole[] = [
    'admin',
    'energy_manager',
    'budget_officer',
    'viewer',
];

const navGroups: NavGroup[] = [
    {
        label: 'Workspace',
        items: [
            {
                title: 'Dashboard',
                href: dashboard(),
                icon: LayoutGrid,
            },
        ],
    },
    {
        label: 'Data Operations',
        items: [
            {
                title: 'Buildings & Meters',
                href: buildingsMeters(),
                icon: Building2,
                roles: ADMIN_EM_FACILITIES,
            },
            {
                title: 'Data Management',
                href: DataManagementController.index(),
                icon: Upload,
                roles: ADMIN_EM_FACILITIES,
            },
            {
                title: 'Verification Queue',
                href: VerificationController.index(),
                icon: ShieldCheck,
                roles: ADMIN_EM,
            },
            {
                title: 'Preprocessing',
                href: PreprocessingController.index(),
                icon: SlidersHorizontal,
                roles: ADMIN_EM,
            },
        ],
    },
    {
        label: 'Insights & Planning',
        items: [
            {
                title: 'Energy Analytics',
                href: AnalyticsController.index(),
                icon: BarChart3,
            },
            {
                title: 'Building Comparison',
                href: AnalyticsController.comparison(),
                icon: Columns3,
            },
            {
                title: 'Forecasting',
                href: ForecastController.index(),
                icon: TrendingUp,
            },
            {
                title: 'Model Evaluation',
                href: ForecastController.evaluation(),
                icon: Gauge,
            },
            {
                title: 'Decision Support',
                href: DecisionSupportController.index(),
                icon: Lightbulb,
            },
            {
                title: 'Action Tracker',
                href: ActionTrackerController.index(),
                icon: ListChecks,
            },
            {
                title: 'Solar & Scenarios',
                href: SolarScenarioController.index(),
                icon: SunMedium,
            },
            {
                title: 'Budget Planning',
                href: null,
                icon: PhilippinePeso,
                disabled: true,
                roles: BUDGET_VIEWERS,
            },
        ],
    },
    {
        label: 'Operations',
        items: [
            {
                title: 'Reports',
                href: ReportController.index(),
                icon: FileText,
            },
            {
                title: 'Notifications',
                href: NotificationController.index(),
                icon: Bell,
            },
        ],
    },
    {
        label: 'Administration',
        items: [
            {
                title: 'Users & Access',
                href: usersAccess(),
                icon: Users,
                roles: ADMIN,
            },
            {
                title: 'Audit Logs',
                href: AuditLogController.index(),
                icon: ScrollText,
                roles: ADMIN,
            },
            {
                title: 'System Settings',
                href: SystemSettingsController.index(),
                icon: Settings,
                roles: ADMIN_EM,
            },
            {
                title: 'System Health',
                href: SystemHealthController.index(),
                icon: HeartPulse,
                roles: ADMIN,
            },
        ],
    },
];

export function AppSidebar() {
    const { auth, navigationModules } = usePage().props;
    const modulesByLabel = navigationModules as
        | Record<string, string>
        | undefined;

    const role = auth.user?.role;
    const filteredGroups: NavGroup[] = navGroups
        .map((group) => ({
            ...group,
            items: group.items.filter(
                (item) =>
                    (!item.roles ||
                        role === undefined ||
                        item.roles.includes(role)) &&
                    (!modulesByLabel?.[item.title] ||
                        auth.user?.accessibleModules?.includes(
                            modulesByLabel[item.title],
                        )),
            ),
        }))
        .filter((group) => group.items.length > 0);

    return (
        <Sidebar collapsible="icon" variant="sidebar">
            <SidebarHeader className="gap-0 p-0">
                <SidebarMenu className="px-3 pt-4">
                    <SidebarMenuItem>
                        <Link
                            href={dashboard()}
                            prefetch
                            className="flex items-center gap-3 group-data-[collapsible=icon]:justify-center"
                        >
                            <span className="flex size-[46px] shrink-0 items-center justify-center rounded-[12px] bg-white text-[18px] font-bold text-[#173a7a]">
                                QC
                            </span>
                            <span className="grid leading-tight group-data-[collapsible=icon]:hidden">
                                <span className="text-[16px] font-semibold text-white">
                                    ENERGY DSS
                                </span>
                                <span className="text-[11px] text-[#cddbf0]">
                                    Quezon City Hall
                                </span>
                            </span>
                        </Link>
                    </SidebarMenuItem>
                </SidebarMenu>
                <div className="mx-3 mt-4 flex h-[60px] flex-col justify-center rounded-[9px] bg-[#2a4d86] px-3 group-data-[collapsible=icon]:hidden">
                    <span className="text-[10px] text-[#bbceed] uppercase">
                        Role workspace
                    </span>
                    <span className="mt-0.5 text-[13px] font-semibold text-white">
                        {auth.user?.roleLabel ?? 'Guest'}
                    </span>
                </div>
            </SidebarHeader>

            <SidebarContent className="pt-3">
                <NavGroups groups={filteredGroups} />
            </SidebarContent>
        </Sidebar>
    );
}
