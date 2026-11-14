import type { InertiaLinkProps } from '@inertiajs/react';
import type { UserRole } from '@/types/auth';
import type { LucideIcon } from 'lucide-react';

export type BreadcrumbItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
};

export type NavItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon?: LucideIcon | null;
    isActive?: boolean;
    /** Roles that may see this item; omitted = all roles. */
    roles?: UserRole[];
};

export type DisabledNavItem = Omit<NavItem, 'href'> & {
    href: null;
    disabled: true;
};

export type NavGroup = {
    label: string;
    items: Array<NavItem | DisabledNavItem>;
};
