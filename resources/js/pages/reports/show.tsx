import { useState, useEffect } from 'react'
import { Head, usePage, router, useForm } from '@inertiajs/react'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Card } from '@/components/ui/card'
import { Textarea } from '@/components/ui/textarea'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { DatePicker } from '@/components/ui/date-picker'
import { Separator } from '@/components/ui/separator'
import { ArrowLeft, FileImage, User, Calendar, MessageCircle, Edit, CheckCircle, XCircle, Clock, History, File, ExternalLink, Plus, Eye, RotateCcw, AlertCircle, Send } from 'lucide-react'
import AppLayout from '@/layouts/app-layout'
import { getCsrfToken } from '@/lib/csrf'

interface Report {
  id: number
  uuid: string
  title: string
  description: string
  project: string | { id: number; name: string }
  status: 'pending' | 'in_progress' | 'submit_for_review' | 'approved' | 'rejected' | 'closed'
  reporter: { id: number; name: string; email: string; role: string }
  assignee?: { id: number; name: string; email: string; role: string }
  due_date?: string | null
  priority: 'low' | 'medium' | 'high' | 'urgent'
  attachments: Array<{
    id: number
    filename: string
    original_filename: string
    storage_path: string
  }>
  comments?: Array<{
    id: number
    comment: string
    user: { id: number; name: string; email: string; role: string }
    created_at: string
  }>
  activityLogs?: Array<{
    id: number
    action: string
    comment?: string
    old_values?: Record<string, unknown> | null
    new_values?: Record<string, unknown> | null
    user: { id: number; name: string; email: string; role?: string }
    created_at: string
  }>
  resolution_summary?: string
  created_at: string
  updated_at: string
}

interface PageProps extends Record<string, unknown> {
  report: Report
  userRole: 'client' | 'admin' | 'super_admin'
  userId: number
}

interface ActivityLog {
  id: number
  action: string
  comment?: string
  old_values?: Record<string, unknown> | null
  new_values?: Record<string, unknown> | null
  user: { id: number; name: string; email: string; role?: string }
  created_at: string
}

interface TimelineEntry {
  key: string
  action: string
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
  status: 'Status'
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

const describeLog = (log: ActivityLog): Pick<TimelineEntry, 'summary' | 'details' | 'quote'> => {
  const oldValues = log.old_values ?? {}
  const newValues = log.new_values ?? {}
  const transition = oldValues.status && newValues.status && oldValues.status !== newValues.status
    ? [`Status: ${humanize(oldValues.status)} → ${humanize(newValues.status)}`]
    : []

  switch (log.action) {
    case 'acknowledged':
      return { summary: 'Acknowledged the report and started work', details: transition }
    case 'submitted_for_review':
      return { summary: 'Submitted the work for client review', details: transition }
    case 'returned_to_progress':
      return { summary: 'Moved the report back to In Progress', details: transition }
    case 'approved':
      return { summary: 'Approved the work', details: transition }
    case 'rejected':
      return {
        summary: 'Rejected the work',
        details: transition,
        quote: newValues.reason ? String(newValues.reason) : undefined
      }
    case 'closed':
      return { summary: 'Closed the report', details: transition }
    case 'updated': {
      const details: string[] = []
      Object.keys(FIELD_LABELS).forEach(field => {
        if (!(field in newValues)) return
        const before = field === 'due_date' ? String(oldValues[field] ?? '').slice(0, 10) : oldValues[field] ?? ''
        const after = field === 'due_date' ? String(newValues[field] ?? '').slice(0, 10) : newValues[field] ?? ''
        if (before === after) return

        details.push(
          field === 'description'
            ? `Description changed to: ${shorten(String(after))}`
            : `${FIELD_LABELS[field]}: ${formatFieldValue(field, before)} → ${formatFieldValue(field, after)}`
        )
      })
      return {
        summary: details.length > 0 ? 'Edited the report' : 'Saved the report without changes',
        details
      }
    }
    default:
      return { summary: humanize(log.action), details: transition }
  }
}

const statusColors = {
  pending: 'bg-yellow-500',
  in_progress: 'bg-blue-500',
  submit_for_review: 'bg-purple-500',
  approved: 'bg-green-500',
  rejected: 'bg-red-500',
  closed: 'bg-gray-500'
}

export default function ReportShow() {
  const { report: initialReport, userRole, userId } = usePage<PageProps>().props
  const [report, setReport] = useState<Report>(initialReport)
  const [activityLogs, setActivityLogs] = useState<ActivityLog[]>(initialReport.activityLogs || [])
  const [loading, setLoading] = useState(false)
  const [resolutionSummary, setResolutionSummary] = useState('')
  const [comment, setComment] = useState('')
  const [newComment, setNewComment] = useState('')
  const [showResolutionForm, setShowResolutionForm] = useState(false)
  const [showCommentForm, setShowCommentForm] = useState(false)
  // Opened from an edit button elsewhere (?edit=1): start in edit mode
  const [isEditing, setIsEditing] = useState(
    () => typeof window !== 'undefined' && new URLSearchParams(window.location.search).get('edit') === '1'
  )
  const [editData, setEditData] = useState({
    title: report.title,
    description: report.description,
    status: report.status,
    due_date: report.due_date || '',
    priority: report.priority || 'medium'
  })

  useEffect(() => {
    fetchReportDetails()
  }, [])

  const fetchReportDetails = async () => {
    try {
      const response = await fetch(`/api/reports/${report.uuid}`)
      const data = await response.json()
      if (response.ok && data.report.activityLogs) {
        setActivityLogs(data.report.activityLogs)
      }
    } catch (error) {
      console.error('Failed to fetch report details:', error)
    }
  }

  const handleAction = async (action: string, data?: Record<string, any>) => {
    setLoading(true)
    try {
      // Always fetch a fresh CSRF token to avoid expiration issues
      const tokenResponse = await fetch('/api/csrf-token', {
        credentials: 'same-origin'
      })
      
      let csrfToken = ''
      if (tokenResponse.ok) {
        const tokenData = await tokenResponse.json()
        csrfToken = tokenData.token
        // Update meta tag
        const metaTag = document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement
        if (metaTag) {
          metaTag.content = csrfToken
        }
      } else {
        // Fallback to existing token if API fails
        csrfToken = getCsrfToken() || ''
      }

      if (!csrfToken) {
        throw new Error('CSRF token not found')
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
        setReport(result.report)
        // Update activity logs directly from the response
        if (result.report.activityLogs) {
          setActivityLogs(result.report.activityLogs)
        }
        // Update edit form data with new report data
        setEditData({
          title: result.report.title,
          description: result.report.description,
          status: result.report.status,
          due_date: result.report.due_date || '',
          priority: result.report.priority || 'medium'
        })
        setResolutionSummary('')
        setComment('')
        setShowResolutionForm(false)
        setShowCommentForm(false)
        // Exit edit mode after successful save
        if (action === 'update_report') {
          setIsEditing(false)
        }
      } else {
        alert(result.message || 'Action failed')
      }
    } catch (error) {
      alert('Network error. Please try again.')
    } finally {
      setLoading(false)
    }
  }

  const handleAddComment = async () => {
    if (!newComment.trim()) return
    
    setLoading(true)
    try {
      // Always fetch a fresh CSRF token to avoid expiration issues
      const tokenResponse = await fetch('/api/csrf-token', {
        credentials: 'same-origin'
      })
      
      let csrfToken = ''
      if (tokenResponse.ok) {
        const tokenData = await tokenResponse.json()
        csrfToken = tokenData.token
        // Update meta tag
        const metaTag = document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement
        if (metaTag) {
          metaTag.content = csrfToken
        }
      } else {
        // Fallback to existing token if API fails
        csrfToken = getCsrfToken() || ''
      }

      if (!csrfToken) {
        throw new Error('CSRF token not found')
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
        body: JSON.stringify({ action: 'add_comment', comment: newComment })
      })

      const result = await response.json()

      if (response.ok) {
        setReport(result.report)
        // Update activity logs from the response
        if (result.report.activityLogs) {
          setActivityLogs(result.report.activityLogs)
        }
        setNewComment('')
      } else {
        alert(result.message || 'Failed to add comment')
      }
    } catch (error) {
      alert('Network error. Please try again.')
    } finally {
      setLoading(false)
    }
  }

  // Permission checks
  const canAcknowledge = (userRole === 'admin' || userRole === 'super_admin') && report.status === 'pending'
  const canSubmitForReview = (userRole === 'admin' || userRole === 'super_admin') && report.status === 'in_progress'
  const canReturnToProgress = (userRole === 'admin' || userRole === 'super_admin') && report.status === 'submit_for_review'
  const canApprove = userRole === 'client' && report.status === 'submit_for_review' && Number(report.reporter.id) === Number(userId)
  const canReject = userRole === 'client' && report.status === 'submit_for_review' && Number(report.reporter.id) === Number(userId)
  const canEdit = userRole === 'admin' || userRole === 'super_admin' || 
    (userRole === 'client' && Number(report.reporter.id) === Number(userId) && report.status === 'pending')
  const canClose = (userRole === 'client' && Number(report.reporter.id) === Number(userId) && report.status !== 'closed') ||
    userRole === 'admin' || userRole === 'super_admin'
  const canComment = (userRole === 'admin' || userRole === 'super_admin') || 
    (userRole === 'client' && Number(report.reporter.id) === Number(userId))

  const formatStatus = (status: string) => {
    return status.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase())
  }

  const formatRole = (role: string) => {
    return role.replace('_', ' ').replace(/\b\w/g, l => l.toUpperCase())
  }

  const formatAction = (action: string) => {
    const actionMap: Record<string, string> = {
      created: 'Created',
      acknowledged: 'Acknowledged',
      submitted_for_review: 'Submitted for Review',
      returned_to_progress: 'Returned to In Progress',
      approved: 'Approved',
      rejected: 'Rejected',
      updated: 'Updated',
      closed: 'Closed'
    }
    return actionMap[action] || action
  }

  const getActionIcon = (action: string) => {
    switch (action) {
      case 'created':
        return <Plus className="w-4 h-4" />
      case 'commented':
        return <MessageCircle className="w-4 h-4" />
      case 'acknowledged':
        return <Eye className="w-4 h-4" />
      case 'submitted_for_review':
        return <Send className="w-4 h-4" />
      case 'returned_to_progress':
        return <RotateCcw className="w-4 h-4" />
      case 'approved':
        return <CheckCircle className="w-4 h-4" />
      case 'rejected':
        return <XCircle className="w-4 h-4" />
      case 'updated':
        return <Edit className="w-4 h-4" />
      case 'closed':
        return <CheckCircle className="w-4 h-4" />
      default:
        return <MessageCircle className="w-4 h-4" />
    }
  }

  const getActionColor = (action: string) => {
    switch (action) {
      case 'created':
        return 'bg-blue-100 text-blue-600'
      case 'commented':
        return 'bg-teal-100 text-teal-600'
      case 'acknowledged':
        return 'bg-purple-100 text-purple-600'
      case 'submitted_for_review':
        return 'bg-orange-100 text-orange-600'
      case 'returned_to_progress':
        return 'bg-yellow-100 text-yellow-600'
      case 'approved':
        return 'bg-green-100 text-green-600'
      case 'rejected':
        return 'bg-red-100 text-red-600'
      case 'updated':
        return 'bg-indigo-100 text-indigo-600'
      case 'closed':
        return 'bg-gray-100 text-gray-600'
      default:
        return 'bg-gray-100 text-gray-600'
    }
  }

  // Everything that happened to this report, newest first: creation, status changes, edits and comments
  const rejectionReasons = new Set(
    activityLogs.filter(log => log.action === 'rejected' && log.new_values?.reason).map(log => String(log.new_values?.reason))
  )
  const timeline: TimelineEntry[] = [
    ...activityLogs.map(log => ({
      key: `log-${log.id}`,
      action: log.action,
      user: log.user,
      created_at: log.created_at,
      ...describeLog(log)
    })),
    // A rejection reason is also saved as a comment; it is already shown on the rejection itself
    ...(report.comments ?? [])
      .filter(item => !rejectionReasons.has(item.comment))
      .map(item => ({
        key: `comment-${item.id}`,
        action: 'commented',
        user: item.user,
        created_at: item.created_at,
        summary: 'Added a comment',
        details: [],
        quote: item.comment
      })),
    {
      key: 'created',
      action: 'created',
      user: report.reporter,
      created_at: report.created_at,
      summary: 'Created the report',
      details: typeof report.project === 'object' && report.project?.name ? [`Project: ${report.project.name}`] : []
    }
  ].sort((a, b) => new Date(b.created_at).getTime() - new Date(a.created_at).getTime())

  return (
    <AppLayout>
      <Head title={`Report #${report.id}`} />
      
      <div className="space-y-6 px-4 py-6">
        {/* Header */}
        <div className="flex items-center gap-4">
          <Button 
            variant="outline" 
            size="sm"
            onClick={() => router.get('/dashboard')}
          >
            <ArrowLeft className="w-4 h-4 mr-2" />
            Back to Reports
          </Button>
          
          <div className="flex-1">
            <div className="flex items-center gap-3">
              <h1 className="text-2xl font-bold">Report #{report.id}</h1>
              <Badge className={`${statusColors[report.status]} text-white`}>
                {formatStatus(report.status)}
              </Badge>
            </div>
            <p className="text-muted-foreground">
              {report.title}
            </p>
          </div>
          
          <div className="flex gap-2">
            {canEdit && (
              <Button
                variant="outline"
                onClick={() => setIsEditing(!isEditing)}
              >
                <Edit className="w-4 h-4 mr-2" />
                {isEditing ? 'Cancel Edit' : 'Edit Report'}
              </Button>
            )}
          </div>
        </div>

        {/* Report Information */}
        <div className="grid grid-cols-1 lg:grid-cols-4 gap-6">
          {/* Main Content */}
          <div className="lg:col-span-3 space-y-6">
            {/* Report Info */}
            <div className="bg-white shadow-sm border rounded-lg p-6">
              <h3 className="text-lg font-semibold mb-4">Information</h3>
              <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 text-sm">
                <div className="space-y-1">
                  <span className="text-muted-foreground font-medium">Project:</span>
                  <div className="font-medium">
                    {typeof report.project === 'object' ? (report.project?.name || '-') : (report.project || '-')}
                  </div>
                </div>
                <div className="space-y-1">
                  <span className="text-muted-foreground font-medium">Reporter:</span>
                  <div className="font-medium">{report.reporter.name}</div>
                </div>
                <div className="space-y-1">
                  <span className="text-muted-foreground font-medium">Priority:</span>
                  <div>
                    {isEditing && canEdit ? (
                      <Select value={editData.priority} onValueChange={(value) => setEditData(prev => ({ ...prev, priority: value }))}>
                        <SelectTrigger className="w-32">
                          <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                          <SelectItem value="low">Low</SelectItem>
                          <SelectItem value="medium">Medium</SelectItem>
                          <SelectItem value="high">High</SelectItem>
                          <SelectItem value="urgent">Urgent</SelectItem>
                        </SelectContent>
                      </Select>
                    ) : (
                      <span className={`inline-flex px-2 py-1 rounded-full text-xs font-medium ${
                        report.priority === 'urgent' ? 'bg-red-100 text-red-800' :
                        report.priority === 'high' ? 'bg-orange-100 text-orange-800' :
                        report.priority === 'medium' ? 'bg-yellow-100 text-yellow-800' :
                        'bg-green-100 text-green-800'
                      }`}>
                        {report.priority?.charAt(0).toUpperCase() + report.priority?.slice(1)}
                      </span>
                    )}
                  </div>
                </div>
                <div className="space-y-1">
                  <span className="text-muted-foreground font-medium">Due Date:</span>
                  <div className="font-medium">
                    {isEditing && canEdit ? (
                      <DatePicker
                        value={editData.due_date}
                        onChange={(value) => setEditData(prev => ({ ...prev, due_date: value }))}
                        placeholder="Select due date"
                        className="w-48"
                      />
                    ) : (
                      report.due_date ? new Date(report.due_date).toLocaleDateString() : 'Not set'
                    )}
                  </div>
                </div>
                {report.assignee && (
                  <div className="space-y-1">
                    <span className="text-muted-foreground font-medium">Assignee:</span>
                    <div className="font-medium">{report.assignee.name}</div>
                  </div>
                )}
                <div className="space-y-1">
                  <span className="text-muted-foreground font-medium">Created:</span>
                  <div className="font-medium">{new Date(report.created_at).toLocaleDateString()}</div>
                </div>
                <div className="space-y-1">
                  <span className="text-muted-foreground font-medium">Last Updated:</span>
                  <div className="font-medium">{new Date(report.updated_at).toLocaleDateString()}</div>
                </div>
              </div>
            </div>
            {/* Edit Form */}
            {canEdit && isEditing && (
              <Card className="p-6">
                <h3 className="text-lg font-semibold mb-4">Edit Report</h3>
                <div className="space-y-4">
                  <div>
                    <Label htmlFor="edit-title">Title</Label>
                    <Input
                      id="edit-title"
                      value={editData.title}
                      onChange={(e) => setEditData(prev => ({ ...prev, title: e.target.value }))}
                    />
                  </div>
                  <div>
                    <Label htmlFor="edit-description">Description</Label>
                    <Textarea
                      id="edit-description"
                      value={editData.description}
                      onChange={(e) => setEditData(prev => ({ ...prev, description: e.target.value }))}
                      rows={4}
                    />
                  </div>
                  
                  {(userRole === 'admin' || userRole === 'super_admin') && (
                    <div>
                      <Label htmlFor="edit-status">Status</Label>
                      <Select value={editData.status} onValueChange={(value) => setEditData(prev => ({ ...prev, status: value }))}>
                        <SelectTrigger>
                          <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                          <SelectItem value="pending">Pending</SelectItem>
                          <SelectItem value="in_progress">In Progress</SelectItem>
                          <SelectItem value="submit_for_review">Submit for Review</SelectItem>
                          <SelectItem value="approved">Approved</SelectItem>
                          <SelectItem value="rejected">Rejected</SelectItem>
                          <SelectItem value="closed">Closed</SelectItem>
                        </SelectContent>
                      </Select>
                    </div>
                  )}
                  <div className="flex gap-2">
                    <Button
                      onClick={() => {
                        // Only include status in update data for admins/super_admins
                        const updateData = userRole === 'admin' || userRole === 'super_admin' 
                          ? editData 
                          : { title: editData.title, description: editData.description, priority: editData.priority, due_date: editData.due_date }
                        handleAction('update_report', updateData)
                      }}
                      disabled={loading}
                    >
                      {loading ? 'Saving...' : 'Save Changes'}
                    </Button>
                    <Button
                      variant="outline"
                      onClick={() => {
                        setIsEditing(false)
                        setEditData({
                          title: report.title,
                          description: report.description,
                          status: report.status,
                          due_date: report.due_date || '',
                          priority: report.priority || 'medium'
                        })
                      }}
                    >
                      Cancel
                    </Button>
                  </div>
                </div>
              </Card>
            )}

            {/* Report Details */}
            <Card className="p-6">
              <h3 className="text-lg font-semibold mb-4">Report Details</h3>
              <div className="space-y-4">
                <div>
                  <Label className="text-sm font-medium">Description</Label>
                  <div className="mt-2 p-3 bg-gray-50 rounded-md">
                    <p className="text-sm whitespace-pre-wrap">{report.description}</p>
                  </div>
                </div>
              </div>
            </Card>

            {/* Attachments */}
            {report.attachments.length > 0 && (
              <Card className="p-6">
                <h3 className="text-lg font-semibold mb-4">Attachments</h3>
                <div className="space-y-3">
                  {report.attachments.map((attachment) => {
                    const isPDF = attachment.original_filename.toLowerCase().endsWith('.pdf')
                    const isImage = attachment.original_filename.toLowerCase().match(/\.(jpg|jpeg|png|gif)$/)
                    const fileSize = attachment.file_size ? (attachment.file_size / 1024 / 1024).toFixed(2) + ' MB' : ''
                    
                    return (
                      <div key={attachment.id} className="flex items-center gap-3 p-3 bg-gray-50 rounded-lg border hover:bg-gray-100 transition-colors">
                        <div className="flex-shrink-0">
                          {isPDF ? (
                            <File className="w-5 h-5 text-red-600" />
                          ) : isImage ? (
                            <FileImage className="w-5 h-5 text-blue-600" />
                          ) : (
                            <File className="w-5 h-5 text-gray-600" />
                          )}
                        </div>
                        <div className="flex-1 min-w-0">
                          <a 
                            href={`/storage/${attachment.storage_path}`}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="text-blue-600 hover:text-blue-800 hover:underline font-medium text-sm block truncate"
                            title={attachment.original_filename}
                          >
                            {attachment.original_filename}
                          </a>
                          {fileSize && (
                            <span className="text-xs text-gray-500">{fileSize}</span>
                          )}
                        </div>
                        <div className="flex-shrink-0">
                          <a 
                            href={`/storage/${attachment.storage_path}`}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="text-gray-400 hover:text-gray-600"
                            title="View file"
                          >
                            <ExternalLink className="w-4 h-4" />
                          </a>
                        </div>
                      </div>
                    )
                  })}
                </div>
              </Card>
            )}

            {/* Resolution Summary */}
            {report.resolution_summary && (
              <Card className="p-6">
                <h3 className="text-lg font-semibold mb-4">Resolution Summary</h3>
                <div className="p-3 bg-green-50 rounded-md border border-green-200">
                  <p className="text-sm whitespace-pre-wrap">{report.resolution_summary}</p>
                </div>
              </Card>
            )}
          </div>

          {/* Activity Timeline Sidebar */}
          <div className="space-y-6">
            {/* Activity Timeline */}
            <Card className="p-6">
              <h3 className="text-lg font-semibold mb-4">Activity Timeline</h3>
              <ol className="max-h-[32rem] overflow-y-auto pr-1">
                {timeline.map((entry, index) => (
                  <li key={entry.key} className="relative flex gap-3 pb-5 text-sm last:pb-0">
                    {index < timeline.length - 1 && (
                      <span className="absolute left-4 top-8 bottom-0 w-px bg-border" aria-hidden="true" />
                    )}
                    <div className={`relative z-10 w-8 h-8 rounded-full flex items-center justify-center flex-shrink-0 ${getActionColor(entry.action)}`}>
                      {getActionIcon(entry.action)}
                    </div>
                    <div className="min-w-0 flex-1">
                      <div className="flex flex-wrap items-center gap-2">
                        <span className="font-medium">{entry.user?.name ?? 'Unknown user'}</span>
                        {entry.user?.role && (
                          <span className="text-xs px-2 py-0.5 bg-gray-100 text-gray-600 rounded-full">
                            {formatRole(entry.user.role)}
                          </span>
                        )}
                      </div>
                      <p className="mt-0.5 font-medium text-muted-foreground">{entry.summary}</p>
                      {entry.details.length > 0 && (
                        <ul className="mt-1 space-y-0.5 text-xs text-muted-foreground">
                          {entry.details.map(detail => (
                            <li key={detail} className="break-words">{detail}</li>
                          ))}
                        </ul>
                      )}
                      {entry.quote && (
                        <p className="mt-1.5 rounded-md border-l-2 border-brand-gold bg-brand-paper px-3 py-2 text-xs break-words whitespace-pre-wrap">
                          {shorten(entry.quote, 240)}
                        </p>
                      )}
                      <p className="mt-1 text-xs text-muted-foreground">
                        {new Date(entry.created_at).toLocaleString()}
                      </p>
                    </div>
                  </li>
                ))}
              </ol>
            </Card>

            {/* Client Actions */}
            {userRole === 'client' && Number(report.reporter.id) === Number(userId) && report.status !== 'closed' && (
              <Card className="p-6">
                <h3 className="text-lg font-semibold mb-4">Actions</h3>
                <div className="space-y-3">
                  {canClose && (
                    <Button 
                      onClick={() => handleAction('close_report')}
                      disabled={loading}
                      variant="outline"
                      className="w-full"
                    >
                      <CheckCircle className="w-4 h-4 mr-2" />
                      {loading ? 'Saving...' : 'Close Report'}
                    </Button>
                  )}

                  {canApprove && (
                    <Button 
                      onClick={() => handleAction('approve')}
                      disabled={loading}
                      className="w-full bg-green-600 hover:bg-green-700"
                    >
                      <CheckCircle className="w-4 h-4 mr-2" />
                      {loading ? 'Saving...' : 'Approve'}
                    </Button>
                  )}

                  {canReject && !showCommentForm && (
                    <Button 
                      onClick={() => setShowCommentForm(true)}
                      disabled={loading}
                      variant="destructive"
                      className="w-full"
                    >
                      <XCircle className="w-4 h-4 mr-2" />
                      Reject
                    </Button>
                  )}

                  {canReject && showCommentForm && (
                    <div className="space-y-2">
                      <Label htmlFor="reject-reason">Reason for rejecting</Label>
                      <Textarea
                        id="reject-reason"
                        value={comment}
                        onChange={(e) => setComment(e.target.value)}
                        placeholder="What still needs to be fixed?"
                        rows={3}
                      />
                      <div className="flex gap-2">
                        <Button 
                          onClick={() => handleAction('reject', { comment: comment.trim() })}
                          disabled={loading || comment.trim().length < 5}
                          variant="destructive"
                          className="flex-1"
                        >
                          <XCircle className="w-4 h-4 mr-2" />
                          {loading ? 'Saving...' : 'Confirm Reject'}
                        </Button>
                        <Button 
                          onClick={() => { setShowCommentForm(false); setComment('') }}
                          disabled={loading}
                          variant="outline"
                        >
                          Cancel
                        </Button>
                      </div>
                    </div>
                  )}
                </div>
              </Card>
            )}


          </div>
        </div>

        {/* Comments Section */}
        {canComment && (
          <Card className="p-6">
            <h3 className="text-lg font-semibold mb-4">Comments</h3>
            
            {/* Existing Comments */}
            {report.comments && report.comments.length > 0 && (
              <div className="space-y-3 mb-6 max-h-60 overflow-y-auto">
                {report.comments.map((comment) => (
                  <div key={comment.id} className="bg-gray-50 p-3 rounded-lg border">
                    <div className="flex items-center gap-2 mb-2">
                      <span className="font-medium text-sm">{comment.user.name}</span>
                      <span className="text-xs px-2 py-1 bg-blue-100 text-blue-800 rounded-full">
                        {formatRole(comment.user.role)}
                      </span>
                      <span className="text-xs text-muted-foreground ml-auto">
                        {new Date(comment.created_at).toLocaleString()}
                      </span>
                    </div>
                    <p className="text-sm text-gray-700 whitespace-pre-wrap">{comment.comment}</p>
                  </div>
                ))}
              </div>
            )}
            
            {/* Add Comment Form */}
            <div className="space-y-3">
              <Textarea
                value={newComment}
                onChange={(e) => setNewComment(e.target.value)}
                placeholder="Write a comment..."
                rows={3}
                className="resize-none"
              />
              <div className="flex justify-end">
                <Button
                  onClick={handleAddComment}
                  disabled={loading || !newComment.trim()}
                >
                  <MessageCircle className="w-4 h-4 mr-2" />
                  {loading ? 'Saving...' : 'Add Comment'}
                </Button>
              </div>
            </div>
          </Card>
        )}

      </div>
    </AppLayout>
  )
}