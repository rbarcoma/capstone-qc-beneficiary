import { Link, usePage } from '@inertiajs/react';
import { Breadcrumbs } from '@/components/breadcrumbs';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { UserMenuContent } from '@/components/user-menu-content';
import { useInitials } from '@/hooks/use-initials';
import { dashboard } from '@/routes';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

export function AppSidebarHeader({
    breadcrumbs = [],
}: {
    breadcrumbs?: BreadcrumbItemType[];
}) {
    const page = usePage();
    const { auth } = page.props;
    const getInitials = useInitials();

    return (
        <div>
            <header className="flex h-[70px] shrink-0 items-center justify-between border-b border-border bg-card px-6">
                <div className="flex items-center gap-3">
                    <SidebarTrigger className="-ml-1" />
                    <Link
                        href={dashboard()}
                        prefetch
                        className="text-[11.5px] font-bold tracking-[0.02em] text-[#173a7a] uppercase"
                    >
                        Quezon City Government / Energy Management
                    </Link>
                </div>
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <button
                            type="button"
                            className="flex size-10 cursor-pointer items-center justify-center rounded-full p-1 outline-none"
                        >
                            <Avatar className="size-8 overflow-hidden rounded-full">
                                <AvatarImage
                                    src={auth.user?.avatar}
                                    alt={auth.user?.name}
                                />
                                <AvatarFallback className="rounded-lg bg-[#eff4fb] text-[12px] font-semibold text-[#173a7a]">
                                    {getInitials(auth.user?.name ?? '')}
                                </AvatarFallback>
                            </Avatar>
                        </button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent className="w-56" align="end">
                        {auth.user && <UserMenuContent user={auth.user} />}
                    </DropdownMenuContent>
                </DropdownMenu>
            </header>
            {breadcrumbs.length > 1 && (
                <div className="flex w-full border-b border-border bg-card">
                    <div className="flex h-12 w-full items-center justify-start px-6 text-muted-foreground">
                        <Breadcrumbs breadcrumbs={breadcrumbs} />
                    </div>
                </div>
            )}
        </div>
    );
}
