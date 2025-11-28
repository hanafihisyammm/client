import { useState } from 'react'
import { Head, router, usePage } from '@inertiajs/react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import { Card } from '@/components/ui/card'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { DatePicker } from '@/components/ui/date-picker'
import { Upload, X, ArrowLeft } from 'lucide-react'
import AppLayout from '@/layouts/app-layout'

interface Project {
  id: number
  name: string
}

interface PageProps extends Record<string, unknown> {
  userRole: 'client' | 'admin' | 'super_admin'
  userId: number
  projects: Project[]
}

export default function CreateReport() {
  const { userRole, userId, projects } = usePage<PageProps>().props
  const [title, setTitle] = useState('')
  const [description, setDescription] = useState('')
  const [projectId, setProjectId] = useState<string>('')
  const [dueDate, setDueDate] = useState('')
  const [priority, setPriority] = useState('medium')
  const [attachments, setAttachments] = useState<File[]>([])
  const [loading, setLoading] = useState(false)
  const [errors, setErrors] = useState<Record<string, string[]>>({})

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    setLoading(true)
    setErrors({})

    try {
      // Validate required fields
      if (!title.trim()) {
        setErrors({ title: ['Title is required'] })
        setLoading(false)
        return
      }
      
      if (!description.trim()) {
        setErrors({ description: ['Description is required'] })
        setLoading(false)
        return
      }
      
      if (!projectId) {
        setErrors({ project_id: ['Project selection is required'] })
        setLoading(false)
        return
      }

      const submitData = new FormData()
      submitData.append('title', title)
      submitData.append('description', description)
      submitData.append('project_id', projectId)
      submitData.append('priority', priority)
      if (dueDate) {
        submitData.append('due_date', dueDate)
      }
      
      attachments.forEach((file, index) => {
        submitData.append(`attachments[${index}]`, file)
      })

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
        csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
      }

      if (!csrfToken) {
        setErrors({ general: ['CSRF token not found. Please refresh the page.'] })
        setLoading(false)
        return
      }

      const response = await fetch('/api/reports', {
        method: 'POST',
        headers: {
          'X-CSRF-TOKEN': csrfToken,
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest'
        },
        credentials: 'same-origin',
        body: submitData
      })

      const data = await response.json()

      if (response.ok) {
        router.get('/dashboard')
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
    
    const allowedFiles = files.filter(file => 
      file.type.startsWith('image/') || file.type === 'application/pdf'
    )
    if (allowedFiles.length !== files.length) {
      setErrors({ attachments: ['Only image files and PDFs are allowed'] })
      return
    }

    // Check file size limit (5MB)
    const oversizedFiles = allowedFiles.filter(file => file.size > 5 * 1024 * 1024)
    if (oversizedFiles.length > 0) {
      setErrors({ attachments: ['Each file must be 5MB or smaller'] })
      return
    }

    setAttachments(prev => [...prev, ...allowedFiles])
    setErrors(prev => ({ ...prev, attachments: [] }))
  }

  const removeAttachment = (index: number) => {
    setAttachments(prev => prev.filter((_, i) => i !== index))
  }

  return (
    <AppLayout>
      <Head title="Create New Report" />
      
      <div className="space-y-6 px-4 py-6">
        {/* Header */}
        <div className="flex items-center gap-4">
          <Button 
            variant="outline" 
            size="sm"
            onClick={() => router.get('/dashboard')}
          >
            <ArrowLeft className="w-4 h-4 mr-2" />
            Back to Dashboard
          </Button>
          
          <div className="flex-1">
            <h1 className="text-2xl font-bold">Create New Report</h1>
            <p className="text-muted-foreground">
              Report an issue or request a new feature
            </p>
          </div>
        </div>

        {/* Main Content */}
        <div className="max-w-4xl">
          <Card className="p-6">
            <form onSubmit={handleSubmit} className="space-y-6">
              {errors.general && (
                <div className="bg-red-50 border border-red-200 rounded-md p-3">
                  {errors.general.map((error, index) => (
                    <p key={index} className="text-red-600 text-sm">{error}</p>
                  ))}
                </div>
              )}

              <div className="space-y-2">
                <Label htmlFor="title">Report Title *</Label>
                <Input
                  id="title"
                  value={title}
                  onChange={(e) => setTitle(e.target.value)}
                  placeholder="Brief summary of the issue or request"
                  required
                />
                {errors.title && (
                  <p className="text-red-600 text-sm">{errors.title[0]}</p>
                )}
              </div>

              {projects && projects.length > 0 && (
                <div className="space-y-2">
                  <Label htmlFor="project">Project *</Label>
                  <Select value={projectId} onValueChange={setProjectId}>
                    <SelectTrigger>
                      <SelectValue placeholder="Select a project" />
                    </SelectTrigger>
                    <SelectContent>
                      {projects.map((project) => (
                        <SelectItem key={project.id} value={project.id.toString()}>
                          {project.name}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                  {errors.project_id && (
                    <p className="text-red-600 text-sm">{errors.project_id[0]}</p>
                  )}
                  <p className="text-sm text-gray-500">
                    All reports must be assigned to a project.
                  </p>
                </div>
              )}

              <div className="space-y-2">
                <Label htmlFor="description">What issue or requirement would you like to report? *</Label>
                <Textarea
                  id="description"
                  value={description}
                  onChange={(e) => setDescription(e.target.value)}
                  placeholder="Please describe the issue or requirement in detail. Include steps to reproduce if it's a bug, or specific requirements if it's a feature request."
                  rows={8}
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

              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div className="space-y-2">
                  <Label htmlFor="priority">Priority *</Label>
                  <Select value={priority} onValueChange={setPriority}>
                    <SelectTrigger>
                      <SelectValue placeholder="Select priority" />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="low">Low</SelectItem>
                      <SelectItem value="medium">Medium</SelectItem>
                      <SelectItem value="high">High</SelectItem>
                      <SelectItem value="urgent">Urgent</SelectItem>
                    </SelectContent>
                  </Select>
                  {errors.priority && (
                    <p className="text-red-600 text-sm">{errors.priority[0]}</p>
                  )}
                </div>

                <div className="space-y-2">
                  <Label htmlFor="due_date">Due Date</Label>
                  <DatePicker
                    id="due_date"
                    value={dueDate}
                    onChange={setDueDate}
                    placeholder="Select due date"
                    className="w-full"
                  />
                  {errors.due_date && (
                    <p className="text-red-600 text-sm">{errors.due_date[0]}</p>
                  )}
                  <p className="text-sm text-gray-500">
                    Optional: Set when this report should be resolved.
                  </p>
                </div>
              </div>

              <div className="space-y-4">
                <Label>Attachments (Optional)</Label>
                <div className="border-2 border-dashed border-gray-300 rounded-lg p-8 hover:border-gray-400 transition-colors">
                  <input
                    type="file"
                    id="attachments"
                    multiple
                    accept="image/*,.pdf"
                    onChange={handleFileChange}
                    className="hidden"
                  />
                  <label
                    htmlFor="attachments"
                    className="flex flex-col items-center justify-center cursor-pointer"
                  >
                    <Upload className="w-12 h-12 text-gray-400 mb-4" />
                    <p className="text-lg text-gray-700 font-medium mb-2">
                      Click to upload screenshots or images
                    </p>
                    <p className="text-sm text-gray-500">
                      PNG, JPG, PDF up to 5MB each (max 5 files)
                    </p>
                  </label>
                </div>
                <p className="text-sm text-gray-500">
                  Screenshots help us understand the issue better and resolve it faster.
                </p>

                {attachments.length > 0 && (
                  <div className="space-y-2">
                    <Label>Selected Files:</Label>
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
                      {attachments.map((file, index) => (
                        <div key={index} className="flex items-center justify-between bg-gray-50 p-3 rounded-lg border">
                          <div className="flex-1">
                            <p className="text-sm font-medium truncate">{file.name}</p>
                            <p className="text-xs text-gray-500">
                              {(file.size / 1024 / 1024).toFixed(2)} MB
                            </p>
                          </div>
                          <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={() => removeAttachment(index)}
                            className="ml-2 text-red-600 hover:text-red-800"
                          >
                            <X className="w-4 h-4" />
                          </Button>
                        </div>
                      ))}
                    </div>
                  </div>
                )}

                {errors.attachments && (
                  <p className="text-red-600 text-sm">{errors.attachments[0]}</p>
                )}
              </div>

              <div className="flex justify-end space-x-3 pt-6 border-t">
                <Button 
                  type="button" 
                  variant="outline" 
                  onClick={() => router.get('/dashboard')}
                >
                  Cancel
                </Button>
                <Button 
                  type="submit" 
                  disabled={loading || !title.trim() || !description.trim() || !projectId}
                  className="min-w-[120px]"
                >
                  {loading ? 'Creating...' : 'Create Report'}
                </Button>
              </div>
            </form>
          </Card>
        </div>
      </div>
    </AppLayout>
  )
}