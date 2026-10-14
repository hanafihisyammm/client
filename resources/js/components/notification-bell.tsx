import { PushAlertsToggle } from '@/components/push-alerts-toggle';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { csrfPost } from '@/lib/csrf';
import { showUnreadOnAppIcon } from '@/lib/push';
import { cn } from '@/lib/utils';
import { router } from '@inertiajs/react';
import { formatDistanceToNow } from 'date-fns';
import { Bell } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';

interface AppNotification {
    id: string;
    title: string;
    message: string;
    url: string | null;
    read_at: string | null;
    created_at: string;
}

const POLL_INTERVAL_MS = 30000;

export function NotificationBell() {
    const [notifications, setNotifications] = useState<AppNotification[]>([]);
    const [unreadCount, setUnreadCount] = useState(0);

    const fetchNotifications = useCallback(async () => {
        try {
            const response = await fetch('/api/notifications', {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            if (!response.ok) return;

            const data = await response.json();
            setNotifications(data.notifications);
            setUnreadCount(data.unread_count);
        } catch {
            // Keep showing the last known list; the next poll will retry
        }
    }, []);

    useEffect(() => {
        const refresh = () => {
            if (!document.hidden) fetchNotifications();
        };

        const initial = setTimeout(refresh, 0);
        const interval = setInterval(refresh, POLL_INTERVAL_MS);
        document.addEventListener('visibilitychange', refresh);

        return () => {
            clearTimeout(initial);
            clearInterval(interval);
            document.removeEventListener('visibilitychange', refresh);
        };
    }, [fetchNotifications]);

    // The same number on the installed app's icon
    useEffect(() => showUnreadOnAppIcon(unreadCount), [unreadCount]);

    const openNotification = async (notification: AppNotification) => {
        if (!notification.read_at) {
            setNotifications((current) =>
                current.map((item) =>
                    item.id === notification.id
                        ? { ...item, read_at: new Date().toISOString() }
                        : item,
                ),
            );
            setUnreadCount((count) => Math.max(0, count - 1));
            await csrfPost(`/api/notifications/${notification.id}/read`).catch(
                () => undefined,
            );
        }

        if (notification.url) {
            router.visit(notification.url);
        }
    };

    const markAllAsRead = async () => {
        setNotifications((current) =>
            current.map((item) =>
                item.read_at
                    ? item
                    : { ...item, read_at: new Date().toISOString() },
            ),
        );
        setUnreadCount(0);
        await csrfPost('/api/notifications/read-all').catch(() => undefined);
    };

    return (
        <DropdownMenu onOpenChange={(open) => open && fetchNotifications()}>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="ghost"
                    size="icon"
                    className="relative"
                    aria-label={
                        unreadCount > 0
                            ? `Notifications (${unreadCount} unread)`
                            : 'Notifications'
                    }
                >
                    <Bell className="size-5" />
                    {unreadCount > 0 && (
                        <span className="absolute -top-0.5 -right-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-red-500 px-1 text-[10px] font-medium text-white">
                            {unreadCount > 99 ? '99+' : unreadCount}
                        </span>
                    )}
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent
                align="end"
                className="w-80 max-w-[calc(100vw-2rem)] p-0"
            >
                <div className="flex items-center justify-between px-3 py-2">
                    <DropdownMenuLabel className="p-0">
                        Notifications
                    </DropdownMenuLabel>
                    {unreadCount > 0 && (
                        <button
                            type="button"
                            onClick={markAllAsRead}
                            className="text-xs text-muted-foreground hover:text-foreground"
                        >
                            Mark all as read
                        </button>
                    )}
                </div>
                <DropdownMenuSeparator className="m-0" />
                <div className="max-h-96 overflow-y-auto">
                    {notifications.length === 0 ? (
                        <p className="px-3 py-6 text-center text-sm text-muted-foreground">
                            No notifications yet
                        </p>
                    ) : (
                        notifications.map((notification) => (
                            <DropdownMenuItem
                                key={notification.id}
                                onSelect={() => openNotification(notification)}
                                className="flex cursor-pointer items-start gap-2 rounded-none px-3 py-2"
                            >
                                <span
                                    className={cn(
                                        'mt-1.5 size-2 shrink-0 rounded-full',
                                        notification.read_at
                                            ? 'bg-transparent'
                                            : 'bg-brand-gold',
                                    )}
                                />
                                <div className="min-w-0 flex-1">
                                    <p
                                        className={cn(
                                            'truncate text-sm',
                                            notification.read_at
                                                ? 'text-muted-foreground'
                                                : 'font-medium',
                                        )}
                                    >
                                        {notification.title}
                                    </p>
                                    <p className="line-clamp-2 text-xs text-muted-foreground">
                                        {notification.message}
                                    </p>
                                    <p className="mt-0.5 text-[11px] text-muted-foreground">
                                        {formatDistanceToNow(
                                            new Date(notification.created_at),
                                            { addSuffix: true },
                                        )}
                                    </p>
                                </div>
                            </DropdownMenuItem>
                        ))
                    )}
                </div>
                <PushAlertsToggle />
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
