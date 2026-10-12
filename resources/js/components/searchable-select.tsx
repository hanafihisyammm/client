import { Input } from '@/components/ui/input';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { cn } from '@/lib/utils';
import { Check, ChevronDown, Search } from 'lucide-react';
import { useMemo, useState } from 'react';

export interface SearchableOption {
    value: string;
    label: string;
    /** Small grey note after the label, e.g. "completed" */
    hint?: string;
    /** Shown in the list but cannot be chosen */
    disabled?: boolean;
}

/**
 * A dropdown with a search box at the top, for lists that can grow long.
 * Looks like the other dropdowns when closed.
 */
export function SearchableSelect({
    value,
    onChange,
    options,
    allLabel,
    placeholder = 'Select...',
    searchPlaceholder = 'Search...',
    emptyText = 'No matches',
    className,
    contentClassName,
}: {
    value: string;
    onChange: (value: string) => void;
    options: SearchableOption[];
    /** Label for the "no filter" choice, whose value is "all". Leave out when a real choice is required. */
    allLabel?: string;
    /** Shown on the closed dropdown when nothing is chosen and there is no "all" choice */
    placeholder?: string;
    searchPlaceholder?: string;
    emptyText?: string;
    className?: string;
    contentClassName?: string;
}) {
    const [open, setOpen] = useState(false);
    const [term, setTerm] = useState('');
    const [activeIndex, setActiveIndex] = useState(0);

    const choices = useMemo<SearchableOption[]>(() => {
        const query = term.trim().toLowerCase();
        const matches = query ? options.filter((option) => option.label.toLowerCase().includes(query)) : options;

        // "All" stays available unless the user is searching for something specific
        return query || !allLabel ? matches : [{ value: 'all', label: allLabel }, ...matches];
    }, [options, term, allLabel]);

    const selected = options.find((option) => option.value === value);

    const choose = (choice: SearchableOption) => {
        if (choice.disabled) return;
        onChange(choice.value);
        setOpen(false);
    };

    const onOpenChange = (next: boolean) => {
        setOpen(next);
        if (next) {
            setTerm('');
            setActiveIndex(0);
        }
    };

    const onKeyDown = (event: React.KeyboardEvent<HTMLInputElement>) => {
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            setActiveIndex((index) => Math.min(index + 1, choices.length - 1));
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            setActiveIndex((index) => Math.max(index - 1, 0));
        } else if (event.key === 'Enter' && choices[activeIndex]) {
            event.preventDefault();
            choose(choices[activeIndex]);
        }
    };

    return (
        <Popover open={open} onOpenChange={onOpenChange}>
            <PopoverTrigger asChild>
                <button
                    type="button"
                    role="combobox"
                    aria-expanded={open}
                    className={cn(
                        'flex h-9 shrink-0 items-center justify-between gap-2 rounded-md border border-input px-3 text-sm whitespace-nowrap shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50',
                        className,
                    )}
                >
                    <span className={cn('truncate', !selected && !allLabel && 'text-muted-foreground')}>
                        {selected?.label ?? allLabel ?? placeholder}
                    </span>
                    <ChevronDown className="h-4 w-4 shrink-0 opacity-50" />
                </button>
            </PopoverTrigger>
            <PopoverContent align="start" className={cn('w-64 p-0', contentClassName)}>
                <div className="relative border-b p-2">
                    <Search className="pointer-events-none absolute top-1/2 left-4.5 h-4 w-4 -translate-y-1/2 text-gray-400" />
                    <Input
                        autoFocus
                        value={term}
                        onChange={(event) => {
                            setTerm(event.target.value);
                            setActiveIndex(0);
                        }}
                        onKeyDown={onKeyDown}
                        placeholder={searchPlaceholder}
                        aria-label={searchPlaceholder}
                        className="h-9 pl-8"
                    />
                </div>
                <div role="listbox" className="max-h-64 overflow-y-auto p-1">
                    {choices.length === 0 ? (
                        <p className="px-3 py-4 text-center text-sm text-muted-foreground">{emptyText}</p>
                    ) : (
                        choices.map((choice, index) => (
                            <button
                                key={choice.value}
                                type="button"
                                role="option"
                                aria-selected={choice.value === value}
                                aria-disabled={choice.disabled}
                                onMouseEnter={() => setActiveIndex(index)}
                                onClick={() => choose(choice)}
                                className={cn(
                                    'flex w-full items-center gap-2 rounded-sm px-2 py-1.5 text-left text-sm',
                                    index === activeIndex && !choice.disabled && 'bg-accent text-accent-foreground',
                                    choice.disabled && 'cursor-not-allowed text-muted-foreground opacity-60',
                                )}
                            >
                                <span className="min-w-0 flex-1 truncate">
                                    {choice.label}
                                    {choice.hint && (
                                        <span className="ml-1.5 text-xs text-muted-foreground">({choice.hint})</span>
                                    )}
                                </span>
                                {choice.value === value && <Check className="h-4 w-4 shrink-0" />}
                            </button>
                        ))
                    )}
                </div>
            </PopoverContent>
        </Popover>
    );
}
