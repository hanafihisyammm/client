import { Bug, Sparkles } from 'lucide-react';

export type IssueType = 'bug' | 'feature';

const typeConfig = {
    bug: { label: 'Bug', icon: Bug, className: 'border-red-200 bg-red-50 text-red-700' },
    feature: { label: 'Feature', icon: Sparkles, className: 'border-purple-200 bg-purple-50 text-purple-700' },
};

/** Says whether an issue is a bug or a feature request. Issues with no type recorded are bugs. */
export function IssueTypeBadge({ type, className = '' }: { type?: IssueType | null; className?: string }) {
    const config = typeConfig[type ?? 'bug'] ?? typeConfig.bug;
    const Icon = config.icon;

    return (
        <span
            data-issue-type={type ?? 'bug'}
            className={`inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-[11px] font-medium whitespace-nowrap ${config.className} ${className}`}
        >
            <Icon className="h-3 w-3" />
            {config.label}
        </span>
    );
}
