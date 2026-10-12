import { router } from '@inertiajs/react';
import { columnDragClass, useIssueDrag, type IssueStatus } from '@/hooks/use-issue-drag';
import { Badge } from '@/components/ui/badge';
import { User as UserIcon, Clock, ArrowUpRight, AlertTriangle, AlertCircle, Info, AlertOctagon, Gauge } from 'lucide-react';

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
    severity?: 'trivial' | 'minor' | 'major' | 'critical';
    reporter: { id: number; name: string; email: string };
    assignee?: { id: number; name: string; email: string };
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
    critical: 'bg-red-600 text-white',
    major: 'bg-orange-500 text-white',
    minor: 'bg-yellow-500 text-white',
    trivial: 'bg-gray-400 text-white',
};

// SLA targets by priority (in hours)
const SLA_TARGETS: Record<string, { response: number; resolution: number }> = {
    urgent: { response: 1, resolution: 4 },
    high: { response: 4, resolution: 24 },
    medium: { response: 8, resolution: 48 },
    low: { response: 24, resolution: 168 },
};

// Calculate SLA status for a report
function getSlaStatus(report: Report): { status: 'breached' | 'at_risk' | 'on_track' | 'met'; label: string } | null {
    // Don't show SLA for closed issues
    if (report.status === 'closed') {
        return null;
    }

    const targets = SLA_TARGETS[report.priority] || SLA_TARGETS.medium;
    const createdAt = new Date(report.created_at);
    const now = new Date();
    const resolutionDeadline = new Date(createdAt.getTime() + targets.resolution * 60 * 60 * 1000);

    // Check resolution SLA
    const minutesRemaining = (resolutionDeadline.getTime() - now.getTime()) / (60 * 1000);
    const totalMinutes = targets.resolution * 60;

    if (minutesRemaining < 0) {
        const hoursOverdue = Math.abs(Math.floor(minutesRemaining / 60));
        return { status: 'breached', label: `-${hoursOverdue}h` };
    }

    // At risk if less than 25% time remaining
    if (minutesRemaining < totalMinutes * 0.25) {
        const hoursRemaining = Math.floor(minutesRemaining / 60);
        return { status: 'at_risk', label: `${hoursRemaining}h` };
    }

    return { status: 'on_track', label: '' };
}

function IssueCard({ report, dragProps = {}, moving = false }: { report: Report; dragProps?: object; moving?: boolean }) {
    const projectName = typeof report.project === 'object' ? report.project?.name : report.project;
    const PriorityIcon = priorityConfig[report.priority]?.icon || Info;
    const priorityColor = priorityConfig[report.priority]?.color || 'text-gray-600';

    const isOverdue = report.due_date && new Date(report.due_date) < new Date() && report.status !== 'closed';
    const slaStatus = getSlaStatus(report);

    return (
        <div
            {...dragProps}
            className={`bg-white rounded-lg border border-gray-200 p-3 mb-2 shadow-sm hover:shadow-md transition-shadow cursor-pointer group ${moving ? 'opacity-50' : ''}`}
            onClick={() => router.get(`/reports/${report.uuid}`)}
        >
            {/* Issue Key & Priority */}
            <div className="flex items-center justify-between mb-2">
                <span className="text-xs font-mono text-gray-500 group-hover:text-blue-600 transition-colors">
                    {report.issue_key || `#${report.id}`}
                </span>
                <div className="flex items-center gap-1">
                    <PriorityIcon className={`w-4 h-4 ${priorityColor}`} />
                    {report.severity && (
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
                            style={{ backgroundColor: label.color + '20', color: label.color }}
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
                <div className="flex items-center gap-2">
                    {/* Assignee Avatar */}
                    {report.assignee ? (
                        <div className="flex items-center gap-1" title={report.assignee.name}>
                            <div className="w-5 h-5 rounded-full bg-gradient-to-br from-indigo-500 to-purple-600 flex items-center justify-center text-white text-[10px] font-medium">
                                {report.assignee.name.charAt(0)}
                            </div>
                        </div>
                    ) : (
                        <div className="w-5 h-5 rounded-full bg-gray-200 flex items-center justify-center">
                            <UserIcon className="w-3 h-3 text-gray-400" />
                        </div>
                    )}

                    {/* Due Date */}
                    {report.due_date && (
                        <div className={`flex items-center gap-0.5 ${isOverdue ? 'text-red-600 font-medium' : ''}`}>
                            <Clock className="w-3 h-3" />
                            <span>{new Date(report.due_date).toLocaleDateString('en-GB', { day: 'numeric', month: 'short' })}</span>
                        </div>
                    )}

                    {/* SLA Indicator */}
                    {slaStatus && slaStatus.status !== 'on_track' && (
                        <div
                            className={`flex items-center gap-0.5 px-1.5 py-0.5 rounded ${
                                slaStatus.status === 'breached'
                                    ? 'bg-red-100 text-red-700'
                                    : 'bg-amber-100 text-amber-700'
                            }`}
                            title={slaStatus.status === 'breached' ? 'SLA Breached' : 'SLA At Risk'}
                        >
                            {slaStatus.status === 'breached' ? (
                                <AlertOctagon className="w-3 h-3" />
                            ) : (
                                <Gauge className="w-3 h-3" />
                            )}
                            {slaStatus.label && <span className="text-[10px] font-medium">{slaStatus.label}</span>}
                        </div>
                    )}
                </div>

                {/* Project */}
                <span className="text-gray-400 truncate max-w-[80px]" title={projectName}>
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
            className={`flex-1 min-w-[280px] max-w-[320px] overflow-hidden rounded-[14px] transition ${config.bgColor} border ${config.borderColor} ${columnDragClass(drag.columnState(status))}`}
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
            <div className="p-2 space-y-2 max-h-[calc(100vh-280px)] overflow-y-auto">
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
        <div className="space-y-3">
            <div className="flex gap-4 overflow-x-auto pb-4">
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
