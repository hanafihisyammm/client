import { useState, useEffect } from 'react'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Card } from '@/components/ui/card'
import { Tag, Plus, X, Check } from 'lucide-react'
import { getCsrfToken } from '@/lib/csrf'

interface Label {
  id: number
  name: string
  color: string
  description?: string
}

interface LabelsPanelProps {
  reportUuid: string
  projectId: number
  labels: Label[]
  canEditLabels: boolean
  onUpdate: () => void
}

export function LabelsPanel({
  reportUuid,
  projectId,
  labels,
  canEditLabels,
  onUpdate
}: LabelsPanelProps) {
  const [showSelector, setShowSelector] = useState(false)
  const [loading, setLoading] = useState(false)
  const [availableLabels, setAvailableLabels] = useState<Label[]>([])
  const [selectedLabelIds, setSelectedLabelIds] = useState<number[]>(labels.map(l => l.id))

  useEffect(() => {
    setSelectedLabelIds(labels.map(l => l.id))
  }, [labels])

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

  const fetchAvailableLabels = async () => {
    try {
      const response = await fetch(`/api/projects/${projectId}/labels`, {
        credentials: 'same-origin'
      })
      if (response.ok) {
        const data = await response.json()
        setAvailableLabels(data.labels || [])
      }
    } catch (error) {
      console.error('Failed to fetch labels:', error)
    }
  }

  const handleOpenSelector = () => {
    fetchAvailableLabels()
    setShowSelector(true)
  }

  const toggleLabel = (labelId: number) => {
    setSelectedLabelIds(prev =>
      prev.includes(labelId)
        ? prev.filter(id => id !== labelId)
        : [...prev, labelId]
    )
  }

  const handleSaveLabels = async () => {
    setLoading(true)
    try {
      const csrfToken = await fetchCsrfToken()
      const response = await fetch(`/api/reports/${reportUuid}/labels`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': csrfToken,
          'Accept': 'application/json'
        },
        credentials: 'same-origin',
        body: JSON.stringify({ label_ids: selectedLabelIds })
      })

      if (response.ok) {
        setShowSelector(false)
        onUpdate()
      } else {
        const data = await response.json()
        alert(data.message || 'Failed to update labels')
      }
    } catch (error) {
      alert('Network error')
    } finally {
      setLoading(false)
    }
  }

  return (
    <Card className="p-6">
      <div className="flex items-center justify-between mb-4">
        <h3 className="text-lg font-semibold flex items-center gap-2">
          <Tag className="w-5 h-5" />
          Labels
        </h3>
        {canEditLabels && !showSelector && (
          <Button size="sm" variant="outline" onClick={handleOpenSelector}>
            <Plus className="w-4 h-4 mr-1" />
            Edit
          </Button>
        )}
      </div>

      {/* Label Selector */}
      {showSelector && (
        <div className="mb-4 p-4 bg-gray-50 rounded-lg">
          <p className="text-sm text-gray-600 mb-3">Select labels for this report:</p>
          {availableLabels.length > 0 ? (
            <div className="flex flex-wrap gap-2 mb-4">
              {availableLabels.map((label) => {
                const isSelected = selectedLabelIds.includes(label.id)
                return (
                  <button
                    key={label.id}
                    type="button"
                    onClick={() => toggleLabel(label.id)}
                    className={`inline-flex items-center gap-1 px-3 py-1 rounded-full text-sm font-medium transition-all ${
                      isSelected
                        ? 'ring-2 ring-offset-1 ring-blue-500'
                        : 'opacity-60 hover:opacity-100'
                    }`}
                    style={{
                      backgroundColor: label.color + '20',
                      color: label.color,
                      borderColor: label.color
                    }}
                    title={label.description}
                  >
                    {isSelected && <Check className="w-3 h-3" />}
                    {label.name}
                  </button>
                )
              })}
            </div>
          ) : (
            <p className="text-sm text-gray-500 mb-4">No labels available for this project.</p>
          )}
          <div className="flex gap-2">
            <Button size="sm" onClick={handleSaveLabels} disabled={loading}>
              {loading ? 'Saving...' : 'Save Labels'}
            </Button>
            <Button
              size="sm"
              variant="outline"
              onClick={() => {
                setShowSelector(false)
                setSelectedLabelIds(labels.map(l => l.id))
              }}
            >
              Cancel
            </Button>
          </div>
        </div>
      )}

      {/* Display Labels */}
      {labels.length > 0 ? (
        <div className="flex flex-wrap gap-2">
          {labels.map((label) => (
            <span
              key={label.id}
              className="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium"
              style={{
                backgroundColor: label.color + '20',
                color: label.color
              }}
              title={label.description}
            >
              {label.name}
            </span>
          ))}
        </div>
      ) : (
        !showSelector && (
          <div className="text-center py-4 text-gray-500">
            <Tag className="w-6 h-6 mx-auto mb-2 text-gray-300" />
            <p className="text-sm">No labels</p>
          </div>
        )
      )}
    </Card>
  )
}
