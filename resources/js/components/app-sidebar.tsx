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
import { Building2, FileText, FolderKanban, ScrollText, Users } from 'lucide-react';
import AppLogo from './app-logo';

// Base navigation items available to all users
const baseNavItems: NavItem[] = [
    {
        title: 'Issues',
        href: dashboard(),
        icon: FileText,
    },
];

// Admins work across every client company
const adminNavItems: NavItem[] = [
    {
        title: 'Clients',
        href: '/clients',
        icon: Building2,
    },
    {
        title: 'Projects',
        href: '/projects',
        icon: FolderKanban,
    },
    {
        title: 'User Management',
        href: '/users',
        icon: Users,
    },
    {
        title: 'Audit Log',
        href: '/audit-logs',
        icon: ScrollText,
    },
];

// A manager only ever sees their own company
const managerNavItems: NavItem[] = [
    {
        title: 'Projects',
        href: '/projects',
        icon: FolderKanban,
    },
    {
        title: 'My Company',
        href: '/my-company',
        icon: Building2,
    },
];

// Staff see the projects they are assigned to
const staffNavItems: NavItem[] = [
    {
        title: 'My Projects',
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
    } else if (user.role === 'manager') {
        // Link straight to the manager's own company page when they have one
        const company = (user as { company?: { uuid: string } | null }).company;
        mainNavItems.push(
            ...managerNavItems.map((item) =>
                item.title === 'My Company' && company ? { ...item, href: `/clients/${company.uuid}` } : item,
            ),
        );
    } else {
        mainNavItems.push(...staffNavItems);
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

            <SidebarFooter className="border-t border-sidebar-border pt-4 pb-2">
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
