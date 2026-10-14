import { installApp } from '@/components/install-app';
import { Button } from '@/components/ui/button';
import { flashError, flashSuccess } from '@/lib/flash';
import {
    disablePush,
    enablePush,
    pushStatus,
    syncPush,
    type PushStatus,
} from '@/lib/push';
import { usePage } from '@inertiajs/react';
import { BellOff, BellRing } from 'lucide-react';
import { useEffect, useState } from 'react';

interface PushProps {
    webPush?: { publicKey: string } | null;
    impersonation?: unknown;
}

/**
 * Turning phone / desktop alerts on or off for this device, at the foot of the
 * notifications menu. Also keeps this device's alerts going to whoever is signed in.
 */
export function PushAlertsToggle() {
    const { webPush, impersonation } = usePage().props as unknown as PushProps;
    const [status, setStatus] = useState<PushStatus | null>(null);
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        if (!webPush) return;

        // Signed in as someone else: this browser's alerts stay with the Super Admin
        if (!impersonation) syncPush(webPush.publicKey);
        pushStatus().then(setStatus);
    }, [webPush, impersonation]);

    if (!webPush || impersonation || !status || status === 'unavailable') {
        return null;
    }

    const change = async (action: () => Promise<PushStatus>) => {
        setBusy(true);
        try {
            const next = await action();
            setStatus(next);
            if (next === 'on') flashSuccess('Alerts are on for this device.');
            if (next === 'off' && status === 'on')
                flashSuccess('Alerts are off for this device.');
            if (next === 'blocked')
                flashError(
                    'Alerts are blocked for this site in your browser settings.',
                );
        } catch {
            flashError(
                navigator.onLine
                    ? "Alerts couldn't be changed. Please try again."
                    : "You're offline, so alerts couldn't be changed.",
            );
        } finally {
            setBusy(false);
        }
    };

    const text = {
        'needs-install':
            'Add Sidra Desk to your Home Screen to get alerts on this iPhone or iPad.',
        blocked:
            'Alerts are blocked for this site. Allow notifications in your browser or system settings.',
        off: 'Get an alert on this device when something needs you.',
        on: 'Alerts are on for this device.',
    }[status];

    return (
        <div className="flex items-center gap-2 border-t border-border px-3 py-2">
            {status === 'on' ? (
                <BellRing className="size-4 shrink-0 text-brand-gold-dark dark:text-brand-gold" />
            ) : (
                <BellOff className="size-4 shrink-0 text-muted-foreground" />
            )}
            <p className="min-w-0 flex-1 text-xs text-muted-foreground">
                {text}
            </p>
            {status === 'off' && (
                <Button
                    size="sm"
                    disabled={busy}
                    onClick={() => change(() => enablePush(webPush.publicKey))}
                >
                    Turn on
                </Button>
            )}
            {status === 'on' && (
                <Button
                    size="sm"
                    variant="ghost"
                    disabled={busy}
                    onClick={() => change(disablePush)}
                >
                    Turn off
                </Button>
            )}
            {status === 'needs-install' && (
                <Button size="sm" variant="ghost" onClick={installApp}>
                    How
                </Button>
            )}
        </div>
    );
}
