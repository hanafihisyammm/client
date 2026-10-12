import { Button } from '@/components/ui/button';

export const DEFAULT_PAGE_SIZE = 10;

export interface Page<T> {
    rows: T[];
    current: number;
    lastPage: number;
    from: number;
    to: number;
    total: number;
}

/** Cuts one page out of a list that is already filtered and sorted in the browser. */
export function paginate<T>(rows: T[], page: number, pageSize: number = DEFAULT_PAGE_SIZE): Page<T> {
    const lastPage = Math.max(1, Math.ceil(rows.length / pageSize));
    // Filtering can leave the viewer past the end, so fall back to the last page that exists
    const current = Math.min(Math.max(1, page), lastPage);
    const start = (current - 1) * pageSize;

    return {
        rows: rows.slice(start, start + pageSize),
        current,
        lastPage,
        from: rows.length === 0 ? 0 : start + 1,
        to: Math.min(start + pageSize, rows.length),
        total: rows.length,
    };
}

export function TablePagination({ page, noun, onPageChange }: { page: Page<unknown>; noun: string; onPageChange: (page: number) => void }) {
    return (
        <div className="flex items-center justify-between border-t px-5 py-3">
            <p className="text-sm text-muted-foreground">
                {page.total === 0 ? `No ${noun}` : `Showing ${page.from}–${page.to} of ${page.total} ${noun}`}
            </p>
            <div className="flex items-center gap-2">
                <Button variant="outline" size="sm" disabled={page.current <= 1} onClick={() => onPageChange(page.current - 1)}>
                    Previous
                </Button>
                <span className="text-sm text-muted-foreground">
                    Page {page.current} of {page.lastPage}
                </span>
                <Button variant="outline" size="sm" disabled={page.current >= page.lastPage} onClick={() => onPageChange(page.current + 1)}>
                    Next
                </Button>
            </div>
        </div>
    );
}
