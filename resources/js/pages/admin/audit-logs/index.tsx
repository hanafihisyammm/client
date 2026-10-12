import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import { Search, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

interface AuditLog {
    id: number;
    user_id: number | null;
    user_name: string | null;
    action: string;
    subject_type: string | null;
    subject_label: string | null;
    description: string;
    changes: Record<string, { old: unknown; new: unknown }> | null;
    ip_address: string | null;
    created_at: string;
}

interface Filters {
    search: string;
    type: string;
    user: string;
    from: string;
    to: string;
}

interface Props {
    logs: {
        data: AuditLog[];
        current_page: number;
        last_page: number;
        from: number | null;
        to: number | null;
        total: number;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    users: { id: number; name: string }[];
    filters: Filters;
}

const TYPE_LABELS: Record<string, string> = {
    auth: 'Sign-in',
    user: 'User',
    project: 'Project',
    report: 'Report',
    comment: 'Comment',
};

const getTypeColor = (type: string) => {
    switch (type) {
        case 'auth':
            return 'bg-gray-100 text-gray-800';
        case 'user':
            return 'bg-red-100 text-red-800';
        case 'project':
            return 'bg-blue-100 text-blue-800';
        case 'report':
            return 'bg-yellow-100 text-yellow-800';
        case 'comment':
            return 'bg-green-100 text-green-800';
        default:
            return 'bg-gray-100 text-gray-800';
    }
};

const formatField = (field: string) => field.replace(/_/g, ' ');

const formatValue = (value: unknown) => {
    if (value === null || value === undefined || value === '') return 'none';
    return String(value);
};

export default function Index({ logs, users, filters }: Props) {
    const [search, setSearch] = useState(filters.search);
    const isFirstRender = useRef(true);

    const applyFilters = (changed: Partial<Filters>) => {
        const next = { ...filters, search, ...changed };
        const params = Object.fromEntries(
            Object.entries(next).filter(
                ([, value]) => value !== '' && value !== 'all',
            ),
        );

        router.get('/audit-logs', params, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    // Search as the user types, after a short pause
    useEffect(() => {
        if (isFirstRender.current) {
            isFirstRender.current = false;
            return;
        }

        const timeout = setTimeout(() => applyFilters({ search }), 300);
        return () => clearTimeout(timeout);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    const hasFilters = Object.entries(filters).some(
        ([, value]) => value !== '' && value !== 'all',
    );

    const clearFilters = () => {
        isFirstRender.current = true;
        setSearch('');
        router.get('/audit-logs', {}, { preserveState: true, replace: true });
    };

    return (
        <AppLayout>
            <Head title="Audit Log" />

            <div className="space-y-6 px-4 py-2">
                <div>
                    <h1 className="text-3xl font-bold">Audit Log</h1>
                    <p className="mt-1 text-muted-foreground">
                        A record of who did what in the system, and when
                    </p>
                </div>

                <div className="flex flex-wrap items-center gap-4">
                    <div className="flex items-center space-x-2">
                        <Search className="h-4 w-4 text-muted-foreground" />
                        <Input
                            placeholder="Search activity..."
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            className="w-64"
                        />
                    </div>

                    <Select
                        value={filters.type}
                        onValueChange={(type) => applyFilters({ type })}
                    >
                        <SelectTrigger className="w-40">
                            <SelectValue placeholder="Filter by type" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Types</SelectItem>
                            {Object.entries(TYPE_LABELS).map(
                                ([value, label]) => (
                                    <SelectItem key={value} value={value}>
                                        {label}
                                    </SelectItem>
                                ),
                            )}
                        </SelectContent>
                    </Select>

                    <Select
                        value={filters.user}
                        onValueChange={(user) => applyFilters({ user })}
                    >
                        <SelectTrigger className="w-48">
                            <SelectValue placeholder="Filter by user" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Users</SelectItem>
                            {users.map((user) => (
                                <SelectItem
                                    key={user.id}
                                    value={String(user.id)}
                                >
                                    {user.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

                    <div className="flex items-center gap-2">
                        <Input
                            type="date"
                            aria-label="From date"
                            value={filters.from}
                            max={filters.to || undefined}
                            onChange={(e) =>
                                applyFilters({ from: e.target.value })
                            }
                            className="w-40"
                        />
                        <span className="text-sm text-muted-foreground">
                            to
                        </span>
                        <Input
                            type="date"
                            aria-label="To date"
                            value={filters.to}
                            min={filters.from || undefined}
                            onChange={(e) =>
                                applyFilters({ to: e.target.value })
                            }
                            className="w-40"
                        />
                    </div>

                    {hasFilters && (
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={clearFilters}
                        >
                            <X className="mr-1 h-4 w-4" />
                            Clear
                        </Button>
                    )}
                </div>

                <div className="rounded-lg border bg-white shadow-sm">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead className="w-44">When</TableHead>
                                <TableHead className="w-48">User</TableHead>
                                <TableHead className="w-28">Type</TableHead>
                                <TableHead>Activity</TableHead>
                                <TableHead className="w-36">
                                    IP Address
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {logs.data.length === 0 ? (
                                <TableRow>
                                    <TableCell
                                        colSpan={5}
                                        className="py-10 text-center text-gray-500"
                                    >
                                        {hasFilters
                                            ? 'No activity matches these filters'
                                            : 'No activity recorded yet'}
                                    </TableCell>
                                </TableRow>
                            ) : (
                                logs.data.map((log) => {
                                    const type = log.action.split('.')[0];

                                    return (
                                        <TableRow
                                            key={log.id}
                                            className="align-top hover:bg-gray-50"
                                        >
                                            <TableCell className="text-sm whitespace-nowrap text-gray-600">
                                                {new Date(
                                                    log.created_at,
                                                ).toLocaleString()}
                                            </TableCell>
                                            <TableCell className="font-medium">
                                                {log.user_name ?? (
                                                    <span className="text-gray-400">
                                                        System
                                                    </span>
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                <Badge
                                                    className={getTypeColor(
                                                        type,
                                                    )}
                                                >
                                                    {TYPE_LABELS[type] ?? type}
                                                </Badge>
                                            </TableCell>
                                            <TableCell className="whitespace-normal">
                                                <p className="break-words">
                                                    {log.description}
                                                </p>
                                                {log.changes && (
                                                    <ul className="mt-1 space-y-0.5 text-xs text-gray-500">
                                                        {Object.entries(
                                                            log.changes,
                                                        ).map(
                                                            ([
                                                                field,
                                                                change,
                                                            ]) => (
                                                                <li
                                                                    key={field}
                                                                    className="break-words"
                                                                >
                                                                    <span className="font-medium capitalize">
                                                                        {formatField(
                                                                            field,
                                                                        )}
                                                                        :
                                                                    </span>{' '}
                                                                    {formatValue(
                                                                        change.old,
                                                                    )}{' '}
                                                                    →{' '}
                                                                    {formatValue(
                                                                        change.new,
                                                                    )}
                                                                </li>
                                                            ),
                                                        )}
                                                    </ul>
                                                )}
                                            </TableCell>
                                            <TableCell className="text-sm text-gray-600">
                                                {log.ip_address ?? '—'}
                                            </TableCell>
                                        </TableRow>
                                    );
                                })
                            )}
                        </TableBody>
                    </Table>
                </div>

                <div className="flex items-center justify-between">
                    <p className="text-sm text-muted-foreground">
                        {logs.total === 0
                            ? 'No entries'
                            : `Showing ${logs.from}–${logs.to} of ${logs.total} entries`}
                    </p>
                    <div className="flex items-center gap-2">
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={!logs.prev_page_url}
                            onClick={() =>
                                logs.prev_page_url &&
                                router.get(
                                    logs.prev_page_url,
                                    {},
                                    { preserveState: true },
                                )
                            }
                        >
                            Previous
                        </Button>
                        <span className="text-sm text-muted-foreground">
                            Page {logs.current_page} of {logs.last_page}
                        </span>
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={!logs.next_page_url}
                            onClick={() =>
                                logs.next_page_url &&
                                router.get(
                                    logs.next_page_url,
                                    {},
                                    { preserveState: true },
                                )
                            }
                        >
                            Next
                        </Button>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
