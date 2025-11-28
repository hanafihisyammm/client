import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, useForm } from '@inertiajs/react';
import { PlusIcon, Edit, TrashIcon, Search, Eye, ChevronUp, ChevronDown, ChevronsUpDown } from 'lucide-react';
import { useState, useEffect, useMemo } from 'react';

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

    const getRoleColor = (role: string) => {
        switch (role) {
            case 'super_admin':
                return 'bg-red-100 text-red-800';
            case 'admin':
                return 'bg-blue-100 text-blue-800';
            case 'client':
                return 'bg-yellow-100 text-yellow-800';
            default:
                return 'bg-gray-100 text-gray-800';
        }
    };

    const formatRole = (role: string) => {
        return role.replace('_', ' ').replace(/\b\w/g, l => l.toUpperCase());
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

    const handleSort = (field: SortField) => {
        const newOrder = sortField === field && sortOrder === 'asc' ? 'desc' : 'asc';
        setSortField(field);
        setSortOrder(newOrder);
    };

    const getSortIcon = (field: SortField) => {
        if (sortField !== field) {
            return <ChevronsUpDown className="ml-2 h-4 w-4 opacity-50" />;
        }
        return sortOrder === 'asc' ? 
            <ChevronUp className="ml-2 h-4 w-4" /> : 
            <ChevronDown className="ml-2 h-4 w-4" />;
    };

    return (
        <AppLayout>
            <Head title="Users" />

            <div className="space-y-6 px-4 py-2">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-3xl font-bold">User Management</h1>
                        <p className="text-muted-foreground mt-1">
                            Manage system users and their access levels
                        </p>
                    </div>
                    <Link href="/users/create">
                        <Button>
                            <PlusIcon className="h-4 w-4 mr-2" />
                            Add User
                        </Button>
                    </Link>
                </div>

                <div className="flex flex-wrap gap-4">
                    <div className="flex items-center space-x-2">
                        <Search className="w-4 h-4 text-muted-foreground" />
                        <Input
                            placeholder="Search users..."
                            value={searchQuery}
                            onChange={(e) => setSearchQuery(e.target.value)}
                            className="w-64"
                        />
                    </div>
                    
                    <Select value={roleFilter} onValueChange={setRoleFilter}>
                        <SelectTrigger className="w-48">
                            <SelectValue placeholder="Filter by role" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Roles</SelectItem>
                            <SelectItem value="super_admin">Super Admin</SelectItem>
                            <SelectItem value="admin">Admin</SelectItem>
                            <SelectItem value="client">Client</SelectItem>
                        </SelectContent>
                    </Select>

                    <Select value={statusFilter} onValueChange={setStatusFilter}>
                        <SelectTrigger className="w-48">
                            <SelectValue placeholder="Filter by status" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Status</SelectItem>
                            <SelectItem value="active">Active</SelectItem>
                            <SelectItem value="pending">Pending</SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <div className="bg-white shadow-sm border rounded-lg">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead className="w-24">Actions</TableHead>
                                <TableHead>
                                    <Button 
                                        variant="ghost" 
                                        className="h-auto p-0 font-semibold hover:bg-transparent"
                                        onClick={() => handleSort('name')}
                                    >
                                        Name
                                        {getSortIcon('name')}
                                    </Button>
                                </TableHead>
                                <TableHead>
                                    <Button 
                                        variant="ghost" 
                                        className="h-auto p-0 font-semibold hover:bg-transparent"
                                        onClick={() => handleSort('email')}
                                    >
                                        Email
                                        {getSortIcon('email')}
                                    </Button>
                                </TableHead>
                                <TableHead>
                                    <Button 
                                        variant="ghost" 
                                        className="h-auto p-0 font-semibold hover:bg-transparent"
                                        onClick={() => handleSort('role')}
                                    >
                                        Role
                                        {getSortIcon('role')}
                                    </Button>
                                </TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead>
                                    <Button 
                                        variant="ghost" 
                                        className="h-auto p-0 font-semibold hover:bg-transparent"
                                        onClick={() => handleSort('created_at')}
                                    >
                                        Created
                                        {getSortIcon('created_at')}
                                    </Button>
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {filteredAndSortedUsers.map((user) => (
                                <TableRow key={user.id} className="hover:bg-gray-50">
                                    <TableCell>
                                        <div className="flex items-center gap-1">
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                onClick={() => window.location.href = `/users/${user.uuid}`}
                                                title="View user"
                                            >
                                                <Eye className="h-4 w-4" />
                                            </Button>
                                            <Link href={`/users/${user.uuid}/edit`}>
                                                <Button variant="ghost" size="sm" title="Edit user">
                                                    <Edit className="h-4 w-4" />
                                                </Button>
                                            </Link>
                                            {user.role !== 'super_admin' && (
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() => setDeleteUser(user)}
                                                    className="text-red-600 hover:text-red-700"
                                                    title="Delete user"
                                                >
                                                    <TrashIcon className="h-4 w-4" />
                                                </Button>
                                            )}
                                        </div>
                                    </TableCell>
                                    <TableCell className="font-medium">
                                        {user.name}
                                    </TableCell>
                                    <TableCell className="text-sm text-gray-600">{user.email}</TableCell>
                                    <TableCell>
                                        <Badge className={getRoleColor(user.role)}>
                                            {formatRole(user.role)}
                                        </Badge>
                                    </TableCell>
                                    <TableCell>
                                        <Badge variant={user.email_verified_at ? "default" : "secondary"}>
                                            {user.email_verified_at ? "Active" : "Pending"}
                                        </Badge>
                                    </TableCell>
                                    <TableCell className="text-sm text-gray-600">
                                        {new Date(user.created_at).toLocaleDateString()}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
            </div>

            <Dialog open={!!deleteUser} onOpenChange={() => setDeleteUser(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Delete User</DialogTitle>
                        <DialogDescription>
                            Are you sure you want to delete {deleteUser?.name}? This action cannot be undone.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="flex justify-end gap-3 mt-6">
                        <Button variant="outline" onClick={() => setDeleteUser(null)}>
                            Cancel
                        </Button>
                        <Button 
                            variant="destructive" 
                            onClick={handleDelete}
                            disabled={processing}
                        >
                            {processing ? 'Deleting...' : 'Delete'}
                        </Button>
                    </div>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}