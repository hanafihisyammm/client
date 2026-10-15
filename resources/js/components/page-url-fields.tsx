import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Plus, X } from 'lucide-react';

/** How many pages one issue can name (the server allows the same) */
export const MAX_PAGE_URLS = 10;

/** A full web address (http or https), as copied from the browser's address bar */
export const isPageUrl = (value: string) => {
    try {
        const url = new URL(value.trim());
        return (url.protocol === 'http:' || url.protocol === 'https:') && url.hostname !== '';
    } catch {
        return false;
    }
};

/**
 * Check the page URLs before sending them. Blank rows are dropped, so `urls` is what to send
 * and the error keys ("page_urls.1") match its positions, as the server's errors do.
 */
export function checkPageUrls(rows: string[], required: boolean): { urls: string[]; errors: Record<string, string[]> } {
    const urls = rows.map((row) => row.trim()).filter(Boolean);
    const errors: Record<string, string[]> = {};

    if (required && urls.length === 0) {
        errors.page_urls = ['Add the URL of the page where the issue happens.'];
    }
    urls.forEach((url, index) => {
        if (!isPageUrl(url)) {
            errors[`page_urls.${index}`] = ['Enter the full page address, starting with http:// or https://'];
        } else if (urls.indexOf(url) !== index) {
            errors[`page_urls.${index}`] = ['This page URL is listed twice.'];
        }
    });

    return { urls, errors };
}

/**
 * One input per page the issue happens on, with buttons to add and remove rows.
 * Always shows at least one row.
 */
export function PageUrlFields({
    urls,
    onChange,
    errors = {},
    idPrefix = 'page_url',
    required = false,
}: {
    urls: string[];
    onChange: (urls: string[]) => void;
    /** Validation errors keyed "page_urls" (the list) or "page_urls.N" (one row) */
    errors?: Record<string, string[] | undefined>;
    idPrefix?: string;
    required?: boolean;
}) {
    const rows = urls.length > 0 ? urls : [''];
    const setRow = (index: number, value: string) => onChange(rows.map((row, i) => (i === index ? value : row)));
    const removeRow = (index: number) => onChange(rows.filter((_, i) => i !== index));

    return (
        <div className="space-y-2">
            {rows.map((url, index) => {
                const error = errors[`page_urls.${index}`]?.[0];
                return (
                    <div key={index} className="space-y-1">
                        <div className="flex items-center gap-2">
                            <Input
                                id={index === 0 ? idPrefix : `${idPrefix}_${index}`}
                                type="url"
                                inputMode="url"
                                value={url}
                                onChange={(e) => setRow(index, e.target.value)}
                                placeholder="https://example.com/checkout"
                                className="h-11 min-w-0 flex-1"
                                aria-label={index === 0 ? undefined : `Page URL ${index + 1}`}
                                aria-invalid={error ? true : undefined}
                                required={required && index === 0}
                            />
                            {rows.length > 1 && (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    aria-label={`Remove page URL ${index + 1}`}
                                    onClick={() => removeRow(index)}
                                    className="h-11 px-2 text-gray-400 hover:text-red-600"
                                >
                                    <X className="h-4 w-4" />
                                </Button>
                            )}
                        </div>
                        {error && <p className="text-sm text-red-600">{error}</p>}
                    </div>
                );
            })}
            {rows.length < MAX_PAGE_URLS && (
                <Button type="button" variant="outline" size="sm" onClick={() => onChange([...rows, ''])}>
                    <Plus className="mr-1 h-4 w-4" />
                    Add another page
                </Button>
            )}
            {errors.page_urls?.[0] && <p className="text-sm text-red-600">{errors.page_urls[0]}</p>}
        </div>
    );
}
