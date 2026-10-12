import { useState, useEffect, useRef } from 'react'
import { Head, usePage, router, Link } from '@inertiajs/react'
import { Button } from '@/components/ui/button'
import { Textarea } from '@/components/ui/textarea'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import {
  ArrowLeft, FileImage, User, Calendar, MessageCircle, Edit2, CheckCircle2,
  Clock, File, ExternalLink, Plus, RotateCcw, AlertCircle, Send, X,
  AlertTriangle, ArrowUpRight, Info, Paperclip, Link2, Timer, Tag, Settings,
  Bug, Sparkles, Play, Gauge, AlertOctagon, ChevronLeft, ChevronRight, Download,
  Upload, Loader2, ChevronDown, UserPlus
} from 'lucide-react'
import AppLayout from '@/layouts/app-layout'
import { getCsrfToken } from '@/lib/csrf'
import { flashError, flashSuccess } from '@/lib/flash'
import { goBack } from '@/lib/navigation'

interface ReportLabel {
  id: number
  name: string
  color: string
}

interface TimeEntry {
  id: number
  hours: number
  description: string | null
  logged_date: string
  user: { id: number; name: string; email: string }
}

interface ReportLink {
  id: number
  link_type: string
  target_report?: { id: number; uuid: string; title: string; status: string; priority: string }
  source_report?: { id: number; uuid: string; title: string; status: string; priority: string }
}

interface SlaInfo {
  targets: {
    response_hours: number
    resolution_hours: number
  }
  response: {
    status: 'breached' | 'at_risk' | 'on_track' | 'met'
    responded_at: string | null
    deadline: string
    time_remaining_minutes: number | null
    breached: boolean
  }
  resolution: {
    status: 'breached' | 'at_risk' | 'on_track' | 'met' | 'paused'
    resolved_at: string | null
    deadline: string
    time_remaining_minutes: number | null
    breached: boolean
  }
}

interface Report {
  id: number
  uuid: string
  issue_key?: string
  title: string
  description: string
  project: string | { id: number; name: string; prefix?: string; uuid?: string }
  project_id?: number
  status: 'open' | 'in_progress' | 'resolved' | 'closed' | 'reopened'
  reporter: { id: number; name: string; email: string; role: string }
  assignee?: { id: number; name: string; email: string; role: string }
  due_date?: string | null
  priority: 'low' | 'medium' | 'high' | 'urgent'
  severity?: 'critical' | 'major' | 'minor' | 'trivial'
  estimated_hours?: number | null
  total_time_spent?: number
  labels?: ReportLabel[]
  time_entries?: TimeEntry[]
  outgoing_links?: ReportLink[]
  incoming_links?: ReportLink[]
  attachments: Array<{
    id: number
    filename: string
    original_filename: string
    storage_path: string
    file_size?: number
  }>
  comments?: Array<{
    id: number
    comment: string
    user: { id: number; name: string; email: string; role: string }
    created_at: string
  }>
  activityLogs?: ActivityLog[]
  resolution_summary?: string
  sla?: SlaInfo
  created_at: string
  updated_at: string
}

interface ActivityLog {
  id: number
  action: string
  old_values?: Record<string, unknown> | null
  new_values?: Record<string, unknown> | null
  user: { id: number; name: string; email: string; role?: string }
  created_at: string
}

interface TimelineEntry {
  key: string
  user: { name: string; role?: string }
  created_at: string
  summary: string
  details: string[]
  quote?: string
}

const FIELD_LABELS: Record<string, string> = {
  title: 'Title',
  description: 'Description',
  due_date: 'Due date',
  priority: 'Priority',
  severity: 'Severity',
  estimated_hours: 'Estimated hours',
  status: 'Status'
}

const STATUS_SUMMARIES: Record<string, string> = {
  open: 'Moved the issue back to Open',
  in_progress: 'Started work on the issue',
  resolved: 'Resolved the issue',
  closed: 'Closed the issue',
  reopened: 'Reopened the issue'
}

const humanize = (value: unknown) =>
  value === null || value === undefined || value === ''
    ? 'none'
    : String(value).replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase())

const shorten = (text: string, length = 120) => (text.length > length ? `${text.slice(0, length)}…` : text)

// Dates are stored either as "2026-01-03" or as a full timestamp; compare and show the day only
const formatFieldValue = (field: string, value: unknown) => {
  if (field === 'due_date') {
    return value ? new Date(`${String(value).slice(0, 10)}T00:00:00`).toLocaleDateString() : 'none'
  }
  if (field === 'title') return value ? `"${shorten(String(value), 60)}"` : 'none'
  return humanize(value)
}

// Turn one activity record into a sentence, the changes it made, and any note left with it
const describeLog = (log: ActivityLog): Pick<TimelineEntry, 'summary' | 'details' | 'quote'> => {
  const oldValues = log.old_values ?? {}
  const newValues = log.new_values ?? {}
  const transition = oldValues.status && newValues.status && oldValues.status !== newValues.status
    ? [`Status: ${humanize(oldValues.status)} → ${humanize(newValues.status)}`]
    : []

  switch (log.action) {
    case 'status_changed':
      return {
        summary: STATUS_SUMMARIES[String(newValues.status)] ?? 'Changed the status',
        details: transition,
        quote: newValues.reason
          ? String(newValues.reason)
          : newValues.resolution_summary ? String(newValues.resolution_summary) : undefined
      }
    case 'closed':
      return { summary: 'Closed the issue', details: transition }
    case 'assigned':
      return {
        summary: `Assigned the issue to ${newValues.assignee_name ?? 'a team member'}`,
        details: oldValues.assignee_name ? [`Previously: ${oldValues.assignee_name}`] : []
      }
    case 'unassigned':
      return {
        summary: 'Removed the assignee',
        details: oldValues.assignee_name ? [`Previously: ${oldValues.assignee_name}`] : []
      }
    case 'added_attachments':
      return { summary: 'Added attachments', details: [] }
    case 'linked_report':
      return {
        summary: 'Linked another issue',
        details: [`${humanize(newValues.link_type)}: ${newValues.linked_report_title ?? 'issue'}`]
      }
    case 'unlinked_report':
      return {
        summary: 'Removed a link to another issue',
        details: [`${humanize(oldValues.link_type)}: ${oldValues.linked_report_title ?? 'issue'}`]
      }
    case 'logged_time':
      return {
        summary: `Logged ${newValues.hours ?? 0}h of work`,
        details: [],
        quote: newValues.description ? String(newValues.description) : undefined
      }
    case 'updated_time':
      return {
        summary: 'Updated a time entry',
        details: [`Hours: ${oldValues.hours ?? 'none'} → ${newValues.hours ?? 'none'}`]
      }
    case 'deleted_time':
      return { summary: `Deleted a time entry (${oldValues.hours ?? 0}h)`, details: [] }
    case 'updated': {
      const details: string[] = []
      Object.keys(FIELD_LABELS).forEach(field => {
        if (!(field in newValues)) return
        const before = field === 'due_date' ? String(oldValues[field] ?? '').slice(0, 10) : oldValues[field] ?? ''
        const after = field === 'due_date' ? String(newValues[field] ?? '').slice(0, 10) : newValues[field] ?? ''
        if (String(before) === String(after)) return

        details.push(
          field === 'description'
            ? `Description changed to: ${shorten(String(after))}`
            : `${FIELD_LABELS[field]}: ${formatFieldValue(field, before)} → ${formatFieldValue(field, after)}`
        )
      })
      return {
        summary: details.length > 0 ? 'Edited the issue' : 'Saved the issue without changes',
        details
      }
    }
    default:
      return { summary: humanize(log.action), details: transition }
  }
}

interface PageProps extends Record<string, unknown> {
  report: Report
  userRole: 'client' | 'admin' | 'super_admin' | 'manager' | 'developer'
  userId: number
  canReview: boolean
}

const statusConfig = {
  open: { label: 'Open', bg: 'bg-blue-100', text: 'text-blue-700', dot: 'bg-blue-500', iconBg: 'bg-blue-50', iconColor: 'text-blue-600' },
  in_progress: { label: 'In Progress', bg: 'bg-amber-100', text: 'text-amber-700', dot: 'bg-amber-500', iconBg: 'bg-amber-50', iconColor: 'text-amber-600' },
  resolved: { label: 'Resolved', bg: 'bg-purple-100', text: 'text-purple-700', dot: 'bg-purple-500', iconBg: 'bg-purple-50', iconColor: 'text-purple-600' },
  closed: { label: 'Closed', bg: 'bg-green-100', text: 'text-green-700', dot: 'bg-green-500', iconBg: 'bg-green-50', iconColor: 'text-green-600' },
  reopened: { label: 'Reopened', bg: 'bg-red-100', text: 'text-red-700', dot: 'bg-red-500', iconBg: 'bg-red-50', iconColor: 'text-red-600' },
}

const priorityConfig = {
  urgent: { label: 'Urgent', icon: AlertTriangle, color: 'text-red-600', bg: 'bg-red-50', badgeBg: 'bg-red-100', badgeText: 'text-red-700' },
  high: { label: 'High', icon: ArrowUpRight, color: 'text-orange-600', bg: 'bg-orange-50', badgeBg: 'bg-orange-100', badgeText: 'text-orange-700' },
  medium: { label: 'Medium', icon: AlertCircle, color: 'text-yellow-600', bg: 'bg-yellow-50', badgeBg: 'bg-yellow-100', badgeText: 'text-yellow-700' },
  low: { label: 'Low', icon: Info, color: 'text-blue-600', bg: 'bg-blue-50', badgeBg: 'bg-blue-100', badgeText: 'text-blue-700' },
}

const severityConfig = {
  critical: { label: 'Critical', bg: 'bg-red-600', text: 'text-white' },
  major: { label: 'Major', bg: 'bg-orange-500', text: 'text-white' },
  minor: { label: 'Minor', bg: 'bg-yellow-500', text: 'text-white' },
  trivial: { label: 'Trivial', bg: 'bg-gray-400', text: 'text-white' },
}

const roleColors: Record<string, string> = {
  super_admin: 'bg-gradient-to-br from-red-500 to-rose-600',
  admin: 'bg-gradient-to-br from-blue-500 to-indigo-600',
  manager: 'bg-gradient-to-br from-purple-500 to-violet-600',
  developer: 'bg-gradient-to-br from-emerald-500 to-teal-600',
  client: 'bg-gradient-to-br from-amber-500 to-orange-600',
}

const roleBadgeColors: Record<string, string> = {
  super_admin: 'bg-red-100 text-red-700',
  admin: 'bg-blue-100 text-blue-700',
  manager: 'bg-purple-100 text-purple-700',
  developer: 'bg-emerald-100 text-emerald-700',
  client: 'bg-amber-100 text-amber-700',
}

const slaStatusConfig = {
  breached: { label: 'Breached', bg: 'bg-red-100', text: 'text-red-700', icon: AlertOctagon, iconColor: 'text-red-600' },
  at_risk: { label: 'At Risk', bg: 'bg-amber-100', text: 'text-amber-700', icon: AlertTriangle, iconColor: 'text-amber-600' },
  on_track: { label: 'On Track', bg: 'bg-green-100', text: 'text-green-700', icon: CheckCircle2, iconColor: 'text-green-600' },
  met: { label: 'Met', bg: 'bg-green-100', text: 'text-green-700', icon: CheckCircle2, iconColor: 'text-green-600' },
  paused: { label: 'Paused', bg: 'bg-gray-100', text: 'text-gray-700', icon: Clock, iconColor: 'text-gray-600' },
}

// Format time remaining
function formatTimeRemaining(minutes: number | null): string {
  if (minutes === null) return '-'

  const absMinutes = Math.abs(minutes)
  const isOverdue = minutes < 0

  if (absMinutes < 60) {
    return `${isOverdue ? '-' : ''}${absMinutes}m`
  }

  const hours = Math.floor(absMinutes / 60)
  const mins = absMinutes % 60

  if (hours < 24) {
    return `${isOverdue ? '-' : ''}${hours}h ${mins}m`
  }

  const days = Math.floor(hours / 24)
  const remainingHours = hours % 24
  return `${isOverdue ? '-' : ''}${days}d ${remainingHours}h`
}

export default function ReportShow() {
  const { report: initialReport, userRole, userId, canReview } = usePage<PageProps>().props
  const [report, setReport] = useState<Report>(initialReport)
  const [loading, setLoading] = useState(false)
  const [newComment, setNewComment] = useState('')
  // Opened from an edit link elsewhere (?edit=1): start in edit mode
  const [isEditing, setIsEditing] = useState(
    () => typeof window !== 'undefined' && new URLSearchParams(window.location.search).get('edit') === '1'
  )
  const [showResolveModal, setShowResolveModal] = useState(false)
  const [showReopenModal, setShowReopenModal] = useState(false)
  const [resolutionSummary, setResolutionSummary] = useState('')
  const [reopenReason, setReopenReason] = useState('')
  const [editData, setEditData] = useState({
    title: report.title,
    description: report.description,
    priority: report.priority,
    severity: report.severity || 'minor',
    due_date: report.due_date || '',
  })
  const [lightboxOpen, setLightboxOpen] = useState(false)
  const [lightboxIndex, setLightboxIndex] = useState(0)
  const [uploading, setUploading] = useState(false)
  const [teamMembers, setTeamMembers] = useState<Array<{id: number; name: string; email: string; role: string}>>([])
  const [showAssigneeDropdown, setShowAssigneeDropdown] = useState(false)
  const [assigningUser, setAssigningUser] = useState(false)
  const fileInputRef = useRef<HTMLInputElement>(null)
  const assigneeDropdownRef = useRef<HTMLDivElement>(null)

  // Get image attachments for lightbox
  const imageAttachments = report.attachments.filter(a => /\.(jpg|jpeg|png|gif|webp)$/i.test(a.original_filename))

  // Keyboard navigation for lightbox
  useEffect(() => {
    if (!lightboxOpen) return

    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.key === 'ArrowLeft') {
        setLightboxIndex((prev) => (prev === 0 ? imageAttachments.length - 1 : prev - 1))
      } else if (e.key === 'ArrowRight') {
        setLightboxIndex((prev) => (prev === imageAttachments.length - 1 ? 0 : prev + 1))
      } else if (e.key === 'Escape') {
        setLightboxOpen(false)
      }
    }

    window.addEventListener('keydown', handleKeyDown)
    return () => window.removeEventListener('keydown', handleKeyDown)
  }, [lightboxOpen, imageAttachments.length])

  const canAssign = userRole === 'admin' || userRole === 'super_admin' || userRole === 'manager'

  // Fetch team members for assignment
  useEffect(() => {
    const fetchTeamMembers = async () => {
      try {
        const response = await fetch('/api/team-members', { credentials: 'same-origin' })
        if (response.ok) {
          const data = await response.json()
          setTeamMembers(data)
        }
      } catch (error) {
        console.error('Failed to fetch team members:', error)
      }
    }
    if (canAssign) {
      fetchTeamMembers()
    }
  }, [canAssign])

  // Close assignee dropdown when clicking outside
  useEffect(() => {
    const handleClickOutside = (e: MouseEvent) => {
      if (assigneeDropdownRef.current && !assigneeDropdownRef.current.contains(e.target as Node)) {
        setShowAssigneeDropdown(false)
      }
    }
    if (showAssigneeDropdown) {
      document.addEventListener('mousedown', handleClickOutside)
      return () => document.removeEventListener('mousedown', handleClickOutside)
    }
  }, [showAssigneeDropdown])

  const handleAssign = async (userId: number | null) => {
    setAssigningUser(true)
    try {
      await handleAction('assign', { assignee_id: userId })
      setShowAssigneeDropdown(false)
    } finally {
      setAssigningUser(false)
    }
  }

  const projectName = typeof report.project === 'object' ? report.project?.name : report.project
  const projectUuid = typeof report.project === 'object' ? report.project?.uuid : null
  const projectPrefix = typeof report.project === 'object' ? report.project?.prefix : null
  const status = statusConfig[report.status] || statusConfig.open
  const priority = priorityConfig[report.priority] || priorityConfig.medium
  const severity = severityConfig[report.severity || 'minor']
  const PriorityIcon = priority.icon

  // Permissions
  const isTeamMember = ['admin', 'super_admin', 'manager', 'developer'].includes(userRole)
  const isReporter = Number(report.reporter.id) === Number(userId)
  const canStartProgress = isTeamMember && (report.status === 'open' || report.status === 'reopened')
  const canResolve = isTeamMember && report.status === 'in_progress'
  // Resolved means "waiting for the client": a reviewing client confirms the fix (closes it) or rejects it (reopens it)
  const awaitingReview = report.status === 'resolved'
  const canConfirmFix = awaitingReview && canReview
  const canClose = (report.status === 'resolved' && isTeamMember) || (report.status === 'open' && (isTeamMember || (userRole === 'client' && isReporter)))
  const canReopen = (report.status === 'closed' && (isTeamMember || canReview)) || (report.status === 'resolved' && isTeamMember)
  const canEdit = isTeamMember || (userRole === 'client' && isReporter && report.status === 'open')
  const canComment = isTeamMember || (userRole === 'client' && isReporter)

  const handleAction = async (action: string, data?: Record<string, any>) => {
    setLoading(true)
    try {
      const tokenResponse = await fetch('/api/csrf-token', { credentials: 'same-origin' })
      let csrfToken = ''
      if (tokenResponse.ok) {
        const tokenData = await tokenResponse.json()
        csrfToken = tokenData.token
      } else {
        csrfToken = getCsrfToken() || ''
      }

      const response = await fetch(`/api/reports/${report.uuid}`, {
        method: 'PATCH',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': csrfToken,
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest'
        },
        credentials: 'same-origin',
        body: JSON.stringify({ action, ...data })
      })

      const result = await response.json()
      if (response.ok) {
        flashSuccess(result.message, 'Issue updated successfully.')
        setReport(result.report)
        setIsEditing(false)
        setShowResolveModal(false)
        setShowReopenModal(false)
        setResolutionSummary('')
        setReopenReason('')
        setEditData({
          title: result.report.title,
          description: result.report.description,
          priority: result.report.priority,
          severity: result.report.severity || 'minor',
          due_date: result.report.due_date || '',
        })
      } else {
        flashError(result.message || 'Action failed')
      }
    } catch (error) {
      flashError('Network error. Please try again.')
    } finally {
      setLoading(false)
    }
  }

  const handleAddComment = async () => {
    if (!newComment.trim()) return
    await handleAction('add_comment', { comment: newComment })
    setNewComment('')
  }

  const handleUploadAttachments = async (files: FileList | null) => {
    if (!files || files.length === 0) return

    setUploading(true)
    try {
      const tokenResponse = await fetch('/api/csrf-token', { credentials: 'same-origin' })
      let csrfToken = ''
      if (tokenResponse.ok) {
        const tokenData = await tokenResponse.json()
        csrfToken = tokenData.token
      } else {
        csrfToken = getCsrfToken() || ''
      }

      const formData = new FormData()
      formData.append('action', 'add_attachments')
      Array.from(files).forEach((file) => {
        formData.append('attachments[]', file)
      })

      const response = await fetch(`/api/reports/${report.uuid}`, {
        method: 'POST',
        headers: {
          'X-CSRF-TOKEN': csrfToken,
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
          'X-HTTP-Method-Override': 'PATCH'
        },
        credentials: 'same-origin',
        body: formData
      })

      const result = await response.json()
      if (response.ok) {
        flashSuccess(result.message, 'Attachments uploaded successfully.')
        setReport(result.report)
      } else {
        flashError(result.message || 'Failed to upload attachments')
      }
    } catch (error) {
      flashError('Network error. Please try again.')
    } finally {
      setUploading(false)
      if (fileInputRef.current) {
        fileInputRef.current.value = ''
      }
    }
  }

  const formatDate = (date: string) => new Date(date).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' })
  const timeAgo = (date: string) => {
    const seconds = Math.floor((new Date().getTime() - new Date(date).getTime()) / 1000)
    if (seconds < 60) return 'just now'
    const minutes = Math.floor(seconds / 60)
    if (minutes < 60) return `${minutes}m ago`
    const hours = Math.floor(minutes / 60)
    if (hours < 24) return `${hours}h ago`
    const days = Math.floor(hours / 24)
    if (days < 7) return `${days}d ago`
    return formatDate(date)
  }

  const isOverdue = report.due_date && new Date(report.due_date) < new Date() && report.status !== 'closed'

  // Everything that happened to this issue, newest first: creation, status changes, edits and comments
  const activityLogs = report.activityLogs ?? []
  const quotedReasons = new Set(
    activityLogs.filter(log => log.new_values?.reason).map(log => String(log.new_values?.reason))
  )
  const timeline: TimelineEntry[] = [
    ...activityLogs.map(log => ({
      key: `log-${log.id}`,
      user: log.user,
      created_at: log.created_at,
      ...describeLog(log)
    })),
    // A reopen reason is also saved as a comment; it is already shown on the reopen itself
    ...(report.comments ?? [])
      .filter(item => !quotedReasons.has(item.comment))
      .map(item => ({
        key: `comment-${item.id}`,
        user: item.user,
        created_at: item.created_at,
        summary: 'Added a comment',
        details: [],
        quote: item.comment
      })),
    {
      key: 'created',
      user: report.reporter,
      created_at: report.created_at,
      summary: 'Created the issue',
      details: typeof report.project === 'object' && report.project?.name ? [`Project: ${report.project.name}`] : []
    }
  ].sort((a, b) => new Date(b.created_at).getTime() - new Date(a.created_at).getTime())

  return (
    <AppLayout>
      <Head title={report.issue_key ? `${report.issue_key} - ${report.title}` : report.title} />

      <div className="space-y-6">
        {/* Header */}
        <div className="flex items-start justify-between">
          <div className="flex items-center gap-4">
            <Button variant="outline" size="sm" onClick={() => goBack('/dashboard')}>
              <ArrowLeft className="h-4 w-4 mr-2" />
              Back
            </Button>
            <div>
              <div className="flex items-center gap-3">
                {isEditing ? (
                  <Input
                    value={editData.title}
                    onChange={(e) => setEditData(prev => ({ ...prev, title: e.target.value }))}
                    className="text-2xl font-semibold h-auto py-1 px-2 -ml-2 w-[400px]"
                  />
                ) : (
                  <h1 className="text-2xl font-semibold tracking-tight">{report.title}</h1>
                )}
                <span className="text-sm font-mono text-gray-400 bg-gray-100 px-2 py-0.5 rounded">
                  {report.issue_key || `#${report.id}`}
                </span>
                <span className={`inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium ${status.bg} ${status.text}`}>
                  <span className={`w-1.5 h-1.5 rounded-full ${status.dot} ${report.status === 'in_progress' ? 'animate-pulse' : ''}`} />
                  {status.label}
                </span>
                {/* Labels as tags */}
                {report.labels && report.labels.length > 0 && report.labels.map((label) => (
                  <span
                    key={label.id}
                    className="px-2.5 py-1 rounded-full text-xs font-medium"
                    style={{ backgroundColor: label.color + '20', color: label.color }}
                  >
                    {label.name}
                  </span>
                ))}
              </div>
              <div className="flex items-center gap-2 mt-1 text-sm text-muted-foreground">
                {projectUuid ? (
                  <Link href={`/projects/view/${projectUuid}`} className="hover:text-blue-600 hover:underline">
                    {projectName}
                  </Link>
                ) : (
                  <span>{projectName}</span>
                )}
                <span>•</span>
                <span>Created {formatDate(report.created_at)}</span>
              </div>
            </div>
          </div>
          <div className="flex gap-2">
            {canStartProgress && (
              <Button onClick={() => handleAction('start_progress')} disabled={loading}>
                <Play className="w-4 h-4 mr-2" />
                Start Progress
              </Button>
            )}
            {canResolve && (
              <Button onClick={() => setShowResolveModal(true)} disabled={loading}>
                <CheckCircle2 className="w-4 h-4 mr-2" />
                Resolve
              </Button>
            )}
            {canClose && (
              <Button onClick={() => handleAction('close')} disabled={loading} variant="outline" className="bg-white">
                <CheckCircle2 className="w-4 h-4 mr-2" />
                Close
              </Button>
            )}
            {canReopen && (
              <Button onClick={() => setShowReopenModal(true)} disabled={loading} variant="outline" className="border-red-200 bg-white text-red-700 hover:bg-red-50 hover:text-red-800">
                <RotateCcw className="w-4 h-4 mr-2" />
                Reopen
              </Button>
            )}
            {canEdit && (
              <Button variant="outline" onClick={() => setIsEditing(!isEditing)}>
                {isEditing ? <X className="w-4 h-4" /> : <Edit2 className="w-4 h-4" />}
              </Button>
            )}
          </div>
        </div>

        {/* Review step: a resolved issue is not closed until the client confirms the fix */}
        {canConfirmFix && (
          <div className="rounded-xl border border-purple-200 bg-purple-50 p-5">
            <div className="flex flex-wrap items-center justify-between gap-4">
              <div className="min-w-0">
                <h2 className="text-base font-semibold text-purple-900">This issue has been resolved</h2>
                <p className="mt-1 text-sm text-purple-800">
                  Please review and confirm whether the issue is fixed.
                </p>
                {report.resolution_summary && (
                  <p className="mt-2 text-sm text-purple-900">
                    <span className="font-medium">Resolution:</span> {report.resolution_summary}
                  </p>
                )}
              </div>
              <div className="flex shrink-0 gap-2">
                <Button onClick={() => handleAction('close')} disabled={loading}>
                  <CheckCircle2 className="w-4 h-4 mr-2" />
                  Confirm Fixed
                </Button>
                <Button onClick={() => setShowReopenModal(true)} disabled={loading} variant="outline" className="border-red-200 bg-white text-red-700 hover:bg-red-50 hover:text-red-800">
                  <RotateCcw className="w-4 h-4 mr-2" />
                  Not Fixed
                </Button>
              </div>
            </div>
          </div>
        )}
        {awaitingReview && !canConfirmFix && (
          <div className="rounded-xl border border-purple-200 bg-purple-50 px-5 py-3 text-sm text-purple-800">
            Resolved and waiting for the client to review. It will be closed once the client confirms the fix.
          </div>
        )}

        {/* Stats */}
        <div className="grid grid-cols-4 gap-4">
          <div className="bg-white border rounded-xl p-4">
            <div className="flex items-center gap-3">
              <div className={`p-2.5 rounded-lg ${priority.bg}`}>
                <PriorityIcon className={`w-5 h-5 ${priority.color}`} />
              </div>
              <div>
                <p className="text-sm text-gray-500">Priority</p>
                <p className="text-lg font-semibold text-gray-900">{priority.label}</p>
              </div>
            </div>
          </div>
          <div className="bg-white border rounded-xl p-4">
            <div className="flex items-center gap-3">
              <div className={`p-2.5 rounded-lg ${severity.bg}`}>
                <AlertTriangle className={`w-5 h-5 ${severity.text}`} />
              </div>
              <div>
                <p className="text-sm text-gray-500">Severity</p>
                <p className="text-lg font-semibold text-gray-900">{severity.label}</p>
              </div>
            </div>
          </div>
          <div className="bg-white border rounded-xl p-4">
            <div className="flex items-center gap-3">
              <div className={`p-2.5 rounded-lg ${isOverdue ? 'bg-red-50' : 'bg-gray-50'}`}>
                <Calendar className={`w-5 h-5 ${isOverdue ? 'text-red-600' : 'text-gray-600'}`} />
              </div>
              <div>
                <p className="text-sm text-gray-500">Due Date</p>
                <p className={`text-lg font-semibold ${isOverdue ? 'text-red-600' : 'text-gray-900'}`}>
                  {report.due_date ? formatDate(report.due_date) : 'Not set'}
                </p>
              </div>
            </div>
          </div>
          <div className="bg-white border rounded-xl p-4">
            <div className="flex items-center gap-3">
              <div className="p-2.5 rounded-lg bg-indigo-50">
                <Timer className="w-5 h-5 text-indigo-600" />
              </div>
              <div>
                <p className="text-sm text-gray-500">Time Tracked</p>
                <p className="text-lg font-semibold text-gray-900">
                  {report.total_time_spent || 0}h
                  {report.estimated_hours && <span className="text-sm font-normal text-gray-400"> / {report.estimated_hours}h</span>}
                </p>
              </div>
            </div>
          </div>
        </div>

        {/* Main Content */}
        <div className="grid grid-cols-1 lg:grid-cols-4 gap-6">
          {/* Left Column - Main Content */}
          <div className="lg:col-span-3 space-y-6">
            {/* Description */}
            <div className="bg-white border rounded-xl overflow-hidden">
              <div className="px-5 py-4 border-b flex items-center justify-between">
                <h2 className="font-medium text-gray-900">Description</h2>
                {isEditing && (
                  <span className="text-xs text-amber-600 bg-amber-50 px-2 py-1 rounded">Editing</span>
                )}
              </div>
              <div className="p-5">
                {isEditing ? (
                  <div className="space-y-4">
                    <Textarea
                      value={editData.description}
                      onChange={(e) => setEditData(prev => ({ ...prev, description: e.target.value }))}
                      rows={8}
                      className="font-mono text-sm"
                    />
                    <div className="flex gap-2">
                      <Button onClick={() => handleAction('update_report', editData)} disabled={loading}>
                        {loading ? 'Saving...' : 'Save Changes'}
                      </Button>
                      <Button variant="outline" onClick={() => {
                        setIsEditing(false)
                        setEditData({
                          title: report.title,
                          description: report.description,
                          priority: report.priority,
                          severity: report.severity || 'minor',
                          due_date: report.due_date || '',
                        })
                      }}>
                        Cancel
                      </Button>
                    </div>
                  </div>
                ) : (
                  <div className="prose prose-sm max-w-none">
                    <p className="whitespace-pre-wrap text-gray-700 leading-relaxed">{report.description}</p>
                  </div>
                )}
              </div>
            </div>

            {/* Resolution Summary */}
            {report.resolution_summary && (
              <div className="bg-green-50 border border-green-200 rounded-xl overflow-hidden">
                <div className="px-5 py-4 border-b border-green-200 flex items-center gap-2">
                  <CheckCircle2 className="w-4 h-4 text-green-600" />
                  <h2 className="font-medium text-green-800">Resolution</h2>
                </div>
                <div className="p-5">
                  <p className="text-sm text-green-900 whitespace-pre-wrap">{report.resolution_summary}</p>
                </div>
              </div>
            )}

            {/* Attachments */}
            <div className="bg-white border rounded-xl overflow-hidden">
              <div className="px-5 py-4 border-b flex items-center justify-between">
                <div className="flex items-center gap-2">
                  <Paperclip className="w-4 h-4 text-gray-400" />
                  <h2 className="font-medium text-gray-900">Attachments</h2>
                  {report.attachments.length > 0 && (
                    <span className="text-xs text-gray-400 ml-1">{report.attachments.length}</span>
                  )}
                </div>
                {canEdit && (
                  <>
                    <input
                      type="file"
                      ref={fileInputRef}
                      onChange={(e) => handleUploadAttachments(e.target.files)}
                      accept="image/jpeg,image/png,image/gif,image/webp,application/pdf"
                      multiple
                      className="hidden"
                    />
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={() => fileInputRef.current?.click()}
                      disabled={uploading}
                      className="h-8"
                    >
                      {uploading ? (
                        <>
                          <Loader2 className="w-3.5 h-3.5 mr-1.5 animate-spin" />
                          Uploading...
                        </>
                      ) : (
                        <>
                          <Upload className="w-3.5 h-3.5 mr-1.5" />
                          Add Files
                        </>
                      )}
                    </Button>
                  </>
                )}
              </div>
              <div className="p-5">
                {report.attachments.length === 0 ? (
                  <div className="text-center py-6">
                    <Paperclip className="w-8 h-8 text-gray-200 mx-auto mb-2" />
                    <p className="text-sm text-gray-400">No attachments yet</p>
                    {canEdit && (
                      <p className="text-xs text-gray-400 mt-1">Click "Add Files" to upload</p>
                    )}
                  </div>
                ) : (
                  <>
                    {/* Image attachments - horizontal scroll with previews */}
                    {imageAttachments.length > 0 && (
                      <div className="mb-4">
                        <div className="flex gap-3 overflow-x-auto pb-2 scrollbar-thin scrollbar-thumb-gray-300">
                          {imageAttachments.map((attachment, index) => (
                            <button
                              key={attachment.id}
                              onClick={() => {
                                setLightboxIndex(index)
                                setLightboxOpen(true)
                              }}
                              className="flex-shrink-0 group relative rounded-lg overflow-hidden border-2 border-transparent hover:border-blue-500 transition-all focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2"
                            >
                              <img
                                src={`/storage/${attachment.storage_path}`}
                                alt={attachment.original_filename}
                                className="w-32 h-24 object-cover"
                              />
                              <div className="absolute inset-0 bg-black/0 group-hover:bg-black/20 transition-colors flex items-center justify-center">
                                <div className="opacity-0 group-hover:opacity-100 transition-opacity bg-white/90 rounded-full p-2">
                                  <ExternalLink className="w-4 h-4 text-gray-700" />
                                </div>
                              </div>
                            </button>
                          ))}
                        </div>
                      </div>
                    )}

                    {/* Non-image attachments */}
                    {report.attachments.filter(a => !/\.(jpg|jpeg|png|gif|webp)$/i.test(a.original_filename)).length > 0 && (
                      <div className="space-y-2">
                        {report.attachments
                          .filter(a => !/\.(jpg|jpeg|png|gif|webp)$/i.test(a.original_filename))
                          .map((attachment) => (
                            <a
                              key={attachment.id}
                              href={`/storage/${attachment.storage_path}`}
                              target="_blank"
                              rel="noopener noreferrer"
                              className="flex items-center gap-3 p-3 bg-gray-50 rounded-lg border hover:bg-gray-100 hover:border-gray-300 transition-all group"
                            >
                              <File className="w-5 h-5 text-red-500" />
                              <span className="text-sm text-gray-700 truncate flex-1 group-hover:text-blue-600">
                                {attachment.original_filename}
                              </span>
                              <Download className="w-4 h-4 text-gray-400 group-hover:text-gray-600" />
                            </a>
                          ))}
                      </div>
                    )}
                  </>
                )}
              </div>
            </div>

            {/* Comments */}
            <div className="bg-white border rounded-xl overflow-hidden">
              <div className="px-5 py-4 border-b flex items-center gap-2">
                <MessageCircle className="w-4 h-4 text-gray-400" />
                <h2 className="font-medium text-gray-900">Comments</h2>
                <span className="text-xs text-gray-400 ml-1">{report.comments?.length || 0}</span>
              </div>
              <div className="p-5">
                {report.comments && report.comments.length > 0 && (
                  <div className="space-y-4 mb-6">
                    {report.comments.map((comment) => (
                      <div key={comment.id} className="flex gap-3">
                        <div className={`w-8 h-8 rounded-full ${roleColors[comment.user.role] || roleColors.client} flex items-center justify-center text-white text-xs font-medium flex-shrink-0`}>
                          {comment.user.name.charAt(0)}
                        </div>
                        <div className="flex-1 bg-gray-50 rounded-lg p-3">
                          <div className="flex items-center gap-2 mb-1">
                            <span className="font-medium text-sm text-gray-900">{comment.user.name}</span>
                            <span className={`text-[10px] px-1.5 py-0.5 rounded ${roleBadgeColors[comment.user.role] || roleBadgeColors.client}`}>
                              {(comment.user.role || 'client').replace('_', ' ')}
                            </span>
                            <span className="text-xs text-gray-400">{timeAgo(comment.created_at)}</span>
                          </div>
                          <p className="text-sm text-gray-700 whitespace-pre-wrap">{comment.comment}</p>
                        </div>
                      </div>
                    ))}
                  </div>
                )}

                {canComment && (
                  <div className="flex gap-3">
                    <div className={`w-8 h-8 rounded-full ${roleColors[userRole] || roleColors.client} flex items-center justify-center text-white text-xs font-medium flex-shrink-0`}>
                      {userRole.charAt(0).toUpperCase()}
                    </div>
                    <div className="flex-1">
                      <Textarea
                        value={newComment}
                        onChange={(e) => setNewComment(e.target.value)}
                        placeholder="Add a comment..."
                        rows={2}
                        className="resize-none mb-2"
                      />
                      <div className="flex justify-end">
                        <Button size="sm" onClick={handleAddComment} disabled={loading || !newComment.trim()}>
                          <Send className="w-4 h-4 mr-1" />
                          Comment
                        </Button>
                      </div>
                    </div>
                  </div>
                )}

                {!canComment && (!report.comments || report.comments.length === 0) && (
                  <div className="text-center py-8 text-gray-400">
                    <MessageCircle className="w-8 h-8 mx-auto mb-2 opacity-50" />
                    <p className="text-sm">No comments yet</p>
                  </div>
                )}
              </div>
            </div>
          </div>

          {/* Sidebar */}
          <div className="space-y-6">
            {/* People */}
            <div className="bg-white border rounded-xl p-5">
              <h3 className="text-sm font-medium text-gray-500 uppercase tracking-wider mb-4">People</h3>
              <div className="space-y-4">
                <div>
                  <p className="text-xs text-gray-400 mb-2">Assignee</p>
                  <div className="relative" ref={assigneeDropdownRef}>
                    {canAssign ? (
                      <button
                        onClick={() => setShowAssigneeDropdown(!showAssigneeDropdown)}
                        disabled={assigningUser}
                        className="w-full flex items-center gap-3 p-2 -m-2 rounded-lg hover:bg-gray-50 transition-colors text-left"
                      >
                        {report.assignee ? (
                          <>
                            <div className={`w-8 h-8 rounded-full ${roleColors[report.assignee.role] || roleColors.client} flex items-center justify-center text-white text-xs font-medium`}>
                              {report.assignee.name.charAt(0)}
                            </div>
                            <div className="flex-1 min-w-0">
                              <p className="text-sm font-medium text-gray-900 truncate">{report.assignee.name}</p>
                              <p className="text-xs text-gray-400 truncate">{report.assignee.email}</p>
                            </div>
                          </>
                        ) : (
                          <>
                            <div className="w-8 h-8 rounded-full bg-gray-100 flex items-center justify-center">
                              <UserPlus className="w-4 h-4 text-gray-400" />
                            </div>
                            <span className="text-sm text-gray-500 flex-1">Click to assign</span>
                          </>
                        )}
                        {assigningUser ? (
                          <Loader2 className="w-4 h-4 text-gray-400 animate-spin" />
                        ) : (
                          <ChevronDown className={`w-4 h-4 text-gray-400 transition-transform ${showAssigneeDropdown ? 'rotate-180' : ''}`} />
                        )}
                      </button>
                    ) : (
                      report.assignee ? (
                        <div className="flex items-center gap-3">
                          <div className={`w-8 h-8 rounded-full ${roleColors[report.assignee.role] || roleColors.client} flex items-center justify-center text-white text-xs font-medium`}>
                            {report.assignee.name.charAt(0)}
                          </div>
                          <div className="flex-1 min-w-0">
                            <p className="text-sm font-medium text-gray-900 truncate">{report.assignee.name}</p>
                            <p className="text-xs text-gray-400 truncate">{report.assignee.email}</p>
                          </div>
                        </div>
                      ) : (
                        <div className="flex items-center gap-3">
                          <div className="w-8 h-8 rounded-full bg-gray-100 flex items-center justify-center">
                            <User className="w-4 h-4 text-gray-400" />
                          </div>
                          <span className="text-sm text-gray-400">Unassigned</span>
                        </div>
                      )
                    )}

                    {/* Assignee Dropdown */}
                    {showAssigneeDropdown && (
                      <div className="absolute top-full left-0 right-0 mt-1 bg-white border rounded-lg shadow-lg z-20 max-h-64 overflow-y-auto">
                        {/* Unassign option */}
                        {report.assignee && (
                          <button
                            onClick={() => handleAssign(null)}
                            className="w-full flex items-center gap-3 p-3 hover:bg-gray-50 transition-colors border-b"
                          >
                            <div className="w-7 h-7 rounded-full bg-gray-100 flex items-center justify-center">
                              <X className="w-3.5 h-3.5 text-gray-400" />
                            </div>
                            <span className="text-sm text-gray-600">Unassign</span>
                          </button>
                        )}
                        {/* Team members */}
                        {teamMembers.map((member) => (
                          <button
                            key={member.id}
                            onClick={() => handleAssign(member.id)}
                            disabled={report.assignee?.id === member.id}
                            className={`w-full flex items-center gap-3 p-3 hover:bg-gray-50 transition-colors ${
                              report.assignee?.id === member.id ? 'bg-blue-50' : ''
                            }`}
                          >
                            <div className={`w-7 h-7 rounded-full ${roleColors[member.role] || roleColors.client} flex items-center justify-center text-white text-xs font-medium`}>
                              {member.name.charAt(0)}
                            </div>
                            <div className="flex-1 min-w-0 text-left">
                              <p className="text-sm font-medium text-gray-900 truncate">{member.name}</p>
                              <p className="text-xs text-gray-400 truncate">{member.role.replace('_', ' ')}</p>
                            </div>
                            {report.assignee?.id === member.id && (
                              <CheckCircle2 className="w-4 h-4 text-blue-500" />
                            )}
                          </button>
                        ))}
                        {teamMembers.length === 0 && (
                          <div className="p-4 text-center text-sm text-gray-400">
                            No team members available
                          </div>
                        )}
                      </div>
                    )}
                  </div>
                </div>
                <div>
                  <p className="text-xs text-gray-400 mb-2">Reporter</p>
                  <div className="flex items-center gap-3">
                    <div className={`w-8 h-8 rounded-full ${roleColors[report.reporter.role] || roleColors.client} flex items-center justify-center text-white text-xs font-medium`}>
                      {report.reporter.name.charAt(0)}
                    </div>
                    <div className="flex-1 min-w-0">
                      <p className="text-sm font-medium text-gray-900 truncate">{report.reporter.name}</p>
                      <p className="text-xs text-gray-400 truncate">{report.reporter.email}</p>
                    </div>
                    <span className={`text-[10px] px-1.5 py-0.5 rounded ${roleBadgeColors[report.reporter.role] || roleBadgeColors.client}`}>
                      {(report.reporter.role || 'client').replace('_', ' ')}
                    </span>
                  </div>
                </div>
              </div>
            </div>

            {/* SLA */}
            {report.sla && (
              <div className="bg-white border rounded-xl p-5">
                <div className="flex items-center gap-2 mb-4">
                  <Gauge className="w-4 h-4 text-gray-400" />
                  <h3 className="text-sm font-medium text-gray-500 uppercase tracking-wider">SLA</h3>
                </div>
                <div className="space-y-4">
                  {/* Response SLA */}
                  <div>
                    <div className="flex items-center justify-between mb-2">
                      <p className="text-xs text-gray-500">First Response</p>
                      <span className="text-xs text-gray-400">{report.sla.targets.response_hours}h target</span>
                    </div>
                    {(() => {
                      const config = slaStatusConfig[report.sla.response.status] || slaStatusConfig.on_track
                      const Icon = config.icon
                      return (
                        <div className={`flex items-center justify-between p-2.5 rounded-lg ${config.bg}`}>
                          <div className="flex items-center gap-2">
                            <Icon className={`w-4 h-4 ${config.iconColor}`} />
                            <span className={`text-sm font-medium ${config.text}`}>{config.label}</span>
                          </div>
                          {report.sla.response.time_remaining_minutes !== null && (
                            <span className={`text-xs font-mono ${config.text}`}>
                              {formatTimeRemaining(report.sla.response.time_remaining_minutes)}
                            </span>
                          )}
                          {report.sla.response.responded_at && (
                            <span className="text-xs text-gray-500">
                              Responded
                            </span>
                          )}
                        </div>
                      )
                    })()}
                  </div>

                  {/* Resolution SLA */}
                  <div>
                    <div className="flex items-center justify-between mb-2">
                      <p className="text-xs text-gray-500">Resolution</p>
                      <span className="text-xs text-gray-400">{report.sla.targets.resolution_hours}h target</span>
                    </div>
                    {(() => {
                      const config = slaStatusConfig[report.sla.resolution.status] || slaStatusConfig.on_track
                      const Icon = config.icon
                      return (
                        <div className={`flex items-center justify-between p-2.5 rounded-lg ${config.bg}`}>
                          <div className="flex items-center gap-2">
                            <Icon className={`w-4 h-4 ${config.iconColor}`} />
                            <span className={`text-sm font-medium ${config.text}`}>{config.label}</span>
                          </div>
                          {report.sla.resolution.time_remaining_minutes !== null && (
                            <span className={`text-xs font-mono ${config.text}`}>
                              {formatTimeRemaining(report.sla.resolution.time_remaining_minutes)}
                            </span>
                          )}
                          {report.sla.resolution.status === 'met' && (
                            <span className="text-xs text-gray-500">
                              Resolved
                            </span>
                          )}
                        </div>
                      )
                    })()}
                  </div>
                </div>
              </div>
            )}

            {/* Linked Issues */}
            {((report.outgoing_links && report.outgoing_links.length > 0) || (report.incoming_links && report.incoming_links.length > 0)) && (
              <div className="bg-white border rounded-xl p-5">
                <div className="flex items-center justify-between mb-4">
                  <h3 className="text-sm font-medium text-gray-500 uppercase tracking-wider">Linked Issues</h3>
                </div>
                <div className="space-y-2">
                  {report.outgoing_links?.map((link) => (
                    <a
                      key={link.id}
                      href={`/reports/${link.target_report?.uuid}`}
                      className="block p-2 bg-gray-50 rounded-lg hover:bg-gray-100 transition-colors"
                    >
                      <p className="text-[10px] text-gray-400 uppercase">{link.link_type}</p>
                      <p className="text-sm text-gray-900 truncate">{link.target_report?.title}</p>
                    </a>
                  ))}
                  {report.incoming_links?.map((link) => (
                    <a
                      key={link.id}
                      href={`/reports/${link.source_report?.uuid}`}
                      className="block p-2 bg-gray-50 rounded-lg hover:bg-gray-100 transition-colors"
                    >
                      <p className="text-[10px] text-gray-400 uppercase">{link.link_type} (from)</p>
                      <p className="text-sm text-gray-900 truncate">{link.source_report?.title}</p>
                    </a>
                  ))}
                </div>
              </div>
            )}

            {/* Activity */}
            <div className="bg-white border rounded-xl p-5">
              <h3 className="text-sm font-medium text-gray-500 uppercase tracking-wider mb-4">Activity</h3>
              <ol className="max-h-[32rem] overflow-y-auto pr-1">
                {timeline.map((entry, index) => (
                  <li key={entry.key} className="relative flex items-start gap-2 pb-4 text-xs last:pb-0">
                    {index < timeline.length - 1 && (
                      <span className="absolute left-2.5 top-6 bottom-0 w-px bg-border" aria-hidden="true" />
                    )}
                    <div className={`relative z-10 w-5 h-5 rounded-full ${roleColors[entry.user?.role || 'client'] || roleColors.client} flex items-center justify-center text-white text-[8px] font-medium flex-shrink-0 mt-0.5`}>
                      {(entry.user?.name ?? '?').charAt(0)}
                    </div>
                    <div className="flex-1 min-w-0">
                      <p className="text-gray-900">
                        <span className="font-medium">{entry.user?.name ?? 'Unknown user'}</span>{' '}
                        <span className="text-gray-500">· {humanize(entry.user?.role || 'user')}</span>
                      </p>
                      <p className="mt-0.5 font-medium text-gray-700">{entry.summary}</p>
                      {entry.details.length > 0 && (
                        <ul className="mt-1 space-y-0.5 text-gray-500">
                          {entry.details.map(detail => (
                            <li key={detail} className="break-words">{detail}</li>
                          ))}
                        </ul>
                      )}
                      {entry.quote && (
                        <p className="mt-1.5 rounded-md border-l-2 border-brand-gold bg-brand-paper px-2.5 py-1.5 text-gray-700 break-words whitespace-pre-wrap">
                          {shorten(entry.quote, 240)}
                        </p>
                      )}
                      <p className="mt-1 text-gray-400" title={new Date(entry.created_at).toLocaleString()}>
                        {timeAgo(entry.created_at)} · {new Date(entry.created_at).toLocaleString()}
                      </p>
                    </div>
                  </li>
                ))}
              </ol>
            </div>
          </div>
        </div>
      </div>

      {/* Resolve Modal */}
      {showResolveModal && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
          <div className="bg-white rounded-xl p-6 w-full max-w-md">
            <h3 className="text-lg font-semibold mb-4">Resolve Issue</h3>
            <div className="space-y-4">
              <div>
                <Label>Resolution Summary</Label>
                <Textarea
                  value={resolutionSummary}
                  onChange={(e) => setResolutionSummary(e.target.value)}
                  placeholder="Describe how this issue was resolved..."
                  rows={4}
                />
              </div>
              <div className="flex gap-2 justify-end">
                <Button variant="outline" onClick={() => setShowResolveModal(false)}>Cancel</Button>
                <Button onClick={() => handleAction('resolve', { resolution_summary: resolutionSummary })} disabled={loading}>
                  {loading ? 'Resolving...' : 'Resolve Issue'}
                </Button>
              </div>
            </div>
          </div>
        </div>
      )}

      {/* Reopen Modal */}
      {showReopenModal && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
          <div className="bg-white rounded-xl p-6 w-full max-w-md">
            <h3 className="text-lg font-semibold mb-4">Reopen Issue</h3>
            <div className="space-y-4">
              <div>
                <Label>Reason (Optional)</Label>
                <Textarea
                  value={reopenReason}
                  onChange={(e) => setReopenReason(e.target.value)}
                  placeholder="Why does this issue need to be reopened?"
                  rows={4}
                />
              </div>
              <div className="flex gap-2 justify-end">
                <Button variant="outline" onClick={() => setShowReopenModal(false)}>Cancel</Button>
                <Button onClick={() => handleAction('reopen', { comment: reopenReason })} disabled={loading} variant="destructive">
                  {loading ? 'Reopening...' : 'Reopen Issue'}
                </Button>
              </div>
            </div>
          </div>
        </div>
      )}

      {/* Image Lightbox/Carousel */}
      {lightboxOpen && imageAttachments.length > 0 && (
        <div
          className="fixed inset-0 bg-black/90 flex items-center justify-center z-50"
          onClick={() => setLightboxOpen(false)}
        >
          {/* Close button */}
          <button
            onClick={() => setLightboxOpen(false)}
            className="absolute top-4 right-4 p-2 rounded-full bg-white/10 hover:bg-white/20 transition-colors text-white"
          >
            <X className="w-6 h-6" />
          </button>

          {/* Download button */}
          <a
            href={`/storage/${imageAttachments[lightboxIndex].storage_path}`}
            download={imageAttachments[lightboxIndex].original_filename}
            onClick={(e) => e.stopPropagation()}
            className="absolute top-4 right-16 p-2 rounded-full bg-white/10 hover:bg-white/20 transition-colors text-white"
          >
            <Download className="w-6 h-6" />
          </a>

          {/* Image counter */}
          <div className="absolute top-4 left-4 text-white/80 text-sm font-medium bg-black/30 px-3 py-1.5 rounded-full">
            {lightboxIndex + 1} / {imageAttachments.length}
          </div>

          {/* Previous button */}
          {imageAttachments.length > 1 && (
            <button
              onClick={(e) => {
                e.stopPropagation()
                setLightboxIndex((prev) => (prev === 0 ? imageAttachments.length - 1 : prev - 1))
              }}
              className="absolute left-4 p-3 rounded-full bg-white/10 hover:bg-white/20 transition-colors text-white"
            >
              <ChevronLeft className="w-8 h-8" />
            </button>
          )}

          {/* Main image */}
          <div
            className="max-w-[90vw] max-h-[85vh] flex items-center justify-center"
            onClick={(e) => e.stopPropagation()}
          >
            <img
              src={`/storage/${imageAttachments[lightboxIndex].storage_path}`}
              alt={imageAttachments[lightboxIndex].original_filename}
              className="max-w-full max-h-[85vh] object-contain rounded-lg shadow-2xl"
            />
          </div>

          {/* Next button */}
          {imageAttachments.length > 1 && (
            <button
              onClick={(e) => {
                e.stopPropagation()
                setLightboxIndex((prev) => (prev === imageAttachments.length - 1 ? 0 : prev + 1))
              }}
              className="absolute right-4 p-3 rounded-full bg-white/10 hover:bg-white/20 transition-colors text-white"
            >
              <ChevronRight className="w-8 h-8" />
            </button>
          )}

          {/* Filename */}
          <div className="absolute bottom-4 left-1/2 -translate-x-1/2 text-white/80 text-sm bg-black/30 px-4 py-2 rounded-full max-w-md truncate">
            {imageAttachments[lightboxIndex].original_filename}
          </div>

          {/* Thumbnail strip */}
          {imageAttachments.length > 1 && (
            <div className="absolute bottom-16 left-1/2 -translate-x-1/2 flex gap-2 p-2 bg-black/30 rounded-lg">
              {imageAttachments.map((attachment, index) => (
                <button
                  key={attachment.id}
                  onClick={(e) => {
                    e.stopPropagation()
                    setLightboxIndex(index)
                  }}
                  className={`w-12 h-12 rounded overflow-hidden border-2 transition-all ${
                    index === lightboxIndex ? 'border-white scale-110' : 'border-transparent opacity-60 hover:opacity-100'
                  }`}
                >
                  <img
                    src={`/storage/${attachment.storage_path}`}
                    alt={attachment.original_filename}
                    className="w-full h-full object-cover"
                  />
                </button>
              ))}
            </div>
          )}
        </div>
      )}
    </AppLayout>
  )
}
