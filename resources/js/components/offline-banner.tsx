import { useOnline } from '@/hooks/use-pwa';
import { WifiOff } from 'lucide-react';

/**
 * Says so while there is no connection, so nobody thinks a change was saved when it could not be.
 */
export function OfflineBanner() {
    const online = useOnline();

    if (online) return null;

    return (
        <div
            role="status"
            className="sticky top-0 z-40 flex items-center gap-2 border-b border-border bg-muted px-4 py-2 text-sm text-foreground sm:px-6"
        >
            <WifiOff className="h-4 w-4 shrink-0" />
            <span>
                You're offline. Pages won't load and changes won't be saved
                until you're connected again.
            </span>
        </div>
    );
}
