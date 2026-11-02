import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Check, Copy, RefreshCw } from 'lucide-react';
import { useEffect, useState } from 'react';

// No look-alike characters (0/O, 1/l/I), because this gets read out or retyped by hand
const CHARACTERS = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';

export function generateTemporaryPassword(length = 14): string {
    const values = crypto.getRandomValues(new Uint32Array(length));

    return Array.from(values, (value) => CHARACTERS[value % CHARACTERS.length]).join('');
}

interface Props {
    id?: string;
    label: string;
    value: string;
    onChange: (value: string) => void;
}

/** A password an admin gives someone: shown in plain text so it can be copied and passed on. */
export function TemporaryPasswordInput({ id, label, value, onChange }: Props) {
    const [copied, setCopied] = useState(false);

    // Filled in once on the page rather than on the server, whose value the browser would not match
    useEffect(() => {
        if (!value) onChange(generateTemporaryPassword());
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const copy = async () => {
        await navigator.clipboard.writeText(value);
        setCopied(true);
        setTimeout(() => setCopied(false), 1500);
    };

    return (
        <div className="flex items-center gap-1">
            <Input
                id={id}
                type="text"
                aria-label={label}
                value={value}
                onChange={(event) => onChange(event.target.value)}
                autoComplete="off"
                spellCheck={false}
                className="min-w-0 flex-1 font-mono text-sm"
                required
            />
            <Button type="button" variant="ghost" size="sm" className="h-9 w-9 shrink-0 p-0" aria-label={`Copy ${label}`} title="Copy" onClick={copy}>
                {copied ? <Check className="h-4 w-4 text-green-600" /> : <Copy className="h-4 w-4" />}
            </Button>
            <Button
                type="button"
                variant="ghost"
                size="sm"
                className="h-9 w-9 shrink-0 p-0"
                aria-label={`Generate another ${label}`}
                title="Generate another"
                onClick={() => onChange(generateTemporaryPassword())}
            >
                <RefreshCw className="h-4 w-4" />
            </Button>
        </div>
    );
}
