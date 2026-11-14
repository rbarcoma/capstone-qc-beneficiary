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
import { dashboard } from '@/routes';
import type { NavGroup } from '@/types';

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
            { title: 'Buildings & Meters', href: null, icon: Building2, disabled: true },
            { title: 'Data Management', href: null, icon: Upload, disabled: true },
            {
                title: 'Verification Queue',
                href: null,
                icon: ShieldCheck,
                disabled: true,
            },
            {
                title: 'Preprocessing',
                href: null,
                icon: SlidersHorizontal,
                disabled: true,
            },
        ],
    },
    {
        label: 'Insights & Planning',
        items: [
            {
                title: 'Energy Analytics',
                href: null,
                icon: BarChart3,
                disabled: true,
            },
            {
                title: 'Building Comparison',
                href: null,
                icon: Columns3,
                disabled: true,
            },
            { title: 'Forecasting', href: null, icon: TrendingUp, disabled: true },
            {
                title: 'Model Evaluation',
                href: null,
                icon: Gauge,
                disabled: true,
            },
            {
                title: 'Decision Support',
                href: null,
                icon: Lightbulb,
                disabled: true,
            },
            {
                title: 'Action Tracker',
                href: null,
                icon: ListChecks,
                disabled: true,
            },
            {
                title: 'Solar & Scenarios',
                href: null,
                icon: SunMedium,
                disabled: true,
            },
            {
                title: 'Budget Planning',
                href: null,
                icon: PhilippinePeso,
                disabled: true,
            },
        ],
    },
    {
        label: 'Operations',
        items: [
            { title: 'Reports', href: null, icon: FileText, disabled: true },
            { title: 'Notifications', href: null, icon: Bell, disabled: true },
        ],
    },
    {
        label: 'Administration',
        items: [
            { title: 'Users & Access', href: null, icon: Users, disabled: true },
            {
                title: 'Audit Logs',
                href: null,
                icon: ScrollText,
                disabled: true,
            },
            { title: 'Settings', href: null, icon: Settings, disabled: true },
            {
                title: 'System Health',
                href: null,
                icon: HeartPulse,
                disabled: true,
            },
        ],
    },
];

export function AppSidebar() {
    const { auth } = usePage().props;

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
                        {auth.user?.name ?? 'Guest'}
                    </span>
                </div>
            </SidebarHeader>

            <SidebarContent className="pt-3">
                <NavGroups groups={navGroups} />
            </SidebarContent>
        </Sidebar>
    );
}
