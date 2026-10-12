import {
    dismissFlash,
    flash,
    subscribeToFlash,
    type FlashMessage,
} from '@/lib/flash';
import { usePage } from '@inertiajs/react';
import { CheckCircle2, X, XCircle } from 'lucide-react';
import { useEffect, useState } from 'react';

const AUTO_DISMISS_MS = 5000;

/**
 * Shows the result of the last action: messages set by the server on a redirect
 * (the shared "flash" page prop) and messages raised in the page after a
 * background request succeeds. Uses the app's existing green/red banner style.
 */
export function FlashMessages() {
    const serverFlash = usePage<{
        flash?: { id: string; message: string; type?: string } | null;
    }>().props.flash;
    const [messages, setMessages] = useState<FlashMessage[]>([]);

    useEffect(() => subscribeToFlash(setMessages), []);

    useEffect(() => {
        if (serverFlash?.message) {
            flash(
                serverFlash.type === 'error' ? 'error' : 'success',
                serverFlash.message,
                serverFlash.id,
            );
        }
    }, [serverFlash]);

    // Success messages clear themselves; errors stay until dismissed
    useEffect(() => {
        const timers = messages
            .filter((item) => item.type === 'success')
            .map((item) =>
                setTimeout(() => dismissFlash(item.id), AUTO_DISMISS_MS),
            );

        return () => timers.forEach(clearTimeout);
    }, [messages]);

    if (messages.length === 0) return null;

    return (
        <div className="pointer-events-none fixed top-4 right-4 z-[100] flex w-full max-w-sm flex-col gap-2 px-4 sm:px-0">
            {messages.map((item) => {
                const isSuccess = item.type === 'success';
                const Icon = isSuccess ? CheckCircle2 : XCircle;

                return (
                    <div
                        key={item.id}
                        role={isSuccess ? 'status' : 'alert'}
                        className={`pointer-events-auto flex items-start gap-3 rounded-lg border p-4 text-sm shadow-lg ${
                            isSuccess
                                ? 'border-green-200 bg-green-50 text-green-800'
                                : 'border-red-200 bg-red-50 text-red-800'
                        }`}
                    >
                        <Icon className="mt-0.5 h-4 w-4 shrink-0" />
                        <p className="min-w-0 flex-1 break-words">
                            {item.message}
                        </p>
                        <button
                            type="button"
                            aria-label="Dismiss message"
                            onClick={() => dismissFlash(item.id)}
                            className="shrink-0 rounded p-0.5 opacity-70 hover:opacity-100"
                        >
                            <X className="h-4 w-4" />
                        </button>
                    </div>
                );
            })}
        </div>
    );
}
