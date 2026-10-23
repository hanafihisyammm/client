import { router } from '@inertiajs/react';
import { columnDragClass, useIssueDrag, type IssueStatus } from '@/hooks/use-issue-drag';
import { Badge } from '@/components/ui/badge';
import { isIssueOverdue, labelStyle } from '@/lib/utils';
import { IssueTypeBadge } from '@/components/issue-type-badge';
import { Clock, ArrowUpRight, AlertTriangle, AlertCircle, Info, User } from 'lucide-react';

interface Label {
    id: number;
    name: string;
    color: string;
}

interface Report {
    id: number;
    uuid: string;
    issue_key?: string;
    title: string;
    description: string;
    project: string | { id: number; name: string; prefix?: string };
    status: 'open' | 'in_progress' | 'resolved' | 'closed' | 'reopened';
    due_date?: string | null;
    priority: 'low' | 'medium' | 'high' | 'urgent';
    type?: 'bug' | 'feature';
    severity?: 'trivial' | 'minor' | 'major' | 'critical' | null;
    reporter: { id: number; name: string; email: string };
    labels?: Label[];
    created_at: string;
}

interface KanbanBoardProps {
    reports: Report[];
    /** Whether the viewer may drag issues between columns to change their status */
    canDrag?: boolean;
    /** Called after an issue has been moved, so the caller can refresh its data */
    onMoved?: () => void;
    /** Show only this status's column, e.g. while the list is filtered to one status */
    onlyStatus?: IssueStatus | null;
}

// Columns share the neutral brand surface; only the dot carries the status colour
const statusConfig = {
    open: {
        label: 'Open',
        color: 'bg-blue-500',
        bgColor: 'bg-muted',
        borderColor: 'border-border',
        headerBg: 'bg-card',
    },
    in_progress: {
        label: 'In Progress',
        color: 'bg-amber-500',
        bgColor: 'bg-muted',
        borderColor: 'border-border',
        headerBg: 'bg-card',
    },
    resolved: {
        label: 'Resolved',
        color: 'bg-purple-500',
        bgColor: 'bg-muted',
        borderColor: 'border-border',
        headerBg: 'bg-card',
    },
    closed: {
        label: 'Closed',
        color: 'bg-green-500',
        bgColor: 'bg-muted',
        borderColor: 'border-border',
        headerBg: 'bg-card',
    },
    reopened: {
        label: 'Reopened',
        color: 'bg-red-500',
        bgColor: 'bg-muted',
        borderColor: 'border-border',
        headerBg: 'bg-card',
    },
};

const priorityConfig = {
    urgent: { icon: AlertTriangle, color: 'text-red-600', bg: 'bg-red-100' },
    high: { icon: ArrowUpRight, color: 'text-orange-600', bg: 'bg-orange-100' },
    medium: { icon: AlertCircle, color: 'text-yellow-600', bg: 'bg-yellow-100' },
    low: { icon: Info, color: 'text-blue-600', bg: 'bg-blue-100' },
};

const severityColors = {
    // Solid fills, the same in both themes. Black on orange and yellow, as white is too faint there.
    critical: 'bg-red-600 text-white',
    major: 'bg-orange-500 text-black',
    minor: 'bg-yellow-500 text-black',
    trivial: 'bg-gray-500 text-white',
};

function IssueCard({ report, dragProps = {}, moving = false }: { report: Report; dragProps?: object; moving?: boolean }) {
    const projectName = typeof report.project === 'object' ? report.project?.name : report.project;
    const PriorityIcon = priorityConfig[report.priority]?.icon || Info;
    const priorityColor = priorityConfig[report.priority]?.color || 'text-gray-600';

    const isOverdue = isIssueOverdue(report.due_date, report.status);

    return (
        <div
            {...dragProps}
            className={`bg-card rounded-lg border border-gray-200 p-3 mb-2 shadow-sm hover:shadow-md transition-shadow cursor-pointer group ${moving ? 'opacity-50' : ''}`}
            onClick={() => router.get(`/reports/${report.uuid}`)}
        >
            {/* Issue Key & Priority */}
            <div className="flex items-center justify-between mb-2">
                <div className="flex min-w-0 items-center gap-2">
                    <span className="text-xs font-mono text-gray-500 group-hover:text-blue-600 transition-colors">
                        {report.issue_key || `#${report.id}`}
                    </span>
                    <IssueTypeBadge type={report.type} />
                </div>
                <div className="flex items-center gap-1">
                    <PriorityIcon className={`w-4 h-4 ${priorityColor}`} />
                    {/* Severity belongs to bugs only */}
                    {report.type !== 'feature' && report.severity && (
                        <Badge className={`text-[10px] px-1.5 py-0 ${severityColors[report.severity]}`}>
                            {report.severity.charAt(0).toUpperCase()}
                        </Badge>
                    )}
                </div>
            </div>

            {/* Title */}
            <h4 className="text-sm font-medium text-gray-900 line-clamp-2 mb-2 group-hover:text-blue-600 transition-colors">
                {report.title}
            </h4>

            {/* Labels */}
            {report.labels && report.labels.length > 0 && (
                <div className="flex flex-wrap gap-1 mb-2">
                    {report.labels.slice(0, 3).map(label => (
                        <span
                            key={label.id}
                            className="text-[10px] px-1.5 py-0.5 rounded-full"
                            style={labelStyle(label.color)}
                        >
                            {label.name}
                        </span>
                    ))}
                    {report.labels.length > 3 && (
                        <span className="text-[10px] text-gray-500">+{report.labels.length - 3}</span>
                    )}
                </div>
            )}

            {/* Footer */}
            <div className="flex items-center justify-between text-xs text-gray-500">
                <div className="flex min-w-0 items-center gap-2">
                    {/* Due Date */}
                    {report.due_date && (
                        <div className={`flex items-center gap-0.5 ${isOverdue ? 'text-red-600 font-medium' : ''}`}>
                            <Clock className="w-3 h-3" />
                            <span>{new Date(report.due_date).toLocaleDateString('en-GB', { day: 'numeric', month: 'short' })}</span>
                        </div>
                    )}

                    {/* Created by */}
                    {report.reporter?.name && (
                        <div className="flex min-w-0 items-center gap-0.5" title={`Created by ${report.reporter.name}`}>
                            <User className="w-3 h-3 shrink-0" />
                            <span className="truncate max-w-[90px]">{report.reporter.name}</span>
                        </div>
                    )}
                </div>

                {/* Project */}
                <span className="ml-2 shrink-0 text-gray-400 truncate max-w-[80px]" title={projectName}>
                    {projectName}
                </span>
            </div>
        </div>
    );
}

function KanbanColumn({ status, reports, config, drag }: { status: IssueStatus; reports: Report[]; config: typeof statusConfig[keyof typeof statusConfig]; drag: ReturnType<typeof useIssueDrag> }) {
    return (
        <div
            {...drag.columnProps(status)}
            className={`flex flex-1 flex-col min-w-[280px] max-w-[320px] overflow-hidden rounded-[14px] transition ${config.bgColor} border ${config.borderColor} ${columnDragClass(drag.columnState(status))}`}
        >
            {/* Column Header */}
            <div className={`px-4 py-3 ${config.headerBg} border-b ${config.borderColor}`}>
                <div className="flex items-center justify-between">
                    <div className="flex items-center gap-2">
                        <div className={`w-2.5 h-2.5 rounded-full ${config.color}`} />
                        <h3 className="eyebrow">{config.label}</h3>
                    </div>
                    <Badge variant="secondary" className="text-xs font-normal">
                        {reports.length}
                    </Badge>
                </div>
            </div>

            {/* Cards */}
            <div className="p-2 space-y-2 max-h-[calc(100vh-280px)] min-h-0 flex-1 overflow-y-auto lg:max-h-none">
                {reports.length === 0 ? (
                    <div className="text-center py-8 text-sm text-gray-400">
                        No issues
                    </div>
                ) : (
                    reports.map(report => (
                        <IssueCard
                            key={report.id}
                            report={report}
                            dragProps={drag.cardProps(report)}
                            moving={drag.movingUuid === report.uuid}
                        />
                    ))
                )}
            </div>
        </div>
    );
}

export default function KanbanBoard({ reports, canDrag = false, onMoved, onlyStatus = null }: KanbanBoardProps) {
    const drag = useIssueDrag({ enabled: canDrag, onMoved: onMoved ?? (() => undefined) });

    // Group reports by status
    const groupedReports = {
        open: reports.filter(r => r.status === 'open'),
        in_progress: reports.filter(r => r.status === 'in_progress'),
        resolved: reports.filter(r => r.status === 'resolved'),
        closed: reports.filter(r => r.status === 'closed'),
        reopened: reports.filter(r => r.status === 'reopened'),
    };

    // One column per status, so reopened issues are not hidden among the open ones
    const allColumns: Array<{ status: IssueStatus; reports: Report[] }> = [
        { status: 'open', reports: groupedReports.open },
        { status: 'in_progress', reports: groupedReports.in_progress },
        { status: 'resolved', reports: groupedReports.resolved },
        { status: 'closed', reports: groupedReports.closed },
        { status: 'reopened', reports: groupedReports.reopened },
    ];
    const columns = onlyStatus ? allColumns.filter(column => column.status === onlyStatus) : allColumns;

    return (
        <div className="flex min-h-0 flex-1 flex-col">
            <div className="flex min-h-0 flex-1 gap-4 overflow-x-auto pb-4">
                {columns.map(({ status, reports }) => (
                    <KanbanColumn
                        key={status}
                        status={status}
                        reports={reports}
                        config={statusConfig[status]}
                        drag={drag}
                    />
                ))}
            </div>
        </div>
    );
}
