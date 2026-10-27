import { useState } from 'react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Textarea } from '@/components/ui/textarea'
import { DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Upload, X } from 'lucide-react'
import { ATTACHMENT_ACCEPT, isAllowedAttachment, maxAttachmentBytes } from '@/lib/attachments'

interface CreateReportFormProps {
  onSuccess: () => void
}

export default function CreateReportForm({ onSuccess }: CreateReportFormProps) {
  const [description, setDescription] = useState('')
  const [attachments, setAttachments] = useState<File[]>([])
  const [loading, setLoading] = useState(false)
  const [errors, setErrors] = useState<Record<string, string[]>>({})

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    setLoading(true)
    setErrors({})

    try {
      const submitData = new FormData()
      submitData.append('description', description)
      
      attachments.forEach((file, index) => {
        submitData.append(`attachments[${index}]`, file)
      })

      const response = await fetch('/api/reports', {
        method: 'POST',
        headers: {
          'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
          'Accept': 'application/json'
        },
        body: submitData
      })

      const data = await response.json()

      if (response.ok) {
        onSuccess()
        setDescription('')
        setAttachments([])
      } else {
        if (data.errors) {
          setErrors(data.errors)
        } else {
          setErrors({ general: [data.message || 'Failed to create report'] })
        }
      }
    } catch (error) {
      setErrors({ general: ['Network error. Please try again.'] })
    } finally {
      setLoading(false)
    }
  }

  const handleFileChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const files = Array.from(e.target.files || [])
    if (attachments.length + files.length > 5) {
      setErrors({ attachments: ['You can only upload up to 5 files'] })
      return
    }
    
    const allowedFiles = files.filter(isAllowedAttachment)
    if (allowedFiles.length !== files.length) {
      setErrors({ attachments: ['Only images, PDFs, videos (MP4, WebM, MOV) and Excel or CSV files are allowed'] })
      return
    }

    // Check file size limit (5MB)
    const oversizedFiles = allowedFiles.filter(file => file.size > maxAttachmentBytes(file))
    if (oversizedFiles.length > 0) {
      setErrors({ attachments: ['Images, PDFs and spreadsheets must be 5MB or smaller, videos 25MB or smaller'] })
      return
    }

    setAttachments(prev => [...prev, ...allowedFiles])
    setErrors(prev => ({ ...prev, attachments: [] }))
  }

  const removeAttachment = (index: number) => {
    setAttachments(prev => prev.filter((_, i) => i !== index))
  }

  return (
    <div>
      <DialogHeader>
        <DialogTitle>Create New Report</DialogTitle>
      </DialogHeader>

      <form onSubmit={handleSubmit} className="space-y-6 mt-4">
        {errors.general && (
          <div className="bg-red-50 border border-red-200 rounded-md p-3">
            {errors.general.map((error, index) => (
              <p key={index} className="text-red-600 text-sm">{error}</p>
            ))}
          </div>
        )}

        <div className="space-y-2">
          <Label htmlFor="description">What issue or requirement would you like to report? *</Label>
          <Textarea
            id="description"
            value={description}
            onChange={(e) => setDescription(e.target.value)}
            placeholder="Please describe the issue or requirement in detail. Include steps to reproduce if it's a bug, or specific requirements if it's a feature request."
            rows={6}
            className="resize-none"
            required
          />
          {errors.description && (
            <p className="text-red-600 text-sm">{errors.description[0]}</p>
          )}
          <p className="text-sm text-gray-500">
            Be as detailed as possible to help our team understand and resolve your request quickly.
          </p>
        </div>

        <div className="space-y-2">
          <Label>Attachments (Optional)</Label>
          <div className="border-2 border-dashed border-gray-300 rounded-lg p-6 hover:border-gray-400 transition-colors">
            <input
              type="file"
              id="attachments"
              multiple
              accept={ATTACHMENT_ACCEPT}
              onChange={handleFileChange}
              className="hidden"
            />
            <label
              htmlFor="attachments"
              className="flex flex-col items-center justify-center cursor-pointer"
            >
              <Upload className="w-10 h-10 text-gray-400 mb-3" />
              <p className="text-sm text-gray-700 font-medium mb-1">
                Click to upload screenshots, images or screen recordings
              </p>
              <p className="text-xs text-gray-500">
                PNG, JPG, PDF, XLSX, XLS, CSV up to 5MB · MP4, WebM, MOV up to 25MB (max 5 files)
              </p>
            </label>
          </div>
          <p className="text-sm text-gray-500">
            Screenshots help us understand the issue better and resolve it faster.
          </p>

          {attachments.length > 0 && (
            <div className="space-y-2">
              {attachments.map((file, index) => (
                <div key={index} className="flex items-center justify-between bg-gray-50 p-2 rounded">
                  <span className="text-sm truncate">{file.name}</span>
                  <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    onClick={() => removeAttachment(index)}
                  >
                    <X className="w-4 h-4" />
                  </Button>
                </div>
              ))}
            </div>
          )}

          {errors.attachments && (
            <p className="text-red-600 text-sm">{errors.attachments[0]}</p>
          )}
        </div>

        <div className="flex justify-end space-x-2 pt-4">
          <Button type="button" variant="outline" onClick={onSuccess}>
            Cancel
          </Button>
          <Button type="submit" disabled={loading}>
            {loading ? 'Creating...' : 'Create Report'}
          </Button>
        </div>
      </form>
    </div>
  )
}