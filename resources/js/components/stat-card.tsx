import { type LucideIcon } from 'lucide-react';

/**
 * A KPI card in the same style as the ones on the Issues page.
 */
export function StatCard({
    label,
    value,
    hint,
    icon: Icon,
    color = 'text-brand-gold-dark bg-brand-cream',
}: {
    label: string;
    value: number | string;
    hint?: string;
    icon: LucideIcon;
    color?: string;
}) {
    return (
        <div className="rounded-[14px] border bg-card p-5">
            <div className="flex items-center justify-between">
                <span className="eyebrow">{label}</span>
                <span className={`rounded-md p-1.5 ${color}`}>
                    <Icon className="h-4 w-4" />
                </span>
            </div>
            <p className="mt-3 font-[family-name:var(--font-heading)] text-4xl font-extrabold tracking-tight">
                {value}
            </p>
            {hint && (
                <p className="mt-1 text-xs text-muted-foreground">{hint}</p>
            )}
        </div>
    );
}
