import { useState } from 'react'
import { flashError, flashSuccess } from '@/lib/flash'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Card } from '@/components/ui/card'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Badge } from '@/components/ui/badge'
import { Link2, Plus, Trash2, ExternalLink, ArrowRight, ArrowLeft } from 'lucide-react'
import { getCsrfToken } from '@/lib/csrf'

interface LinkedReport {
  id: number
  uuid: string
  title: string
  status: string
  priority: string
}

interface ReportLink {
  id: number
  link_type: string
  target_report?: LinkedReport
  source_report?: LinkedReport
  creator: { id: number; name: string }
}

interface LinkedBugsPanelProps {
  reportUuid: string
  outgoingLinks: ReportLink[]
  incomingLinks: ReportLink[]
  canLinkBugs: boolean
  onUpdate: () => void
}

const linkTypes = {
  duplicates: 'Duplicates',
  is_duplicated_by: 'Is duplicated by',
  blocks: 'Blocks',
  is_blocked_by: 'Is blocked by',
  relates_to: 'Relates to'
}

const statusColors: Record<string, string> = {
  open: 'bg-blue-500',
  in_progress: 'bg-amber-500',
  resolved: 'bg-purple-500',
  closed: 'bg-green-500',
  reopened: 'bg-red-500'
}

export function LinkedBugsPanel({
  reportUuid,
  outgoingLinks,
  incomingLinks,
  canLinkBugs,
  onUpdate
}: LinkedBugsPanelProps) {
  const [showAddForm, setShowAddForm] = useState(false)
  const [loading, setLoading] = useState(false)
  const [targetUuid, setTargetUuid] = useState('')
  const [linkType, setLinkType] = useState('relates_to')

  const fetchCsrfToken = async () => {
    try {
      const response = await fetch('/api/csrf-token', { credentials: 'same-origin' })
      if (response.ok) {
        const data = await response.json()
        return data.token
      }
    } catch (e) {}
    return getCsrfToken() || ''
  }

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!targetUuid.trim()) return

    setLoading(true)
    try {
      const csrfToken = await fetchCsrfToken()
      const response = await fetch(`/api/reports/${reportUuid}/links`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': csrfToken,
          'Accept': 'application/json'
        },
        credentials: 'same-origin',
        body: JSON.stringify({
          target_report_uuid: targetUuid.trim(),
          link_type: linkType
        })
      })

      const data = await response.json()
      if (response.ok) {
        flashSuccess(data.message, 'Issue linked successfully.')
        setTargetUuid('')
        setLinkType('relates_to')
        setShowAddForm(false)
        onUpdate()
      } else {
        flashError(data.message || 'Failed to link issue')
      }
    } catch (error) {
      flashError('Network error')
    } finally {
      setLoading(false)
    }
  }

  const handleDelete = async (linkId: number) => {
    if (!confirm('Are you sure you want to remove this link?')) return

    setLoading(true)
    try {
      const csrfToken = await fetchCsrfToken()
      const response = await fetch(`/api/reports/${reportUuid}/links/${linkId}`, {
        method: 'DELETE',
        headers: {
          'X-CSRF-TOKEN': csrfToken,
          'Accept': 'application/json'
        },
        credentials: 'same-origin'
      })

      if (response.ok) {
        flashSuccess(null, 'Link removed successfully.')
        onUpdate()
      } else {
        const data = await response.json()
        flashError(data.message || 'Failed to remove link')
      }
    } catch (error) {
      flashError('Network error')
    } finally {
      setLoading(false)
    }
  }

  const totalLinks = outgoingLinks.length + incomingLinks.length

  const renderLinkedReport = (report: LinkedReport, linkType: string, isOutgoing: boolean, linkId: number) => (
    <div key={linkId} className="flex items-center gap-2 p-2 bg-gray-50 rounded-lg text-sm">
      <div className="flex items-center gap-1 text-gray-400">
        {isOutgoing ? <ArrowRight className="w-4 h-4" /> : <ArrowLeft className="w-4 h-4" />}
      </div>
      <Badge variant="outline" className="text-xs">
        {linkTypes[linkType as keyof typeof linkTypes] || linkType}
      </Badge>
      <a
        href={`/reports/${report.uuid}`}
        className="flex-1 text-blue-600 hover:underline truncate"
        title={report.title}
      >
        #{report.id} {report.title}
      </a>
      <Badge className={`${statusColors[report.status]} text-white text-xs`}>
        {report.status.replace(/_/g, ' ')}
      </Badge>
      {canLinkBugs && isOutgoing && (
        <Button
          size="sm"
          variant="ghost"
          className="text-red-500 hover:text-red-700 hover:bg-red-50 h-6 w-6 p-0"
          onClick={() => handleDelete(linkId)}
        >
          <Trash2 className="w-3 h-3" />
        </Button>
      )}
    </div>
  )

  return (
    <Card className="p-6">
      <div className="flex items-center justify-between mb-4">
        <h3 className="text-lg font-semibold flex items-center gap-2">
          <Link2 className="w-5 h-5" />
          Linked Bugs
          {totalLinks > 0 && (
            <Badge variant="secondary" className="ml-1">{totalLinks}</Badge>
          )}
        </h3>
        {canLinkBugs && !showAddForm && (
          <Button size="sm" variant="outline" onClick={() => setShowAddForm(true)}>
            <Plus className="w-4 h-4 mr-1" />
            Link Bug
          </Button>
        )}
      </div>

      {/* Add Link Form */}
      {showAddForm && (
        <form onSubmit={handleSubmit} className="mb-4 p-4 bg-gray-50 rounded-lg space-y-3">
          <div>
            <Label htmlFor="targetUuid">Issue UUID or ID *</Label>
            <Input
              id="targetUuid"
              value={targetUuid}
              onChange={(e) => setTargetUuid(e.target.value)}
              placeholder="Enter the UUID of the issue to link"
              required
            />
            <p className="text-xs text-gray-500 mt-1">
              You can find the UUID in the report's URL
            </p>
          </div>
          <div>
            <Label htmlFor="linkType">Link Type *</Label>
            <Select value={linkType} onValueChange={setLinkType}>
              <SelectTrigger>
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="relates_to">Relates to</SelectItem>
                <SelectItem value="duplicates">Duplicates</SelectItem>
                <SelectItem value="is_duplicated_by">Is duplicated by</SelectItem>
                <SelectItem value="blocks">Blocks</SelectItem>
                <SelectItem value="is_blocked_by">Is blocked by</SelectItem>
              </SelectContent>
            </Select>
          </div>
          <div className="flex gap-2">
            <Button type="submit" size="sm" disabled={loading}>
              {loading ? 'Linking...' : 'Link Issue'}
            </Button>
            <Button type="button" size="sm" variant="outline" onClick={() => setShowAddForm(false)}>
              Cancel
            </Button>
          </div>
        </form>
      )}

      {/* Links List */}
      {totalLinks > 0 ? (
        <div className="space-y-2 max-h-64 overflow-y-auto">
          {outgoingLinks.map((link) =>
            link.target_report && renderLinkedReport(link.target_report, link.link_type, true, link.id)
          )}
          {incomingLinks.map((link) =>
            link.source_report && renderLinkedReport(link.source_report, link.link_type, false, link.id)
          )}
        </div>
      ) : (
        <div className="text-center py-6 text-gray-500">
          <Link2 className="w-8 h-8 mx-auto mb-2 text-gray-300" />
          <p className="text-sm">No linked bugs</p>
          {canLinkBugs && (
            <p className="text-xs text-gray-400">Link related, blocking, or duplicate bugs</p>
          )}
        </div>
      )}
    </Card>
  )
}
