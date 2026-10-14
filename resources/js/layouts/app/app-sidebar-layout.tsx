import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import { FlashMessages } from '@/components/flash-messages';
import { ImpersonationBanner } from '@/components/impersonation-banner';
import { InstallAppBanner } from '@/components/install-app';
import { OfflineBanner } from '@/components/offline-banner';
import { type BreadcrumbItem } from '@/types';
import { type PropsWithChildren } from 'react';

export default function AppSidebarLayout({
    children,
    breadcrumbs = [],
}: PropsWithChildren<{ breadcrumbs?: BreadcrumbItem[] }>) {
    return (
        <AppShell variant="sidebar">
            <FlashMessages />
            <AppSidebar />
            <AppContent variant="sidebar" className="overflow-x-hidden">
                <OfflineBanner />
                <ImpersonationBanner />
                <InstallAppBanner />
                <AppSidebarHeader breadcrumbs={breadcrumbs} />
                <div className="px-6 pt-4 pb-6">
                    {children}
                </div>
            </AppContent>
        </AppShell>
    );
}
