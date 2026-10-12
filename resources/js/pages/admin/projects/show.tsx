import { Head, Link, router, usePage } from '@inertiajs/react';
import { StatCard } from '@/components/stat-card';
import { columnDragClass, useIssueDrag } from '@/hooks/use-issue-drag';
import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { ArrowLeft, Users, FileText, Calendar, User, Clock, CheckCircle2, AlertCircle, FolderKanban, Plus, Settings, RotateCcw, Bug, Sparkles, ArrowUpRight, AlertTriangle, Info, Pencil } from 'lucide-react';

interface UserType {
    id: number;
    name: string;
    email: string;
    role?: string;
}

interface Report {
    id: number;
    uuid: string;
    issue_key?: string;
    title: string;
    status: 'open' | 'in_progress' | 'resolved' | 'closed' | 'reopened';
    priority?: 'low' | 'medium' | 'high' | 'urgent';
    created_at: string;
    reporter: UserType;
    assignee?: UserType;
}

interface Project {
    id: number;
    uuid: string;
    name: string;
    prefix?: string;
    description: string;
    status: 'active' | 'inactive' | 'completed';
    created_at: string;
    updated_at: string;
    creator: UserType;
    users: UserType[];
    reports: Report[];
}

interface Props {
    project: Project;
    /** False for staff, who can view the project but not change its settings */
    canManage: boolean;
}

// Status configuration
const statusConfig = {
    open: { label: 'Open', bg: 'bg-blue-100', text: 'text-blue-700', dot: 'bg-blue-500' },
    in_progress: { label: 'In Progress', bg: 'bg-amber-100', text: 'text-amber-700', dot: 'bg-amber-500' },
    resolved: { label: 'Resolved', bg: 'bg-purple-100', text: 'text-purple-700', dot: 'bg-purple-500' },
    closed: { label: 'Closed', bg: 'bg-green-100', text: 'text-green-700', dot: 'bg-green-500' },
    reopened: { label: 'Reopened', bg: 'bg-red-100', text: 'text-red-700', dot: 'bg-red-500' },
};

const priorityConfig = {
    urgent: { icon: AlertTriangle, color: 'text-red-500' },
    high: { icon: ArrowUpRight, color: 'text-orange-500' },
    medium: { icon: AlertCircle, color: 'text-yellow-500' },
    low: { icon: Info, color: 'text-blue-500' },
};

const roleColors: Record<string, string> = {
    super_admin: 'bg-gradient-to-br from-red-500 to-rose-600',
    admin: 'bg-gradient-to-br from-blue-500 to-indigo-600',
    manager: 'bg-gradient-to-br from-purple-500 to-violet-600',
    staff: 'bg-gradient-to-br from-emerald-500 to-teal-600',
};

const roleBadgeColors: Record<string, string> = {
    super_admin: 'bg-red-100 text-red-700',
    admin: 'bg-blue-100 text-blue-700',
    manager: 'bg-purple-100 text-purple-700',
    staff: 'bg-emerald-100 text-emerald-700',
};

function IssueCard({ report, projectPrefix, dragProps = {}, moving = false }: { report: Report; projectPrefix?: string; dragProps?: object; moving?: boolean }) {
    const status = statusConfig[report.status] || statusConfig.open;
    const priority = priorityConfig[report.priority || 'medium'];
    const PriorityIcon = priority?.icon || AlertCircle;

    return (
        <div
            {...dragProps}
            onClick={() => router.get(`/reports/${report.uuid}`)}
            className={`bg-white border rounded-lg p-3 hover:shadow-md transition-all cursor-pointer group ${moving ? 'opacity-50' : ''}`}
        >
            <div className="flex items-start justify-between gap-2 mb-2">
                <span className="text-xs font-mono text-blue-600 group-hover:underline">
                    {report.issue_key || `${projectPrefix || 'PROJ'}-${report.id}`}
                </span>
                <PriorityIcon className={`w-3.5 h-3.5 ${priority?.color}`} />
            </div>
            <p className="text-sm font-medium text-gray-900 line-clamp-2 mb-2 group-hover:text-blue-600">
                {report.title}
            </p>
            <div className="flex items-center justify-between">
                {report.assignee ? (
                    <div className="w-6 h-6 rounded-full bg-gradient-to-br from-indigo-500 to-purple-600 flex items-center justify-center text-white text-[10px] font-medium" title={report.assignee.name}>
                        {report.assignee.name.charAt(0)}
                    </div>
                ) : (
                    <div className="w-6 h-6 rounded-full bg-gray-100 flex items-center justify-center">
                        <User className="w-3 h-3 text-gray-400" />
                    </div>
                )}
                <span className={`text-[10px] px-1.5 py-0.5 rounded ${status.bg} ${status.text}`}>
                    {status.label}
                </span>
            </div>
        </div>
    );
}

export default function ProjectShow({ project, canManage }: Props) {
    const formatDate = (dateString: string) => {
        return new Date(dateString).toLocaleDateString('en-GB', {
            day: 'numeric',
            month: 'short',
            year: 'numeric',
        });
    };

    // Calculate report stats with new statuses
    const stats = {
        open: project.reports.filter(r => r.status === 'open').length,
        reopened: project.reports.filter(r => r.status === 'reopened').length,
        inProgress: project.reports.filter(r => r.status === 'in_progress').length,
        resolved: project.reports.filter(r => r.status === 'resolved').length,
        closed: project.reports.filter(r => r.status === 'closed').length,
    };

    // Drag an issue to another column to change its status; the server checks each move is allowed
    const { auth } = usePage<{ auth: { user: { role?: string } } }>().props;
    const drag = useIssueDrag({
        enabled: !!auth.user.role,
        onMoved: () => router.reload({ only: ['project'] }),
    });

    // Every issue in the project, one column per status
    const boardColumns: Array<{ status: Report['status']; empty: string }> = [
        { status: 'open', empty: 'No open issues' },
        { status: 'in_progress', empty: 'No issues in progress' },
        { status: 'resolved', empty: 'No resolved issues' },
        { status: 'closed', empty: 'No closed issues' },
        { status: 'reopened', empty: 'No reopened issues' },
    ];

    return (
        <AppLayout>
            <Head title={project.name} />

            <div className="space-y-6">
                {/* Header */}
                <div className="flex items-start justify-between">
                    <div className="flex items-center gap-4">
                        <Link href="/projects">
                            <Button variant="outline" size="sm">
                                <ArrowLeft className="h-4 w-4 mr-2" />
                                Back
                            </Button>
                        </Link>
                        <div>
                            <div className="flex items-center gap-3">
                                <h1 className="text-3xl font-extrabold tracking-tight">{project.name}</h1>
                                {project.prefix && (
                                    <span className="text-sm font-mono text-gray-400 bg-gray-100 px-2 py-0.5 rounded">
                                        {project.prefix}
                                    </span>
                                )}
                                <span className={`inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium ${
                                    project.status === 'active' ? 'bg-green-100 text-green-700' :
                                    project.status === 'completed' ? 'bg-blue-100 text-blue-700' :
                                    'bg-gray-100 text-gray-600'
                                }`}>
                                    <span className={`w-1.5 h-1.5 rounded-full ${
                                        project.status === 'active' ? 'bg-green-500 animate-pulse' :
                                        project.status === 'completed' ? 'bg-blue-500' :
                                        'bg-gray-400'
                                    }`} />
                                    {project.status.charAt(0).toUpperCase() + project.status.slice(1)}
                                </span>
                            </div>
                            {project.description && (
                                <p className="text-muted-foreground mt-1 max-w-2xl">{project.description}</p>
                            )}
                        </div>
                    </div>
                    <div className="flex gap-2">
                        {project.status === 'active' && (
                            <Link href={`/reports/create?project=${project.uuid}`}>
                                <Button>
                                    <Plus className="h-4 w-4 mr-2" />
                                    New Issue
                                </Button>
                            </Link>
                        )}
                        {canManage && (
                            <Link href={`/projects/${project.uuid}/edit`}>
                                <Button variant="outline">
                                    <Pencil className="h-4 w-4" />
                                </Button>
                            </Link>
                        )}
                    </div>
                </div>

                {/* Stats */}
                <div className="grid grid-cols-2 gap-4 md:grid-cols-3 xl:grid-cols-5">
                    <StatCard label="Open" value={stats.open} hint="Not yet started" icon={AlertCircle} color="text-blue-600 bg-blue-50" />
                    <StatCard label="In Progress" value={stats.inProgress} hint="Being worked on" icon={Clock} color="text-amber-600 bg-amber-50" />
                    <StatCard label="Resolved" value={stats.resolved} hint="Waiting for the reporter or manager to confirm" icon={CheckCircle2} color="text-purple-600 bg-purple-50" />
                    <StatCard label="Closed" value={stats.closed} hint="Confirmed and completed" icon={CheckCircle2} color="text-green-600 bg-green-50" />
                    <StatCard label="Reopened" value={stats.reopened} hint="Sent back, needs rework" icon={RotateCcw} color="text-red-600 bg-red-50" />
                </div>

                {/* Main Content */}
                <div className="grid grid-cols-1 lg:grid-cols-4 gap-6">
                    {/* Issues Board */}
                    <div className="lg:col-span-3">
                        <div className="bg-white border rounded-xl overflow-hidden">
                            <div className="px-5 py-4 border-b flex items-center justify-between">
                                <h2 className="font-medium text-gray-900">Issues</h2>
                                <span className="text-sm text-gray-500">
                                    {drag.enabled && 'Drag an issue to another column to change its status · '}
                                    {project.reports.length} total
                                </span>
                            </div>

                            {project.reports.length === 0 ? (
                                <div className="text-center py-16">
                                    <FolderKanban className="w-12 h-12 text-gray-300 mx-auto mb-3" />
                                    <p className="text-gray-500 mb-4">No issues yet</p>
                                    <Link href={`/reports/create?project=${project.uuid}`}>
                                        <Button size="sm">
                                            <Plus className="w-4 h-4 mr-1" />
                                            Create First Issue
                                        </Button>
                                    </Link>
                                </div>
                            ) : (
                                <>
                                <div className="flex gap-4 overflow-x-auto p-4">
                                    {boardColumns.map(({ status, empty }) => {
                                        const issues = project.reports.filter(r => r.status === status);

                                        return (
                                            <div
                                                key={status}
                                                {...drag.columnProps(status)}
                                                className={`min-w-[200px] flex-1 overflow-hidden rounded-[14px] border bg-muted transition ${columnDragClass(drag.columnState(status))}`}
                                            >
                                                <div className="flex items-center gap-2 border-b bg-card px-4 py-3">
                                                    <span className={`w-2.5 h-2.5 rounded-full ${statusConfig[status].dot}`} />
                                                    <span className="eyebrow">{statusConfig[status].label}</span>
                                                    <span className="ml-auto rounded-md bg-muted px-2 py-0.5 text-xs text-muted-foreground">{issues.length}</span>
                                                </div>
                                                <div className="space-y-2 p-2">
                                                    {issues.length === 0 ? (
                                                        <p className="py-8 text-center text-sm text-gray-400">{empty}</p>
                                                    ) : (
                                                        issues.map(report => (
                                                            <IssueCard
                                                                key={report.id}
                                                                report={report}
                                                                projectPrefix={project.prefix}
                                                                dragProps={drag.cardProps(report)}
                                                                moving={drag.movingUuid === report.uuid}
                                                            />
                                                        ))
                                                    )}
                                                </div>
                                            </div>
                                        );
                                    })}
                                </div>
                                </>
                            )}
                        </div>
                    </div>

                    {/* Sidebar */}
                    <div className="space-y-6">
                        {/* Project Info */}
                        <div className="bg-white border rounded-xl p-5">
                            <h3 className="text-sm font-medium text-gray-500 uppercase tracking-wider mb-4">Details</h3>
                            <div className="space-y-4">
                                <div>
                                    <p className="text-xs text-gray-400 mb-1">Created by</p>
                                    <div className="flex items-center gap-2">
                                        <div className={`w-6 h-6 rounded-full ${roleColors[project.creator.role || 'staff']} flex items-center justify-center text-white text-xs font-medium`}>
                                            {project.creator.name.charAt(0)}
                                        </div>
                                        <span className="text-sm font-medium text-gray-900">{project.creator.name}</span>
                                    </div>
                                </div>
                                <div>
                                    <p className="text-xs text-gray-400 mb-1">Created</p>
                                    <p className="text-sm text-gray-900">{formatDate(project.created_at)}</p>
                                </div>
                                <div>
                                    <p className="text-xs text-gray-400 mb-1">Last updated</p>
                                    <p className="text-sm text-gray-900">{formatDate(project.updated_at)}</p>
                                </div>
                                <div>
                                    <p className="text-xs text-gray-400 mb-1">Total issues</p>
                                    <p className="text-sm text-gray-900">{project.reports.length}</p>
                                </div>
                            </div>
                        </div>

                        {/* Team Members */}
                        <div className="bg-white border rounded-xl p-5">
                            <div className="flex items-center justify-between mb-4">
                                <h3 className="text-sm font-medium text-gray-500 uppercase tracking-wider">Team</h3>
                                <span className="text-xs text-gray-400">{project.users.length} members</span>
                            </div>

                            {project.users.length === 0 ? (
                                <div className="text-center py-4">
                                    <Users className="w-8 h-8 text-gray-200 mx-auto mb-2" />
                                    <p className="text-xs text-gray-400">No members</p>
                                </div>
                            ) : (
                                <div className="space-y-3">
                                    {project.users.slice(0, 6).map((user) => (
                                        <div key={user.id} className="flex items-center gap-3">
                                            <div className={`w-8 h-8 rounded-full ${roleColors[user.role || 'staff']} flex items-center justify-center text-white text-xs font-medium`}>
                                                {user.name.charAt(0)}
                                            </div>
                                            <div className="flex-1 min-w-0">
                                                <p className="text-sm font-medium text-gray-900 truncate">{user.name}</p>
                                                <p className="text-xs text-gray-400 truncate">{user.email}</p>
                                            </div>
                                            <span className={`text-[10px] px-1.5 py-0.5 rounded ${roleBadgeColors[user.role || 'staff']}`}>
                                                {(user.role || 'staff').replace('_', ' ')}
                                            </span>
                                        </div>
                                    ))}
                                    {project.users.length > 6 && (
                                        <p className="text-xs text-center text-gray-400 pt-1">
                                            +{project.users.length - 6} more members
                                        </p>
                                    )}
                                </div>
                            )}
                        </div>

                        {/* Quick Actions */}
                        <div className="bg-white border rounded-xl p-5">
                            <h3 className="text-sm font-medium text-gray-500 uppercase tracking-wider mb-4">Quick Actions</h3>
                            <div className="space-y-2">
                                <Link href={`/reports/create?project=${project.uuid}`} className="block">
                                    <Button variant="outline" size="sm" className="w-full justify-start">
                                        <Bug className="w-4 h-4 mr-2 text-red-500" />
                                        Report Bug
                                    </Button>
                                </Link>
                                <Link href={`/reports/create?project=${project.uuid}`} className="block">
                                    <Button variant="outline" size="sm" className="w-full justify-start">
                                        <Sparkles className="w-4 h-4 mr-2 text-purple-500" />
                                        Request Feature
                                    </Button>
                                </Link>
                                {canManage && (
                                    <Link href={`/projects/${project.uuid}/edit`} className="block">
                                        <Button variant="outline" size="sm" className="w-full justify-start">
                                            <Pencil className="w-4 h-4 mr-2 text-gray-500" />
                                            Edit Project
                                        </Button>
                                    </Link>
                                )}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
