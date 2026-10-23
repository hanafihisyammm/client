import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import { FlashMessages } from '@/components/flash-messages';
import { ImpersonationBanner } from '@/components/impersonation-banner';
import { InstallAppBanner } from '@/components/install-app';
import { OfflineBanner } from '@/components/offline-banner';
import { type BreadcrumbItem } from '@/types';
import { cn } from '@/lib/utils';
import { type PropsWithChildren } from 'react';

export default function AppSidebarLayout({
    children,
    breadcrumbs = [],
    fillHeight = false,
}: PropsWithChildren<{ breadcrumbs?: BreadcrumbItem[]; fillHeight?: boolean }>) {
    return (
        <AppShell variant="sidebar">
            <FlashMessages />
            <AppSidebar />
            {/* On large screens fillHeight caps the page at the viewport (less the inset margin) */}
            <AppContent
                variant="sidebar"
                className={cn('overflow-x-hidden', fillHeight && 'lg:h-[calc(100svh-(--spacing(4)))] lg:overflow-hidden')}
            >
                <OfflineBanner />
                <ImpersonationBanner />
                <InstallAppBanner />
                <AppSidebarHeader breadcrumbs={breadcrumbs} />
                <div className={cn('px-6 pt-4 pb-6', fillHeight && 'lg:flex lg:min-h-0 lg:flex-1 lg:flex-col')}>
                    {children}
                </div>
            </AppContent>
        </AppShell>
    );
}
