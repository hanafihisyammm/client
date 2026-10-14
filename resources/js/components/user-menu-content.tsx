import {
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
} from '@/components/ui/dropdown-menu';
import { installApp } from '@/components/install-app';
import { UserInfo } from '@/components/user-info';
import { useMobileNavigation } from '@/hooks/use-mobile-navigation';
import { useInstallState } from '@/hooks/use-pwa';
import { logoutUser } from '@/lib/csrf';
import { flashError } from '@/lib/flash';
import { edit } from '@/routes/profile';
import { type User } from '@/types';
import { Link, router } from '@inertiajs/react';
import { Download, LogOut, Settings } from 'lucide-react';

interface UserMenuContentProps {
    user: User;
}

export function UserMenuContent({ user }: UserMenuContentProps) {
    const cleanup = useMobileNavigation();
    const { canPrompt, needsManualSteps } = useInstallState();

    const handleLogout = (e: React.MouseEvent) => {
        console.log('Logout clicked!', e); // Debug log
        e.preventDefault();
        e.stopPropagation();

        // Logging out has to reach the server; leaving the page offline would only look like it worked
        if (!navigator.onLine) {
            flashError("You're offline, so you can't log out yet. Try again when you're back online.");
            return;
        }

        // Immediate cleanup
        cleanup();
        router.flushAll();
        
        // Force immediate logout without waiting
        logoutUser('/').catch(() => {
            // If logout fails, just redirect
            window.location.href = '/';
        });
        
        // Also add a fallback timeout
        setTimeout(() => {
            if (window.location.pathname !== '/') {
                window.location.href = '/';
            }
        }, 2000);
    };

    return (
        <>
            <DropdownMenuLabel className="p-0 font-normal">
                <div className="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
                    <UserInfo user={user} showEmail={true} />
                </div>
            </DropdownMenuLabel>
            <DropdownMenuSeparator />
            <DropdownMenuGroup>
                <DropdownMenuItem asChild>
                    <Link
                        className="block w-full"
                        href={edit()}
                        as="button"
                        prefetch
                        onClick={cleanup}
                    >
                        <Settings className="mr-2" />
                        Settings
                    </Link>
                </DropdownMenuItem>
                {(canPrompt || needsManualSteps) && (
                    <DropdownMenuItem
                        onSelect={() => {
                            cleanup();
                            installApp();
                        }}
                    >
                        <Download className="mr-2" />
                        Install app
                    </DropdownMenuItem>
                )}
            </DropdownMenuGroup>
            <DropdownMenuSeparator />
            <div 
                role="menuitem"
                className="relative flex cursor-pointer select-none items-center rounded-sm px-2 py-1.5 text-sm outline-none transition-colors hover:bg-accent hover:text-accent-foreground data-[disabled]:pointer-events-none data-[disabled]:opacity-50"
                onClick={handleLogout}
                data-test="logout-button"
            >
                <LogOut className="mr-2 h-4 w-4" />
                Log out
            </div>
        </>
    );
}
