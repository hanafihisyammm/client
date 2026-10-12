import { type ReactNode } from 'react';

/**
 * The standard page heading, matching the Issues page: a small gold label,
 * a large title, a one-line description, and actions on the right.
 */
export function PageHeader({
    title,
    description,
    eyebrow = 'Sidra Desk',
    children,
}: {
    title: ReactNode;
    description?: ReactNode;
    eyebrow?: string;
    children?: ReactNode;
}) {
    return (
        <div className="flex items-center justify-between gap-4">
            <div className="min-w-0">
                <p className="eyebrow mb-1">{eyebrow}</p>
                <h1 className="text-3xl font-extrabold tracking-tight">
                    {title}
                </h1>
                {description && (
                    <p className="mt-1 text-sm text-muted-foreground">
                        {description}
                    </p>
                )}
            </div>
            {children && (
                <div className="flex shrink-0 items-center gap-3">
                    {children}
                </div>
            )}
        </div>
    );
}
