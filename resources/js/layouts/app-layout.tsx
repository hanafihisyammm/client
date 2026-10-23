import AppLayoutTemplate from '@/layouts/app/app-sidebar-layout';
import { type BreadcrumbItem } from '@/types';
import { type ReactNode } from 'react';

interface AppLayoutProps {
    children: ReactNode;
    breadcrumbs?: BreadcrumbItem[];
    /** Pin the page to the viewport so only inner panels scroll */
    fillHeight?: boolean;
}

export default ({ children, breadcrumbs, fillHeight, ...props }: AppLayoutProps) => (
    <AppLayoutTemplate breadcrumbs={breadcrumbs} fillHeight={fillHeight} {...props}>
        {children}
    </AppLayoutTemplate>
);
