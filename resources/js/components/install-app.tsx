import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useInstallState } from '@/hooks/use-pwa';
import { getInstallState, promptInstall } from '@/lib/pwa';
import { Download, Share, SquarePlus, X } from 'lucide-react';
import { useEffect, useState } from 'react';

const DISMISSED_KEY = 'install-app-dismissed';
const SHOW_STEPS_EVENT = 'show-install-steps';

const wasDismissed = () => {
    try {
        return localStorage.getItem(DISMISSED_KEY) === '1';
    } catch {
        return false;
    }
};

/**
 * Install the app: the browser's own install window where there is one, otherwise
 * (iPhone and iPad) the steps for adding it from Safari's Share menu.
 */
export function installApp() {
    if (getInstallState().canPrompt) {
        promptInstall();
    } else {
        window.dispatchEvent(new Event(SHOW_STEPS_EVENT));
    }
}

/**
 * A slim bar offering to install the app, until it is installed or the user says "not now".
 * The same option stays in the user menu.
 */
export function InstallAppBanner() {
    const { canPrompt, needsManualSteps } = useInstallState();
    const [dismissed, setDismissed] = useState(wasDismissed);

    const dismiss = () => {
        setDismissed(true);
        try {
            localStorage.setItem(DISMISSED_KEY, '1');
        } catch {
            // Hidden for this visit only
        }
    };

    return (
        <>
            {!dismissed && (canPrompt || needsManualSteps) && (
                <div
                    role="region"
                    aria-label="Install the app"
                    className="flex items-center gap-3 border-b border-border bg-accent px-4 py-2 text-accent-foreground sm:px-6"
                >
                    <Download className="h-4 w-4 shrink-0" />
                    <p className="min-w-0 flex-1 text-sm">
                        Install Sidra Desk on this device to open it like an
                        app.
                    </p>
                    <Button size="sm" onClick={installApp} className="shrink-0">
                        Install
                    </Button>
                    <Button
                        size="icon-sm"
                        variant="ghost"
                        onClick={dismiss}
                        aria-label="Not now"
                        className="shrink-0"
                    >
                        <X className="h-4 w-4" />
                    </Button>
                </div>
            )}
            <InstallSteps />
        </>
    );
}

function InstallSteps() {
    const [open, setOpen] = useState(false);

    useEffect(() => {
        const show = () => setOpen(true);

        window.addEventListener(SHOW_STEPS_EVENT, show);

        return () => window.removeEventListener(SHOW_STEPS_EVENT, show);
    }, []);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogContent className="max-w-sm">
                <DialogHeader>
                    <DialogTitle>Install Sidra Desk</DialogTitle>
                    <DialogDescription>
                        Add it to your Home Screen to open it like an app.
                    </DialogDescription>
                </DialogHeader>
                <ol className="space-y-3 text-sm">
                    <li className="flex items-center gap-3">
                        <Share className="h-5 w-5 shrink-0 text-brand-gold-dark dark:text-brand-gold" />
                        <span>
                            In Safari, tap the <strong>Share</strong> button.
                        </span>
                    </li>
                    <li className="flex items-center gap-3">
                        <SquarePlus className="h-5 w-5 shrink-0 text-brand-gold-dark dark:text-brand-gold" />
                        <span>
                            Choose <strong>Add to Home Screen</strong>, then tap{' '}
                            <strong>Add</strong>.
                        </span>
                    </li>
                </ol>
            </DialogContent>
        </Dialog>
    );
}
