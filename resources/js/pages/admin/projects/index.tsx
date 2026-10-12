import { Head, Link, router } from '@inertiajs/react';
import { useState, useMemo } from 'react';
import AppLayout from '@/layouts/app-layout';
import { PageHeader } from '@/components/page-header';
import { StatCard } from '@/components/stat-card';
import { DateRangePicker } from '@/components/ui/date-range-picker';
import { filterControlClass } from '@/lib/utils';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { PlusIcon, Users, FileText, Edit, Trash2, Eye, Search, ChevronUp, ChevronDown, ChevronsUpDown, MoreHorizontal, FolderKanban, CheckCircle2, Clock, Loader2, XCircle, Briefcase, X, RotateCcw } from 'lucide-react';

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
    open_reports_count?: number;
    in_progress_reports_count?: number;
    resolved_reports_count?: number;
    closed_reports_count?: number;
    reopened_reports_count?: number;
}

interface Props {
    projects: Project[];
}

type SortField = 'name' | 'status' | 'reports_count' | 'created_at'
type SortOrder = 'asc' | 'desc'

// Project avatar with initials
function ProjectAvatar({ name }: { name: string }) {
    const initials = name
        .split(' ')
        .map(n => n[0])
        .join('')
        .toUpperCase()
        .slice(0, 2);

    return (
        <div className="w-10 h-10 rounded-lg bg-gradient-to-br from-indigo-500 to-purple-600 flex items-center justify-center text-white font-semibold text-sm shadow-sm">
            {initials}
        </div>
    );
}

// Status badge
function StatusBadge({ status }: { status: string }) {
    const config = {
        active: {
            label: 'Active',
            className: 'bg-green-50 text-green-700 border-green-200',
            dot: 'bg-green-500'
        },
        inactive: {
            label: 'Inactive',
            className: 'bg-gray-50 text-gray-600 border-gray-200',
            dot: 'bg-gray-400'
        },
        completed: {
            label: 'Completed',
            className: 'bg-blue-50 text-blue-700 border-blue-200',
            dot: 'bg-blue-500'
        },
    }[status] || {
        label: status,
        className: 'bg-gray-50 text-gray-600 border-gray-200',
        dot: 'bg-gray-400'
    };

    return (
        <span className={`inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium border ${config.className}`}>
            <span className={`w-1.5 h-1.5 rounded-full ${config.dot} ${status === 'active' ? 'animate-pulse' : ''}`} />
            {config.label}
        </span>
    );
}

// Report status mini badge
function ReportStatusBadge({ count, type }: { count: number; type: 'open' | 'in_progress' | 'resolved' | 'closed' | 'reopened' }) {
    if (count === 0) {
        return <span className="text-gray-300">—</span>;
    }

    const config = {
        open: { bg: 'bg-blue-100 text-blue-700', icon: Clock },
        in_progress: { bg: 'bg-amber-100 text-amber-700', icon: Loader2 },
        resolved: { bg: 'bg-purple-100 text-purple-700', icon: CheckCircle2 },
        closed: { bg: 'bg-green-100 text-green-700', icon: XCircle },
        reopened: { bg: 'bg-red-100 text-red-700', icon: RotateCcw },
    }[type];

    const Icon = config.icon;

    return (
        <span className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-xs font-medium ${config.bg}`}>
            <Icon className="w-3 h-3" />
            {count}
        </span>
    );
}

// Stats card
export default function ProjectIndex({ projects }: Props) {
    const [deleteProject, setDeleteProject] = useState<Project | null>(null);
    const [searchQuery, setSearchQuery] = useState('');
    const [statusFilter, setStatusFilter] = useState('all');
    const [dateFrom, setDateFrom] = useState('');
    const [dateTo, setDateTo] = useState('');
    const [sortField, setSortField] = useState<SortField>('created_at');
    const [sortOrder, setSortOrder] = useState<SortOrder>('desc');
    const [deleting, setDeleting] = useState(false);

    const handleDelete = () => {
        if (!deleteProject) return;
        setDeleting(true);
        router.delete(`/projects/${deleteProject.id}`, {
            onSuccess: () => {
                setDeleteProject(null);
                setDeleting(false);
            },
            onError: () => {
                setDeleting(false);
            }
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

            // Compare on the created date in the viewer's own timezone
            const created = new Date(project.created_at);
            const createdDate = `${created.getFullYear()}-${String(created.getMonth() + 1).padStart(2, '0')}-${String(created.getDate()).padStart(2, '0')}`;
            const matchesDate = (!dateFrom || createdDate >= dateFrom) && (!dateTo || createdDate <= dateTo);

            return matchesSearch && matchesStatus && matchesDate;
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
    }, [projects, searchQuery, statusFilter, dateFrom, dateTo, sortField, sortOrder]);

    // Stats
    const stats = useMemo(() => ({
        total: projects.length,
        active: projects.filter(p => p.status === 'active').length,
        totalReports: projects.reduce((sum, p) => sum + (p.reports_count || 0), 0),
    }), [projects]);

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

    return (
        <AppLayout>
            <Head title="Projects" />

            <div className="space-y-6">
                {/* Header */}
                <PageHeader title="Projects" description="Manage projects and track their progress">
                    <Link href="/projects/create">
                        <Button className="shadow-sm">
                            <PlusIcon className="h-4 w-4 mr-2" />
                            Create Project
                        </Button>
                    </Link>
                </PageHeader>

                {/* Stats Cards */}
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <StatCard
                        icon={FolderKanban}
                        label="Total Projects"
                        value={stats.total}
                    />
                    <StatCard
                        icon={Briefcase}
                        label="Active Projects"
                        value={stats.active}
                    />
                    <StatCard
                        icon={FileText}
                        label="Total Issues"
                        value={stats.totalReports}
                    />
                </div>

                {/* Filters */}
                <div className="flex items-center gap-3">
                    <div className="relative flex-1 max-w-md">
                        <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" />
                        <Input
                            placeholder="Search projects..."
                            value={searchQuery}
                            onChange={(e) => setSearchQuery(e.target.value)}
                            className="pl-9 h-9 bg-white"
                        />
                        {searchQuery && (
                            <button
                                type="button"
                                aria-label="Clear search"
                                onClick={() => setSearchQuery('')}
                                className="absolute right-2 top-1/2 -translate-y-1/2 p-1 hover:bg-gray-100 rounded"
                            >
                                <X className="w-3 h-3 text-gray-400" />
                            </button>
                        )}
                    </div>

                    <Select value={statusFilter} onValueChange={setStatusFilter}>
                        <SelectTrigger className={`${filterControlClass(statusFilter !== 'all')} w-40 shrink-0`}>
                            <SelectValue placeholder="All Status" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Status</SelectItem>
                            <SelectItem value="active">Active</SelectItem>
                            <SelectItem value="inactive">Inactive</SelectItem>
                            <SelectItem value="completed">Completed</SelectItem>
                        </SelectContent>
                    </Select>

                    <DateRangePicker
                        aria-label="Created date range"
                        from={dateFrom}
                        to={dateTo}
                        onChange={({ from, to }) => {
                            setDateFrom(from);
                            setDateTo(to);
                        }}
                        placeholder="Created date"
                        className={`${filterControlClass(!!dateFrom)} shrink-0 px-3 shadow-xs`}
                    />

                    {(searchQuery || statusFilter !== 'all' || dateFrom || dateTo) && (
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => {
                                setSearchQuery('');
                                setStatusFilter('all');
                                setDateFrom('');
                                setDateTo('');
                            }}
                            className="h-9 px-3 text-gray-500 hover:text-gray-700"
                        >
                            <X className="w-3.5 h-3.5 mr-1" />
                            Clear
                        </Button>
                    )}
                </div>

                {/* Results count */}
                {filteredAndSortedProjects.length !== projects.length && (
                    <p className="text-sm text-muted-foreground">
                        Showing {filteredAndSortedProjects.length} of {projects.length} projects
                    </p>
                )}

                {/* Table */}
                <div className="bg-white shadow-sm border rounded-xl overflow-hidden">
                    <Table>
                        <TableHeader>
                            <TableRow className="bg-slate-50/50">
                                <TableHead className="w-[280px]">
                                    <Button
                                        variant="ghost"
                                        className="h-auto p-0 font-medium text-xs uppercase tracking-wider hover:bg-transparent text-muted-foreground"
                                        onClick={() => handleSort('name')}
                                    >
                                        Project
                                        {getSortIcon('name')}
                                    </Button>
                                </TableHead>
                                <TableHead>
                                    <Button
                                        variant="ghost"
                                        className="h-auto p-0 font-medium text-xs uppercase tracking-wider hover:bg-transparent text-muted-foreground"
                                        onClick={() => handleSort('status')}
                                    >
                                        Status
                                        {getSortIcon('status')}
                                    </Button>
                                </TableHead>
                                <TableHead className="font-medium text-xs uppercase tracking-wider text-muted-foreground text-center">
                                    Clients
                                </TableHead>
                                <TableHead className="font-medium text-xs uppercase tracking-wider text-muted-foreground text-center" colSpan={5}>
                                    Issue Status
                                </TableHead>
                                <TableHead>
                                    <Button
                                        variant="ghost"
                                        className="h-auto p-0 font-medium text-xs uppercase tracking-wider hover:bg-transparent text-muted-foreground"
                                        onClick={() => handleSort('reports_count')}
                                    >
                                        Total
                                        {getSortIcon('reports_count')}
                                    </Button>
                                </TableHead>
                                <TableHead>
                                    <Button
                                        variant="ghost"
                                        className="h-auto p-0 font-medium text-xs uppercase tracking-wider hover:bg-transparent text-muted-foreground"
                                        onClick={() => handleSort('created_at')}
                                    >
                                        Created
                                        {getSortIcon('created_at')}
                                    </Button>
                                </TableHead>
                                <TableHead className="w-[50px]"></TableHead>
                            </TableRow>
                            <TableRow className="bg-slate-50/30 border-b">
                                <TableHead></TableHead>
                                <TableHead></TableHead>
                                <TableHead></TableHead>
                                <TableHead className="text-xs text-muted-foreground font-normal text-center py-1">Open</TableHead>
                                <TableHead className="text-xs text-muted-foreground font-normal text-center py-1 whitespace-nowrap">In Progress</TableHead>
                                <TableHead className="text-xs text-muted-foreground font-normal text-center py-1">Resolved</TableHead>
                                <TableHead className="text-xs text-muted-foreground font-normal text-center py-1">Closed</TableHead>
                                <TableHead className="text-xs text-muted-foreground font-normal text-center py-1">Reopened</TableHead>
                                <TableHead></TableHead>
                                <TableHead></TableHead>
                                <TableHead></TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {filteredAndSortedProjects.length === 0 ? (
                                <TableRow>
                                    <TableCell colSpan={11} className="h-32 text-center">
                                        <div className="flex flex-col items-center gap-2 text-muted-foreground">
                                            <FolderKanban className="h-8 w-8 opacity-50" />
                                            <p>No projects found</p>
                                            {(searchQuery || statusFilter !== 'all' || dateFrom || dateTo) && (
                                                <Button
                                                    variant="link"
                                                    size="sm"
                                                    onClick={() => {
                                                        setSearchQuery('');
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
                                filteredAndSortedProjects.map((project) => (
                                    <TableRow
                                        key={project.id}
                                        className="group hover:bg-slate-50/50 transition-colors cursor-pointer"
                                        onClick={() => handleView(project.uuid)}
                                    >
                                        <TableCell>
                                            <div className="flex items-center gap-3">
                                                <ProjectAvatar name={project.name} />
                                                <div className="min-w-0">
                                                    <p className="font-medium text-gray-900 truncate">
                                                        {project.name}
                                                    </p>
                                                    <p className="text-sm text-muted-foreground truncate max-w-[200px]">
                                                        {project.description || 'No description'}
                                                    </p>
                                                </div>
                                            </div>
                                        </TableCell>
                                        <TableCell>
                                            <StatusBadge status={project.status || 'active'} />
                                        </TableCell>
                                        <TableCell>
                                            <div className="flex items-center justify-center gap-1.5">
                                                <Users className="w-4 h-4 text-muted-foreground" />
                                                <span className="font-medium">{project.users.length}</span>
                                            </div>
                                        </TableCell>
                                        <TableCell className="text-center">
                                            <ReportStatusBadge count={project.open_reports_count || 0} type="open" />
                                        </TableCell>
                                        <TableCell className="text-center">
                                            <ReportStatusBadge count={project.in_progress_reports_count || 0} type="in_progress" />
                                        </TableCell>
                                        <TableCell className="text-center">
                                            <ReportStatusBadge count={project.resolved_reports_count || 0} type="resolved" />
                                        </TableCell>
                                        <TableCell className="text-center">
                                            <ReportStatusBadge count={project.closed_reports_count || 0} type="closed" />
                                        </TableCell>
                                        <TableCell className="text-center">
                                            <ReportStatusBadge count={project.reopened_reports_count || 0} type="reopened" />
                                        </TableCell>
                                        <TableCell>
                                            <div className="flex items-center gap-1.5">
                                                <FileText className="w-4 h-4 text-muted-foreground" />
                                                <span className="font-medium">{project.reports_count}</span>
                                            </div>
                                        </TableCell>
                                        <TableCell className="text-sm text-muted-foreground whitespace-nowrap">
                                            {new Date(project.created_at).toLocaleDateString()}
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
                                                    <DropdownMenuItem
                                                        onClick={() => handleView(project.uuid)}
                                                        className="cursor-pointer"
                                                    >
                                                        <Eye className="mr-2 h-4 w-4" />
                                                        View Project
                                                    </DropdownMenuItem>
                                                    <DropdownMenuItem asChild>
                                                        <Link href={`/projects/${project.uuid}/edit`} className="flex items-center cursor-pointer">
                                                            <Edit className="mr-2 h-4 w-4" />
                                                            Edit Project
                                                        </Link>
                                                    </DropdownMenuItem>
                                                    <DropdownMenuSeparator />
                                                    <DropdownMenuItem
                                                        onClick={() => setDeleteProject(project)}
                                                        className="text-red-600 focus:text-red-600 focus:bg-red-50 cursor-pointer"
                                                    >
                                                        <Trash2 className="mr-2 h-4 w-4" />
                                                        Delete Project
                                                    </DropdownMenuItem>
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
            <Dialog open={!!deleteProject} onOpenChange={() => setDeleteProject(null)}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <div className="mx-auto w-12 h-12 rounded-full bg-red-100 flex items-center justify-center mb-4">
                            <Trash2 className="h-6 w-6 text-red-600" />
                        </div>
                        <DialogTitle className="text-center">Delete Project</DialogTitle>
                        <DialogDescription className="text-center">
                            Are you sure you want to delete <span className="font-medium text-gray-900">{deleteProject?.name}</span>?
                            This will also delete all associated issues and data. This action cannot be undone.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="flex gap-3 mt-6">
                        <Button
                            variant="outline"
                            onClick={() => setDeleteProject(null)}
                            className="flex-1"
                        >
                            Cancel
                        </Button>
                        <Button
                            variant="destructive"
                            onClick={handleDelete}
                            disabled={deleting}
                            className="flex-1"
                        >
                            {deleting ? 'Deleting...' : 'Delete Project'}
                        </Button>
                    </div>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
