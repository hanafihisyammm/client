import { NavFooter } from '@/components/nav-footer';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import { type NavItem, type SharedData, type User } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { LayoutGrid, Users, FolderKanban } from 'lucide-react';
import AppLogo from './app-logo';

// Base navigation items available to all users
const baseNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
];

// Admin-only navigation items
const adminNavItems: NavItem[] = [
    {
        title: 'User Management',
        href: '/users',
        icon: Users,
    },
    {
        title: 'Projects',
        href: '/projects',
        icon: FolderKanban,
    },
];

const footerNavItems: NavItem[] = [];

export function AppSidebar() {
    const { auth } = usePage<SharedData>().props;
    const user = auth.user as User & { role?: string };
    
    // Determine navigation items based on user role
    const mainNavItems = [...baseNavItems];
    if (user.role === 'admin' || user.role === 'super_admin') {
        mainNavItems.push(...adminNavItems);
    }

    return (
        <Sidebar collapsible="icon" variant="inset" className="w-64">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent className="flex flex-col py-2">
                <NavMain items={mainNavItems} />
                <div className="flex-1 min-h-8" />
            </SidebarContent>

            <SidebarFooter className="border-t border-gray-200 pt-4 pb-2">
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
