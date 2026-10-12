import { useState } from 'react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import { Card } from '@/components/ui/card'
import { Clock, Plus, Trash2, Edit2, Check, X } from 'lucide-react'
import { getCsrfToken } from '@/lib/csrf'

interface TimeEntry {
  id: number
  hours: number
  description: string | null
  logged_date: string
  user: { id: number; name: string; email: string }
  created_at: string
}

interface TimeTrackingPanelProps {
  reportUuid: string
  timeEntries: TimeEntry[]
  totalTimeSpent: number
  estimatedHours: number | null
  canLogTime: boolean
  currentUserId: number
  onUpdate: () => void
}

export function TimeTrackingPanel({
  reportUuid,
  timeEntries,
  totalTimeSpent,
  estimatedHours,
  canLogTime,
  currentUserId,
  onUpdate
}: TimeTrackingPanelProps) {
  const [showAddForm, setShowAddForm] = useState(false)
  const [loading, setLoading] = useState(false)
  const [editingId, setEditingId] = useState<number | null>(null)
  const [formData, setFormData] = useState({
    hours: '',
    description: '',
    logged_date: new Date().toISOString().split('T')[0]
  })

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
    if (!formData.hours || parseFloat(formData.hours) <= 0) return

    setLoading(true)
    try {
      const csrfToken = await fetchCsrfToken()
      const response = await fetch(`/api/reports/${reportUuid}/time-entries`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': csrfToken,
          'Accept': 'application/json'
        },
        credentials: 'same-origin',
        body: JSON.stringify({
          hours: parseFloat(formData.hours),
          description: formData.description || null,
          logged_date: formData.logged_date
        })
      })

      if (response.ok) {
        setFormData({ hours: '', description: '', logged_date: new Date().toISOString().split('T')[0] })
        setShowAddForm(false)
        onUpdate()
      } else {
        const data = await response.json()
        alert(data.message || 'Failed to log time')
      }
    } catch (error) {
      alert('Network error')
    } finally {
      setLoading(false)
    }
  }

  const handleDelete = async (id: number) => {
    if (!confirm('Are you sure you want to delete this time entry?')) return

    setLoading(true)
    try {
      const csrfToken = await fetchCsrfToken()
      const response = await fetch(`/api/reports/${reportUuid}/time-entries/${id}`, {
        method: 'DELETE',
        headers: {
          'X-CSRF-TOKEN': csrfToken,
          'Accept': 'application/json'
        },
        credentials: 'same-origin'
      })

      if (response.ok) {
        onUpdate()
      } else {
        const data = await response.json()
        alert(data.message || 'Failed to delete')
      }
    } catch (error) {
      alert('Network error')
    } finally {
      setLoading(false)
    }
  }

  const progress = estimatedHours ? Math.min((totalTimeSpent / estimatedHours) * 100, 100) : 0

  return (
    <Card className="p-6">
      <div className="flex items-center justify-between mb-4">
        <h3 className="text-lg font-semibold flex items-center gap-2">
          <Clock className="w-5 h-5" />
          Time Tracking
        </h3>
        {canLogTime && !showAddForm && (
          <Button size="sm" variant="outline" onClick={() => setShowAddForm(true)}>
            <Plus className="w-4 h-4 mr-1" />
            Log Time
          </Button>
        )}
      </div>

      {/* Summary */}
      <div className="grid grid-cols-2 gap-4 mb-4">
        <div className="bg-blue-50 p-3 rounded-lg">
          <div className="text-sm text-blue-600 font-medium">Time Spent</div>
          <div className="text-2xl font-bold text-blue-700">{totalTimeSpent.toFixed(1)}h</div>
        </div>
        <div className="bg-gray-50 p-3 rounded-lg">
          <div className="text-sm text-gray-600 font-medium">Estimated</div>
          <div className="text-2xl font-bold text-gray-700">
            {estimatedHours ? `${estimatedHours}h` : 'Not set'}
          </div>
        </div>
      </div>

      {/* Progress Bar */}
      {estimatedHours && (
        <div className="mb-4">
          <div className="flex justify-between text-xs text-gray-500 mb-1">
            <span>Progress</span>
            <span>{progress.toFixed(0)}%</span>
          </div>
          <div className="w-full bg-gray-200 rounded-full h-2">
            <div
              className={`h-2 rounded-full transition-all ${
                progress > 100 ? 'bg-red-500' : progress > 75 ? 'bg-yellow-500' : 'bg-green-500'
              }`}
              style={{ width: `${Math.min(progress, 100)}%` }}
            />
          </div>
          {progress > 100 && (
            <div className="text-xs text-red-500 mt-1">
              Over estimate by {(totalTimeSpent - estimatedHours).toFixed(1)}h
            </div>
          )}
        </div>
      )}

      {/* Add Time Form */}
      {showAddForm && (
        <form onSubmit={handleSubmit} className="mb-4 p-4 bg-gray-50 rounded-lg space-y-3">
          <div className="grid grid-cols-2 gap-3">
            <div>
              <Label htmlFor="hours">Hours *</Label>
              <Input
                id="hours"
                type="number"
                step="0.25"
                min="0.25"
                max="24"
                value={formData.hours}
                onChange={(e) => setFormData({ ...formData, hours: e.target.value })}
                placeholder="e.g., 2.5"
                required
              />
            </div>
            <div>
              <Label htmlFor="logged_date">Date *</Label>
              <Input
                id="logged_date"
                type="date"
                value={formData.logged_date}
                onChange={(e) => setFormData({ ...formData, logged_date: e.target.value })}
                max={new Date().toISOString().split('T')[0]}
                required
              />
            </div>
          </div>
          <div>
            <Label htmlFor="description">Description</Label>
            <Textarea
              id="description"
              value={formData.description}
              onChange={(e) => setFormData({ ...formData, description: e.target.value })}
              placeholder="What did you work on?"
              rows={2}
            />
          </div>
          <div className="flex gap-2">
            <Button type="submit" size="sm" disabled={loading}>
              {loading ? 'Saving...' : 'Log Time'}
            </Button>
            <Button type="button" size="sm" variant="outline" onClick={() => setShowAddForm(false)}>
              Cancel
            </Button>
          </div>
        </form>
      )}

      {/* Time Entries List */}
      {timeEntries.length > 0 ? (
        <div className="space-y-2 max-h-64 overflow-y-auto">
          {timeEntries.map((entry) => (
            <div key={entry.id} className="flex items-start gap-3 p-3 bg-gray-50 rounded-lg text-sm">
              <div className="flex-1">
                <div className="flex items-center gap-2">
                  <span className="font-medium">{entry.hours}h</span>
                  <span className="text-gray-400">|</span>
                  <span className="text-gray-600">{entry.user.name}</span>
                  <span className="text-gray-400">|</span>
                  <span className="text-gray-500">{new Date(entry.logged_date).toLocaleDateString()}</span>
                </div>
                {entry.description && (
                  <p className="text-gray-600 mt-1">{entry.description}</p>
                )}
              </div>
              {(entry.user.id === currentUserId || canLogTime) && (
                <Button
                  size="sm"
                  variant="ghost"
                  className="text-red-500 hover:text-red-700 hover:bg-red-50"
                  onClick={() => handleDelete(entry.id)}
                >
                  <Trash2 className="w-4 h-4" />
                </Button>
              )}
            </div>
          ))}
        </div>
      ) : (
        <div className="text-center py-6 text-gray-500">
          <Clock className="w-8 h-8 mx-auto mb-2 text-gray-300" />
          <p className="text-sm">No time logged yet</p>
        </div>
      )}
    </Card>
  )
}
