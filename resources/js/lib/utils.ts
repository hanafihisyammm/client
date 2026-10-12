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
    return `h-9 whitespace-nowrap text-sm ${active ? 'border-blue-500 bg-blue-50 text-blue-700' : 'bg-white'}`;
}
