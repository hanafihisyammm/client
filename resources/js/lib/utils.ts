import { InertiaLinkProps } from '@inertiajs/react';
import { type ClassValue, clsx } from 'clsx';
import { twMerge } from 'tailwind-merge';

export function cn(...inputs: ClassValue[]) {
    return twMerge(clsx(inputs));
}

export function isSameUrl(
    url1: NonNullable<InertiaLinkProps['href']>,
    url2: NonNullable<InertiaLinkProps['href']>,
) {
    return resolveUrl(url1) === resolveUrl(url2);
}

export function resolveUrl(url: NonNullable<InertiaLinkProps['href']>): string {
    return typeof url === 'string' ? url : url.url;
}

/**
 * Classes for a filter dropdown or date field, matching the Issues page:
 * compact, white, and highlighted while a filter is applied.
 */
export function filterControlClass(active: boolean): string {
    return `h-9 whitespace-nowrap text-sm ${active ? 'border-blue-500 bg-blue-50 text-blue-700' : 'bg-card'}`;
}

/**
 * The timezone the business works in (Malaysia). The server prints it into the
 * page so both sides agree on which calendar day "today" is.
 */
export function appTimezone(): string {
    const fromServer =
        typeof document !== 'undefined' ? document.querySelector('meta[name="app-timezone"]')?.getAttribute('content') : null;

    return fromServer || 'Asia/Kuala_Lumpur';
}

/**
 * Today's calendar date in the business timezone, as YYYY-MM-DD,
 * whatever timezone the viewer's own computer is set to.
 */
export function todayInAppTimezone(now: Date = new Date()): string {
    // en-CA formats a date as YYYY-MM-DD
    return new Intl.DateTimeFormat('en-CA', { timeZone: appTimezone(), year: 'numeric', month: '2-digit', day: '2-digit' }).format(now);
}

/**
 * Whether an issue counts as overdue, by the same rule as the Overdue KPI:
 * its due date has passed and it is neither resolved nor closed.
 * The day rolls over at midnight in the business timezone, as it does on the server.
 */
export function isIssueOverdue(dueDate: string | null | undefined, status: string): boolean {
    if (!dueDate || status === 'resolved' || status === 'closed') {
        return false;
    }

    return dueDate.slice(0, 10) < todayInAppTimezone();
}

/**
 * Colours for a label chip. Labels have a colour chosen by a person, which may be too dark to
 * read on a dark background, so in the dark theme the text is lightened towards white.
 */
export function labelStyle(color: string): { backgroundColor: string; color: string } {
    return {
        backgroundColor: `color-mix(in srgb, ${color} 14%, transparent)`,
        color: `light-dark(color-mix(in srgb, ${color} 80%, black), color-mix(in srgb, ${color} 55%, white))`,
    };
}
