import { Head, Link, router } from '@inertiajs/react';
import { useState, useMemo } from 'react';
import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { PlusIcon, Users, FileText, Edit, Trash2, Eye, Search, ChevronUp, ChevronDown, ChevronsUpDown } from 'lucide-react';

interface User {
    id: number;
    name: string;
    email: string;
}

interface Project {
    id: number;
    uuid: string;
    name: string;
    description: string;
    status: 'active' | 'inactive' | 'completed';
    created_by: number;
    created_at: string;
    updated_at: string;
    creator: User;
    users: User[];
    reports_count: number;
    pending_reports_count?: number;
    in_progress_reports_count?: number;
    approved_reports_count?: number;
    closed_reports_count?: number;
}

interface Props {
    projects: Project[];
}

type SortField = 'name' | 'status' | 'reports_count' | 'created_at'
type SortOrder = 'asc' | 'desc'

export default function ProjectIndex({ projects }: Props) {
    const [deleteConfirm, setDeleteConfirm] = useState<number | null>(null);
    const [searchQuery, setSearchQuery] = useState('');
    const [statusFilter, setStatusFilter] = useState('all');
    const [sortField, setSortField] = useState<SortField>('created_at');
    const [sortOrder, setSortOrder] = useState<SortOrder>('desc');

    const formatStatus = (status: string) => {
        return status.charAt(0).toUpperCase() + status.slice(1);
    };

    const getStatusColor = (status: string) => {
        switch (status) {
            case 'active':
                return 'bg-green-100 text-green-800';
            case 'inactive':
                return 'bg-gray-100 text-gray-800';
            case 'completed':
                return 'bg-blue-100 text-blue-800';
            default:
                return 'bg-gray-100 text-gray-800';
        }
    };

    const handleDelete = (projectId: number) => {
        router.delete(`/projects/${projectId}`, {
            onSuccess: () => setDeleteConfirm(null),
        });
    };

    const handleView = (projectUuid: string) => {
        router.get(`/projects/view/${projectUuid}`);
    };

    const filteredAndSortedProjects = useMemo(() => {
        let filtered = projects.filter(project => {
            const matchesSearch = project.name.toLowerCase().includes(searchQuery.toLowerCase()) ||
                                project.description?.toLowerCase().includes(searchQuery.toLowerCase());
            const matchesStatus = statusFilter === 'all' || project.status === statusFilter;
            
            return matchesSearch && matchesStatus;
        });

        return filtered.sort((a, b) => {
            let aValue: any, bValue: any;

            switch (sortField) {
                case 'name':
                    aValue = a.name.toLowerCase();
                    bValue = b.name.toLowerCase();
                    break;
                case 'status':
                    aValue = a.status;
                    bValue = b.status;
                    break;
                case 'reports_count':
                    aValue = a.reports_count || 0;
                    bValue = b.reports_count || 0;
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
    }, [projects, searchQuery, statusFilter, sortField, sortOrder]);

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
            <Head title="Projects" />

            <div className="space-y-6 px-4 py-2">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-3xl font-bold">Project Management</h1>
                        <p className="text-muted-foreground mt-1">
                            Manage projects and track their progress
                        </p>
                    </div>
                    <Link href="/projects/create">
                        <Button>
                            <PlusIcon className="h-4 w-4 mr-2" />
                            Create Project
                        </Button>
                    </Link>
                </div>

                <div className="flex flex-wrap gap-4">
                    <div className="flex items-center space-x-2">
                        <Search className="w-4 h-4 text-muted-foreground" />
                        <Input
                            placeholder="Search projects..."
                            value={searchQuery}
                            onChange={(e) => setSearchQuery(e.target.value)}
                            className="w-64"
                        />
                    </div>
                    
                    <Select value={statusFilter} onValueChange={setStatusFilter}>
                        <SelectTrigger className="w-48">
                            <SelectValue placeholder="Filter by status" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Status</SelectItem>
                            <SelectItem value="active">Active</SelectItem>
                            <SelectItem value="inactive">Inactive</SelectItem>
                            <SelectItem value="completed">Completed</SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <div className="bg-white shadow-sm border rounded-lg">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead className="w-32">Actions</TableHead>
                                <TableHead>
                                    <Button 
                                        variant="ghost" 
                                        className="h-auto p-0 font-semibold hover:bg-transparent"
                                        onClick={() => handleSort('name')}
                                    >
                                        Project Name
                                        {getSortIcon('name')}
                                    </Button>
                                </TableHead>
                                <TableHead>Description</TableHead>
                                <TableHead>
                                    <Button 
                                        variant="ghost" 
                                        className="h-auto p-0 font-semibold hover:bg-transparent"
                                        onClick={() => handleSort('status')}
                                    >
                                        Status
                                        {getSortIcon('status')}
                                    </Button>
                                </TableHead>
                                <TableHead>Clients</TableHead>
                                <TableHead>Pending</TableHead>
                                <TableHead>In Progress</TableHead>
                                <TableHead>Approved</TableHead>
                                <TableHead>Closed</TableHead>
                                <TableHead>
                                    <Button 
                                        variant="ghost" 
                                        className="h-auto p-0 font-semibold hover:bg-transparent"
                                        onClick={() => handleSort('reports_count')}
                                    >
                                        Total Reports
                                        {getSortIcon('reports_count')}
                                    </Button>
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {filteredAndSortedProjects.map((project) => (
                                <TableRow 
                                    key={project.id}
                                    className="hover:bg-gray-50"
                                >
                                    <TableCell>
                                        <div className="flex items-center gap-1">
                                            <Button 
                                                variant="ghost" 
                                                size="sm"
                                                onClick={() => handleView(project.uuid)}
                                                title="View project details, reports and clients"
                                            >
                                                <Eye className="h-4 w-4" />
                                            </Button>
                                            <Link href={`/projects/${project.uuid}/edit`}>
                                                <Button variant="ghost" size="sm" title="Edit project">
                                                    <Edit className="h-4 w-4" />
                                                </Button>
                                            </Link>
                                            {deleteConfirm === project.id ? (
                                                <div className="flex gap-1">
                                                    <Button
                                                        variant="destructive"
                                                        size="sm"
                                                        onClick={() => handleDelete(project.id)}
                                                    >
                                                        ✓
                                                    </Button>
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        onClick={() => setDeleteConfirm(null)}
                                                    >
                                                        ✗
                                                    </Button>
                                                </div>
                                            ) : (
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() => setDeleteConfirm(project.id)}
                                                    className="text-red-600 hover:text-red-700"
                                                    title="Delete project"
                                                >
                                                    <Trash2 className="h-4 w-4" />
                                                </Button>
                                            )}
                                        </div>
                                    </TableCell>
                                    <TableCell className="font-medium">
                                        {project.name}
                                    </TableCell>
                                    <TableCell className="max-w-xs">
                                        <div className="truncate">
                                            {project.description || '-'}
                                        </div>
                                    </TableCell>
                                    <TableCell>
                                        <Badge className={getStatusColor(project.status)}>
                                            {formatStatus(project.status || 'active')}
                                        </Badge>
                                    </TableCell>
                                    <TableCell>
                                        <div className="flex items-center gap-1">
                                            <Users className="w-4 h-4 text-gray-400" />
                                            {project.users.length}
                                        </div>
                                    </TableCell>
                                    <TableCell className="text-center">
                                        {project.pending_reports_count || 0}
                                    </TableCell>
                                    <TableCell className="text-center">
                                        {project.in_progress_reports_count || 0}
                                    </TableCell>
                                    <TableCell className="text-center">
                                        {project.approved_reports_count || 0}
                                    </TableCell>
                                    <TableCell className="text-center">
                                        {project.closed_reports_count || 0}
                                    </TableCell>
                                    <TableCell>
                                        <div className="flex items-center gap-1">
                                            <FileText className="w-4 h-4 text-gray-400" />
                                            {project.reports_count}
                                        </div>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                        </Table>
                    </div>
                </div>
        </AppLayout>
    );
}