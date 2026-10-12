import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, useForm } from '@inertiajs/react';
import { PlusIcon, Edit, TrashIcon, Search, Eye, ChevronUp, ChevronDown, ChevronsUpDown, MoreHorizontal, Users, Shield, UserCog, Code, User, Mail, Calendar } from 'lucide-react';
import { useState, useMemo } from 'react';

interface User {
    id: number;
    uuid: string;
    name: string;
    email: string;
    role: string;
    created_at: string;
    email_verified_at: string | null;
}

interface Props {
    users: User[];
}

type SortField = 'name' | 'email' | 'role' | 'created_at'
type SortOrder = 'asc' | 'desc'

// Avatar component with initials
function UserAvatar({ name, role }: { name: string; role: string }) {
    const initials = name
        .split(' ')
        .map(n => n[0])
        .join('')
        .toUpperCase()
        .slice(0, 2);

    const bgColor = {
        super_admin: 'bg-gradient-to-br from-red-500 to-rose-600',
        admin: 'bg-gradient-to-br from-blue-500 to-indigo-600',
        manager: 'bg-gradient-to-br from-purple-500 to-violet-600',
        developer: 'bg-gradient-to-br from-emerald-500 to-teal-600',
        client: 'bg-gradient-to-br from-amber-500 to-orange-600',
    }[role] || 'bg-gradient-to-br from-gray-400 to-gray-500';

    return (
        <div className={`w-10 h-10 rounded-full ${bgColor} flex items-center justify-center text-white font-semibold text-sm shadow-sm`}>
            {initials}
        </div>
    );
}

// Role badge with icon
function RoleBadge({ role }: { role: string }) {
    const config = {
        super_admin: {
            label: 'Super Admin',
            className: 'bg-red-50 text-red-700 border-red-200 ring-red-600/10',
            icon: Shield
        },
        admin: {
            label: 'Admin',
            className: 'bg-blue-50 text-blue-700 border-blue-200 ring-blue-600/10',
            icon: UserCog
        },
        manager: {
            label: 'Manager',
            className: 'bg-purple-50 text-purple-700 border-purple-200 ring-purple-600/10',
            icon: Users
        },
        developer: {
            label: 'Developer',
            className: 'bg-emerald-50 text-emerald-700 border-emerald-200 ring-emerald-600/10',
            icon: Code
        },
        client: {
            label: 'Client',
            className: 'bg-amber-50 text-amber-700 border-amber-200 ring-amber-600/10',
            icon: User
        },
    }[role] || {
        label: role,
        className: 'bg-gray-50 text-gray-700 border-gray-200',
        icon: User
    };

    const Icon = config.icon;

    return (
        <span className={`inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium border ${config.className}`}>
            <Icon className="w-3 h-3" />
            {config.label}
        </span>
    );
}

// Status badge
function StatusBadge({ isActive }: { isActive: boolean }) {
    return isActive ? (
        <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium bg-green-50 text-green-700 border border-green-200">
            <span className="w-1.5 h-1.5 rounded-full bg-green-500 animate-pulse" />
            Active
        </span>
    ) : (
        <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium bg-gray-50 text-gray-600 border border-gray-200">
            <span className="w-1.5 h-1.5 rounded-full bg-gray-400" />
            Pending
        </span>
    );
}

// Stats card
function StatsCard({ icon: Icon, label, value, color }: { icon: any; label: string; value: number; color: string }) {
    return (
        <div className={`flex items-center gap-3 px-4 py-3 rounded-xl border ${color}`}>
            <div className="p-2 rounded-lg bg-white shadow-sm">
                <Icon className="w-4 h-4" />
            </div>
            <div>
                <p className="text-2xl font-bold">{value}</p>
                <p className="text-xs text-muted-foreground">{label}</p>
            </div>
        </div>
    );
}

export default function Index({ users }: Props) {
    const [deleteUser, setDeleteUser] = useState<User | null>(null);
    const [searchQuery, setSearchQuery] = useState('');
    const [roleFilter, setRoleFilter] = useState('all');
    const [statusFilter, setStatusFilter] = useState('all');
    const [sortField, setSortField] = useState<SortField>('created_at');
    const [sortOrder, setSortOrder] = useState<SortOrder>('desc');

    const { delete: deleteForm, processing } = useForm();

    const handleDelete = () => {
        if (!deleteUser) return;

        deleteForm(`/users/${deleteUser.uuid}`, {
            onSuccess: () => {
                setDeleteUser(null);
            }
        });
    };

    const filteredAndSortedUsers = useMemo(() => {
        let filtered = users.filter(user => {
            const matchesSearch = user.name.toLowerCase().includes(searchQuery.toLowerCase()) ||
                                user.email.toLowerCase().includes(searchQuery.toLowerCase());
            const matchesRole = roleFilter === 'all' || user.role === roleFilter;
            const matchesStatus = statusFilter === 'all' ||
                                (statusFilter === 'active' && user.email_verified_at) ||
                                (statusFilter === 'pending' && !user.email_verified_at);

            return matchesSearch && matchesRole && matchesStatus;
        });

        return filtered.sort((a, b) => {
            let aValue: any, bValue: any;

            switch (sortField) {
                case 'name':
                    aValue = a.name.toLowerCase();
                    bValue = b.name.toLowerCase();
                    break;
                case 'email':
                    aValue = a.email.toLowerCase();
                    bValue = b.email.toLowerCase();
                    break;
                case 'role':
                    aValue = a.role;
                    bValue = b.role;
                    break;
                case 'created_at':
                    aValue = new Date(a.created_at).getTime();
                    bValue = new Date(b.created_at).getTime();
                    break;
                default:
                    return 0;
            }

            if (aValue < bValue) return sortOrder === 'asc' ? -1 : 1;
            if (aValue > bValue) return sortOrder === 'asc' ? 1 : -1;
            return 0;
        });
    }, [users, searchQuery, roleFilter, statusFilter, sortField, sortOrder]);

    // Stats
    const stats = useMemo(() => ({
        total: users.length,
        admins: users.filter(u => u.role === 'admin' || u.role === 'super_admin').length,
        active: users.filter(u => u.email_verified_at).length,
    }), [users]);

    const handleSort = (field: SortField) => {
        const newOrder = sortField === field && sortOrder === 'asc' ? 'desc' : 'asc';
        setSortField(field);
        setSortOrder(newOrder);
    };

    const getSortIcon = (field: SortField) => {
        if (sortField !== field) {
            return <ChevronsUpDown className="ml-1 h-3.5 w-3.5 opacity-50" />;
        }
        return sortOrder === 'asc' ?
            <ChevronUp className="ml-1 h-3.5 w-3.5" /> :
            <ChevronDown className="ml-1 h-3.5 w-3.5" />;
    };

    const formatDate = (date: string) => {
        const d = new Date(date);
        const now = new Date();
        const diffDays = Math.floor((now.getTime() - d.getTime()) / (1000 * 60 * 60 * 24));

        if (diffDays === 0) return 'Today';
        if (diffDays === 1) return 'Yesterday';
        if (diffDays < 7) return `${diffDays} days ago`;

        return d.toLocaleDateString('en-GB', {
            day: 'numeric',
            month: 'short',
            year: 'numeric'
        });
    };

    return (
        <AppLayout>
            <Head title="Users" />

            <div className="space-y-6">
                {/* Header */}
                <div className="flex items-start justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">User Management</h1>
                        <p className="text-muted-foreground text-sm mt-1">
                            Manage system users and their access levels
                        </p>
                    </div>
                    <Link href="/users/create">
                        <Button className="shadow-sm">
                            <PlusIcon className="h-4 w-4 mr-2" />
                            Add User
                        </Button>
                    </Link>
                </div>

                {/* Stats Cards */}
                <div className="grid grid-cols-3 gap-4">
                    <StatsCard
                        icon={Users}
                        label="Total Users"
                        value={stats.total}
                        color="bg-slate-50 border-slate-200"
                    />
                    <StatsCard
                        icon={Shield}
                        label="Administrators"
                        value={stats.admins}
                        color="bg-blue-50 border-blue-200"
                    />
                    <StatsCard
                        icon={User}
                        label="Active Users"
                        value={stats.active}
                        color="bg-green-50 border-green-200"
                    />
                </div>

                {/* Filters */}
                <div className="flex items-center gap-3">
                    <div className="relative flex-1 max-w-sm">
                        <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-muted-foreground" />
                        <Input
                            placeholder="Search by name or email..."
                            value={searchQuery}
                            onChange={(e) => setSearchQuery(e.target.value)}
                            className="pl-9"
                        />
                    </div>

                    <Select value={roleFilter} onValueChange={setRoleFilter}>
                        <SelectTrigger className="w-40">
                            <SelectValue placeholder="All Roles" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Roles</SelectItem>
                            <SelectItem value="super_admin">Super Admin</SelectItem>
                            <SelectItem value="admin">Admin</SelectItem>
                            <SelectItem value="manager">Manager</SelectItem>
                            <SelectItem value="developer">Developer</SelectItem>
                            <SelectItem value="client">Client</SelectItem>
                        </SelectContent>
                    </Select>

                    <Select value={statusFilter} onValueChange={setStatusFilter}>
                        <SelectTrigger className="w-36">
                            <SelectValue placeholder="All Status" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Status</SelectItem>
                            <SelectItem value="active">Active</SelectItem>
                            <SelectItem value="pending">Pending</SelectItem>
                        </SelectContent>
                    </Select>

                    {(searchQuery || roleFilter !== 'all' || statusFilter !== 'all') && (
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => {
                                setSearchQuery('');
                                setRoleFilter('all');
                                setStatusFilter('all');
                            }}
                            className="text-muted-foreground"
                        >
                            Clear filters
                        </Button>
                    )}
                </div>

                {/* Results count */}
                {filteredAndSortedUsers.length !== users.length && (
                    <p className="text-sm text-muted-foreground">
                        Showing {filteredAndSortedUsers.length} of {users.length} users
                    </p>
                )}

                {/* Table */}
                <div className="bg-white shadow-sm border rounded-xl overflow-hidden">
                    <Table>
                        <TableHeader>
                            <TableRow className="bg-slate-50/50">
                                <TableHead className="w-[300px]">
                                    <Button
                                        variant="ghost"
                                        className="h-auto p-0 font-medium text-xs uppercase tracking-wider hover:bg-transparent text-muted-foreground"
                                        onClick={() => handleSort('name')}
                                    >
                                        User
                                        {getSortIcon('name')}
                                    </Button>
                                </TableHead>
                                <TableHead>
                                    <Button
                                        variant="ghost"
                                        className="h-auto p-0 font-medium text-xs uppercase tracking-wider hover:bg-transparent text-muted-foreground"
                                        onClick={() => handleSort('role')}
                                    >
                                        Role
                                        {getSortIcon('role')}
                                    </Button>
                                </TableHead>
                                <TableHead className="font-medium text-xs uppercase tracking-wider text-muted-foreground">
                                    Status
                                </TableHead>
                                <TableHead>
                                    <Button
                                        variant="ghost"
                                        className="h-auto p-0 font-medium text-xs uppercase tracking-wider hover:bg-transparent text-muted-foreground"
                                        onClick={() => handleSort('created_at')}
                                    >
                                        Joined
                                        {getSortIcon('created_at')}
                                    </Button>
                                </TableHead>
                                <TableHead className="w-[50px]"></TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {filteredAndSortedUsers.length === 0 ? (
                                <TableRow>
                                    <TableCell colSpan={5} className="h-32 text-center">
                                        <div className="flex flex-col items-center gap-2 text-muted-foreground">
                                            <Users className="h-8 w-8 opacity-50" />
                                            <p>No users found</p>
                                            {(searchQuery || roleFilter !== 'all' || statusFilter !== 'all') && (
                                                <Button
                                                    variant="link"
                                                    size="sm"
                                                    onClick={() => {
                                                        setSearchQuery('');
                                                        setRoleFilter('all');
                                                        setStatusFilter('all');
                                                    }}
                                                >
                                                    Clear filters
                                                </Button>
                                            )}
                                        </div>
                                    </TableCell>
                                </TableRow>
                            ) : (
                                filteredAndSortedUsers.map((user) => (
                                    <TableRow
                                    key={user.id}
                                    className="group hover:bg-slate-50/50 transition-colors cursor-pointer"
                                    onClick={() => window.location.href = `/users/${user.uuid}`}
                                >
                                        <TableCell>
                                            <div className="flex items-center gap-3">
                                                <UserAvatar name={user.name} role={user.role} />
                                                <div className="min-w-0">
                                                    <p className="font-medium text-gray-900 truncate">
                                                        {user.name}
                                                    </p>
                                                    <p className="text-sm text-muted-foreground truncate">
                                                        {user.email}
                                                    </p>
                                                </div>
                                            </div>
                                        </TableCell>
                                        <TableCell>
                                            <RoleBadge role={user.role} />
                                        </TableCell>
                                        <TableCell>
                                            <StatusBadge isActive={!!user.email_verified_at} />
                                        </TableCell>
                                        <TableCell>
                                            <div className="flex items-center gap-1.5 text-sm text-muted-foreground">
                                                <Calendar className="h-3.5 w-3.5" />
                                                {formatDate(user.created_at)}
                                            </div>
                                        </TableCell>
                                        <TableCell onClick={(e) => e.stopPropagation()}>
                                            <DropdownMenu>
                                                <DropdownMenuTrigger asChild>
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        className="h-8 w-8 p-0"
                                                    >
                                                        <MoreHorizontal className="h-4 w-4" />
                                                    </Button>
                                                </DropdownMenuTrigger>
                                                <DropdownMenuContent align="end" className="w-48">
                                                    <DropdownMenuItem asChild>
                                                        <Link href={`/users/${user.uuid}`} className="flex items-center cursor-pointer">
                                                            <Eye className="mr-2 h-4 w-4" />
                                                            View Profile
                                                        </Link>
                                                    </DropdownMenuItem>
                                                    <DropdownMenuItem asChild>
                                                        <Link href={`/users/${user.uuid}/edit`} className="flex items-center cursor-pointer">
                                                            <Edit className="mr-2 h-4 w-4" />
                                                            Edit User
                                                        </Link>
                                                    </DropdownMenuItem>
                                                    {user.role !== 'super_admin' && (
                                                        <>
                                                            <DropdownMenuSeparator />
                                                            <DropdownMenuItem
                                                                onClick={() => setDeleteUser(user)}
                                                                className="text-red-600 focus:text-red-600 focus:bg-red-50 cursor-pointer"
                                                            >
                                                                <TrashIcon className="mr-2 h-4 w-4" />
                                                                Delete User
                                                            </DropdownMenuItem>
                                                        </>
                                                    )}
                                                </DropdownMenuContent>
                                            </DropdownMenu>
                                        </TableCell>
                                    </TableRow>
                                ))
                            )}
                        </TableBody>
                    </Table>
                </div>
            </div>

            {/* Delete Confirmation Dialog */}
            <Dialog open={!!deleteUser} onOpenChange={() => setDeleteUser(null)}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <div className="mx-auto w-12 h-12 rounded-full bg-red-100 flex items-center justify-center mb-4">
                            <TrashIcon className="h-6 w-6 text-red-600" />
                        </div>
                        <DialogTitle className="text-center">Delete User</DialogTitle>
                        <DialogDescription className="text-center">
                            Are you sure you want to delete <span className="font-medium text-gray-900">{deleteUser?.name}</span>?
                            This action cannot be undone and will permanently remove their account and all associated data.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="flex gap-3 mt-6">
                        <Button
                            variant="outline"
                            onClick={() => setDeleteUser(null)}
                            className="flex-1"
                        >
                            Cancel
                        </Button>
                        <Button
                            variant="destructive"
                            onClick={handleDelete}
                            disabled={processing}
                            className="flex-1"
                        >
                            {processing ? 'Deleting...' : 'Delete User'}
                        </Button>
                    </div>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
