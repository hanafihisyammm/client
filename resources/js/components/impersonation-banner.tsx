import { Button } from '@/components/ui/button';
import { router, usePage } from '@inertiajs/react';
import { LogOut, UserCheck } from 'lucide-react';
import { useState } from 'react';

interface ImpersonationProps {
    auth: { user: { name: string; email: string } | null };
    /** Present only while a Super Admin is signed in as this user */
    impersonation?: { superAdmin: string } | null;
}

/**
 * Shown on every page while a Super Admin is signed in as someone else,
 * with the way back to their own account.
 */
export function ImpersonationBanner() {
    const { auth, impersonation } = usePage().props as unknown as ImpersonationProps;
    const [returning, setReturning] = useState(false);

    if (!impersonation || !auth.user) {
        return null;
    }

    const stop = () => {
        setReturning(true);
        router.post('/impersonation/stop', {}, { onFinish: () => setReturning(false) });
    };

    return (
        <div
            role="status"
            className="sticky top-0 z-40 flex flex-wrap items-center justify-between gap-x-4 gap-y-2 border-b border-amber-300 bg-amber-100 px-6 py-2.5 text-amber-950"
        >
            <p className="flex min-w-0 items-center gap-2 text-sm">
                <UserCheck className="h-4 w-4 shrink-0" />
                <span className="min-w-0">
                    You are signed in as <strong className="font-semibold">{auth.user.name}</strong>
                    <span className="text-amber-800"> ({auth.user.email})</span>. You see and can do exactly what they can.
                </span>
            </p>
            <Button size="sm" onClick={stop} disabled={returning} className="shrink-0">
                <LogOut className="mr-2 h-4 w-4" />
                {returning ? 'Returning...' : 'Return to Super Admin'}
            </Button>
        </div>
    );
}
