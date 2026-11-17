import { Link } from '@inertiajs/react';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { cn } from '@/lib/utils';
import type { NavGroup } from '@/types';

export function NavGroups({ groups }: { groups: NavGroup[] }) {
    const { isCurrentUrl } = useCurrentUrl();

    return (
        <>
            {groups.map((group) => (
                <SidebarGroup key={group.label} className="px-2 py-1">
                    <SidebarGroupLabel className="h-6 rounded-none px-2 text-[10px] font-semibold tracking-[0.08em] text-[#a8bddc] uppercase">
                        {group.label}
                    </SidebarGroupLabel>
                    <SidebarMenu className="mx-0">
                        {group.items.map((item) => (
                            <SidebarMenuItem key={item.title}>
                                {item.href ? (
                                    <SidebarMenuButton
                                        asChild
                                        isActive={isCurrentUrl(item.href)}
                                        tooltip={{ children: item.title }}
                                        className="h-[42px] gap-3 rounded-[9px] px-3 text-[12.5px]"
                                    >
                                        <Link href={item.href} prefetch>
                                            {item.icon && (
                                                <item.icon className="size-4" />
                                            )}
                                            <span>{item.title}</span>
                                        </Link>
                                    </SidebarMenuButton>
                                ) : (
                                    <span
                                        className={cn(
                                            'flex h-[42px] items-center gap-3 rounded-[9px] px-3 text-[12.5px] text-sidebar-foreground/75',
                                        )}
                                    >
                                        {item.icon && (
                                            <item.icon className="size-4" />
                                        )}
                                        <span>{item.title}</span>
                                    </span>
                                )}
                            </SidebarMenuItem>
                        ))}
                    </SidebarMenu>
                </SidebarGroup>
            ))}
        </>
    );
}
