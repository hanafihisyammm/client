import { Icon } from '@/components/icon';
import {
    SidebarGroup,
    SidebarGroupContent,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { resolveUrl } from '@/lib/utils';
import { type NavItem } from '@/types';
import { type ComponentPropsWithoutRef } from 'react';

export function NavFooter({
    items,
    className,
    ...props
}: ComponentPropsWithoutRef<typeof SidebarGroup> & {
    items: NavItem[];
}) {
    return (
        <SidebarGroup
            {...props}
            className={`group-data-[collapsible=icon]:p-0 px-3 py-2 ${className || ''}`}
        >
            <SidebarGroupContent>
                <SidebarMenu className="space-y-2">
                    {items.map((item) => (
                        <SidebarMenuItem key={item.title}>
                            <SidebarMenuButton
                                asChild
                                className="h-10 px-3 py-2 text-neutral-600 hover:text-neutral-800 dark:text-neutral-300 dark:hover:text-neutral-100"
                            >
                                <a
                                    href={resolveUrl(item.href)}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                >
                                    {item.icon && (
                                        <Icon
                                            iconNode={item.icon}
                                            className="h-4 w-4"
                                        />
                                    )}
                                    <span className="text-sm font-medium">{item.title}</span>
                                </a>
                            </SidebarMenuButton>
                        </SidebarMenuItem>
                    ))}
                </SidebarMenu>
            </SidebarGroupContent>
        </SidebarGroup>
    );
}
