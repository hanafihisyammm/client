import { useState, useEffect } from 'react'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Card } from '@/components/ui/card'
import { Textarea } from '@/components/ui/textarea'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Separator } from '@/components/ui/separator'
import { FileImage, User, Calendar, MessageCircle, Edit } from 'lucide-react'

interface ReportDetailsProps {
  report: {
    id: number
    title: string
    description: string
    project: string
    status: 'unassigned' | 'in_progress' | 'ready_for_client_confirmation' | 'closed' | 'reopened'
    reporter: { id: number; name: string; email: string }
    assignee?: { id: number; name: string; email: string }
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
    resolution_summary?: string
    created_at: string
    updated_at: string
  }
  onUpdate: (report: any) => void
  userRole: 'client' | 'admin' | 'super_admin'
  userId: number
}

interface ActivityLog {
  id: number
  action: string
  comment?: string
  user: { id: number; name: string; email: string }
  created_at: string
}

const statusColors = {
  unassigned: 'bg-gray-500',
  in_progress: 'bg-blue-500',
  ready_for_client_confirmation: 'bg-yellow-500',
  closed: 'bg-green-500',
  reopened: 'bg-orange-500'
}


export default function ReportDetails({ report, onUpdate, userRole, userId }: ReportDetailsProps) {
  const [activityLogs, setActivityLogs] = useState<ActivityLog[]>([])
  const [loading, setLoading] = useState(false)
  const [resolutionSummary, setResolutionSummary] = useState('')
  const [comment, setComment] = useState('')
  const [showResolutionForm, setShowResolutionForm] = useState(false)
  const [showCommentForm, setShowCommentForm] = useState(false)
  const [isEditing, setIsEditing] = useState(false)
  const [newComment, setNewComment] = useState('')
  const [editData, setEditData] = useState({
    title: '',
    description: '',
    status: ''
  })

  useEffect(() => {
    fetchReportDetails()
    setEditData({
      title: report.title,
      description: report.description,
      status: report.status
    })
  }, [report.id, report.title, report.description, report.status])

  const fetchReportDetails = async () => {
    try {
      const response = await fetch(`/api/reports/${report.id}`)
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
      const response = await fetch(`/api/reports/${report.id}`, {
        method: 'PATCH',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
          'Accept': 'application/json'
        },
        body: JSON.stringify({ action, ...data })
      })

      const result = await response.json()

      if (response.ok) {
        onUpdate(result.report)
        fetchReportDetails()
        setResolutionSummary('')
        setComment('')
        setShowResolutionForm(false)
        setShowCommentForm(false)
      } else {
        alert(result.message || 'Action failed')
      }
    } catch (error) {
      alert('Network error. Please try again.')
    } finally {
      setLoading(false)
    }
  }

  const canAssign = userRole === 'admin' && report.status === 'unassigned'
  const canMarkReady = userRole === 'admin' && 
    report.status === 'in_progress' && report.assignee?.id === userId
  const canClose = userRole === 'client' && report.status === 'ready_for_client_confirmation' && 
    report.reporter.id === userId
  const canReopen = userRole === 'client' && report.status === 'closed' && 
    report.reporter.id === userId
  const canComment = (userRole === 'admin' || userRole === 'super_admin') || 
    (userRole === 'client' && report.reporter.id === userId)

  const formatStatus = (status: string) => {
    return status.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase())
  }

  const formatRole = (role: string) => {
    return role.replace('_', ' ').replace(/\b\w/g, l => l.toUpperCase())
  }

  const formatAction = (action: string) => {
    const actionMap: Record<string, string> = {
      created: 'Created',
      assigned: 'Assigned',
      marked_ready_for_confirmation: 'Marked Ready for Confirmation',
      closed: 'Closed',
      reopened: 'Reopened'
    }
    return actionMap[action] || action
  }

  const handleAddComment = async () => {
    if (!newComment.trim()) return
    
    setLoading(true)
    try {
      const response = await fetch(`/api/reports/${report.id}`, {
        method: 'PATCH',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
          'Accept': 'application/json'
        },
        body: JSON.stringify({ action: 'add_comment', comment: newComment })
      })

      const result = await response.json()

      if (response.ok) {
        onUpdate(result.report)
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

  return (
    <div>
      <DialogHeader>
        <DialogTitle className="flex items-center gap-2 justify-between">
          <div className="flex items-center gap-2">
            {report.title}
            <Badge className={`${statusColors[report.status]} text-white`}>
              {formatStatus(report.status)}
            </Badge>
          </div>
          {(userRole === 'admin' || userRole === 'super_admin') && (
            <Button
              variant="outline"
              size="sm"
              onClick={() => setIsEditing(!isEditing)}
            >
              <Edit className="w-4 h-4 mr-1" />
              {isEditing ? 'Cancel' : 'Edit'}
            </Button>
          )}
        </DialogTitle>
      </DialogHeader>

      <div className="space-y-6 mt-4">
        {(userRole === 'admin' || userRole === 'super_admin') && isEditing && (
          <div className="bg-blue-50 p-4 rounded-lg border border-blue-200 space-y-4">
            <div>
              <Label htmlFor="edit-title">Title</Label>
              <Input
                id="edit-title"
                value={editData.title}
                onChange={(e) => setEditData(prev => ({ ...prev, title: e.target.value }))}
                className="mt-1"
              />
            </div>
            <div>
              <Label htmlFor="edit-description">Description</Label>
              <Textarea
                id="edit-description"
                value={editData.description}
                onChange={(e) => setEditData(prev => ({ ...prev, description: e.target.value }))}
                className="mt-1"
                rows={4}
              />
            </div>
            <div>
              <Label htmlFor="edit-status">Status</Label>
              <Select value={editData.status} onValueChange={(value) => setEditData(prev => ({ ...prev, status: value }))}>
                <SelectTrigger className="mt-1">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="unassigned">Unassigned</SelectItem>
                  <SelectItem value="in_progress">In Progress</SelectItem>
                  <SelectItem value="ready_for_client_confirmation">Ready for Confirmation</SelectItem>
                  <SelectItem value="closed">Closed</SelectItem>
                  <SelectItem value="reopened">Reopened</SelectItem>
                </SelectContent>
              </Select>
            </div>
            <div className="flex gap-2">
              <Button
                onClick={() => handleAction('update_report', editData)}
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
                    status: report.status
                  })
                }}
              >
                Cancel
              </Button>
            </div>
          </div>
        )}
        
        <div>
          <div className="grid grid-cols-2 gap-4 mb-4">
            <div>
              <Label className="text-sm font-medium text-muted-foreground">Project</Label>
              <p>{report.project}</p>
            </div>
            <div>
              <Label className="text-sm font-medium text-muted-foreground">Reporter</Label>
              <p className="flex items-center gap-1">
                <User className="w-4 h-4" />
                {report.reporter.name}
              </p>
            </div>
            {report.assignee && (
              <div>
                <Label className="text-sm font-medium text-muted-foreground">Assignee</Label>
                <p className="flex items-center gap-1">
                  <User className="w-4 h-4" />
                  {report.assignee.name}
                </p>
              </div>
            )}
            <div>
              <Label className="text-sm font-medium text-muted-foreground">Created</Label>
              <p className="flex items-center gap-1">
                <Calendar className="w-4 h-4" />
                {new Date(report.created_at).toLocaleString()}
              </p>
            </div>
          </div>

          <div className="space-y-2">
            <Label className="text-sm font-medium">Description</Label>
            <p className="text-sm bg-gray-50 p-3 rounded-md whitespace-pre-wrap">
              {report.description}
            </p>
          </div>
        </div>

        {report.attachments.length > 0 && (
          <div>
            <Label className="text-sm font-medium mb-2 block">Attachments</Label>
            <div className="grid grid-cols-3 gap-2">
              {report.attachments.map((attachment) => (
                <div key={attachment.id} className="border rounded-lg p-2">
                  <div className="aspect-square bg-gray-100 rounded mb-2 flex items-center justify-center">
                    <img
                      src={`/storage/${attachment.storage_path}`}
                      alt={attachment.original_filename}
                      className="max-w-full max-h-full object-contain"
                    />
                  </div>
                  <p className="text-xs truncate" title={attachment.original_filename}>
                    <FileImage className="w-3 h-3 inline mr-1" />
                    {attachment.original_filename}
                  </p>
                </div>
              ))}
            </div>
          </div>
        )}

        {report.resolution_summary && (
          <div>
            <Label className="text-sm font-medium mb-2 block">Resolution Summary</Label>
            <Card className="p-3">
              <p className="text-sm whitespace-pre-wrap">{report.resolution_summary}</p>
            </Card>
          </div>
        )}

        <div className="space-y-2">
          {canAssign && (
            <Button 
              onClick={() => handleAction('assign')}
              disabled={loading}
              className="w-full"
            >
              {loading ? 'Saving...' : 'Assign to Me'}
            </Button>
          )}

          {canMarkReady && (
            <>
              {!showResolutionForm ? (
                <Button 
                  onClick={() => setShowResolutionForm(true)}
                  className="w-full"
                >
                  Mark Ready for Client Confirmation
                </Button>
              ) : (
                <div className="space-y-2">
                  <Label>Resolution Summary</Label>
                  <Textarea
                    value={resolutionSummary}
                    onChange={(e) => setResolutionSummary(e.target.value)}
                    placeholder="Describe what was done to resolve this issue..."
                    rows={3}
                    required
                  />
                  <div className="flex gap-2">
                    <Button
                      onClick={() => handleAction('mark_ready', { resolution_summary: resolutionSummary })}
                      disabled={loading || !resolutionSummary.trim()}
                    >
                      {loading ? 'Saving...' : 'Submit'}
                    </Button>
                    <Button
                      variant="outline"
                      onClick={() => setShowResolutionForm(false)}
                    >
                      Cancel
                    </Button>
                  </div>
                </div>
              )}
            </>
          )}

          {canClose && (
            <div className="flex gap-2">
              <Button 
                onClick={() => handleAction('close', { comment })}
                disabled={loading}
                className="flex-1"
              >
                {loading ? 'Saving...' : 'Close Issue'}
              </Button>
              {!showCommentForm ? (
                <Button 
                  variant="outline"
                  onClick={() => setShowCommentForm(true)}
                  className="flex-1"
                >
                  Reopen Issue
                </Button>
              ) : (
                <div className="flex-1 space-y-2">
                  <Textarea
                    value={comment}
                    onChange={(e) => setComment(e.target.value)}
                    placeholder="Why are you reopening this issue?"
                    rows={2}
                    required
                  />
                  <div className="flex gap-2">
                    <Button
                      onClick={() => handleAction('reopen', { comment })}
                      disabled={loading || !comment.trim()}
                      size="sm"
                    >
                      {loading ? 'Saving...' : 'Reopen'}
                    </Button>
                    <Button
                      variant="outline"
                      onClick={() => setShowCommentForm(false)}
                      size="sm"
                    >
                      Cancel
                    </Button>
                  </div>
                </div>
              )}
            </div>
          )}

          {canReopen && (
            <>
              {!showCommentForm ? (
                <Button 
                  onClick={() => setShowCommentForm(true)}
                  variant="outline"
                  className="w-full"
                >
                  Reopen Issue
                </Button>
              ) : (
                <div className="space-y-2">
                  <Label>Reason for Reopening</Label>
                  <Textarea
                    value={comment}
                    onChange={(e) => setComment(e.target.value)}
                    placeholder="Why are you reopening this issue?"
                    rows={3}
                    required
                  />
                  <div className="flex gap-2">
                    <Button
                      onClick={() => handleAction('reopen', { comment })}
                      disabled={loading || !comment.trim()}
                    >
                      {loading ? 'Saving...' : 'Reopen'}
                    </Button>
                    <Button
                      variant="outline"
                      onClick={() => setShowCommentForm(false)}
                    >
                      Cancel
                    </Button>
                  </div>
                </div>
              )}
            </>
          )}
        </div>

        {/* Comments Section */}
        {canComment && (
          <>
            <Separator />
            <div className="space-y-4">
              <Label className="text-sm font-medium">Comments</Label>
              
              {/* Existing Comments */}
              {report.comments && report.comments.length > 0 && (
                <div className="space-y-3 max-h-60 overflow-y-auto bg-gray-50 p-4 rounded-lg">
                  {report.comments.map((comment) => (
                    <div key={comment.id} className="bg-card p-3 rounded-lg shadow-sm border">
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
              <div className="space-y-2">
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
                    size="sm"
                  >
                    <MessageCircle className="w-4 h-4 mr-2" />
                    {loading ? 'Saving...' : 'Add Comment'}
                  </Button>
                </div>
              </div>
            </div>
          </>
        )}

        {activityLogs.length > 0 && (
          <>
            <Separator />
            <div>
              <Label className="text-sm font-medium mb-3 block">Activity Log</Label>
              <div className="space-y-3 max-h-60 overflow-y-auto">
                {activityLogs.map((log) => (
                  <div key={log.id} className="flex gap-3 text-sm">
                    <div className="w-6 h-6 rounded-full bg-blue-100 flex items-center justify-center flex-shrink-0">
                      <MessageCircle className="w-3 h-3 text-blue-600" />
                    </div>
                    <div className="flex-1">
                      <div className="flex items-center gap-2 mb-1">
                        <span className="font-medium">{log.user.name}</span>
                        <span className="text-muted-foreground">
                          {formatAction(log.action)}
                        </span>
                        <span className="text-xs text-muted-foreground">
                          {new Date(log.created_at).toLocaleString()}
                        </span>
                      </div>
                    </div>
                  </div>
                ))}
              </div>
            </div>
          </>
        )}
      </div>
    </div>
  )
}