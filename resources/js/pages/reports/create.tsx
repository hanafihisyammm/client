import { useState, useCallback, useEffect } from 'react'
import { Head, router, usePage } from '@inertiajs/react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { DatePicker } from '@/components/ui/date-picker'
import {
  Upload, X, ArrowLeft, Bug, Sparkles, AlertTriangle, ArrowUpRight,
  AlertCircle, Info, FileImage, File, Calendar, Link2
} from 'lucide-react'
import { SearchableSelect } from '@/components/searchable-select'
import { PageUrlFields, checkPageUrls } from '@/components/page-url-fields'
import AppLayout from '@/layouts/app-layout'
import { flashError, flashSuccess } from '@/lib/flash'
import { goBack } from '@/lib/navigation'

interface Project {
  id: number
  name: string
  prefix?: string
  status?: 'active' | 'inactive' | 'completed'
}

interface PageProps extends Record<string, unknown> {
  userRole: 'admin' | 'super_admin' | 'manager' | 'staff'
  userId: number
  projects: Project[]
}

type IssueType = 'bug' | 'feature'

const priorityOptions = [
  { value: 'low', label: 'Low', icon: Info, color: 'text-blue-600', bg: 'bg-blue-50', description: 'Nice to have' },
  { value: 'medium', label: 'Medium', icon: AlertCircle, color: 'text-yellow-600', bg: 'bg-yellow-50', description: 'Should be fixed soon' },
  { value: 'high', label: 'High', icon: ArrowUpRight, color: 'text-orange-600', bg: 'bg-orange-50', description: 'Needs attention' },
  { value: 'urgent', label: 'Urgent', icon: AlertTriangle, color: 'text-red-600', bg: 'bg-red-50', description: 'Critical, fix immediately' },
]

const severityOptions = [
  { value: 'trivial', label: 'Trivial', description: 'Cosmetic issue, no impact on functionality' },
  { value: 'minor', label: 'Minor', description: 'Minor issue with easy workaround' },
  { value: 'major', label: 'Major', description: 'Major feature broken, limited workaround' },
  { value: 'critical', label: 'Critical', description: 'System down or data loss' },
]

export default function CreateReport() {
  const { userRole, userId, projects } = usePage<PageProps>().props
  const [issueType, setIssueType] = useState<IssueType>('bug')
  const [title, setTitle] = useState('')
  const [description, setDescription] = useState('')
  const [pageUrls, setPageUrls] = useState<string[]>([''])
  const hasPageUrl = pageUrls.some(url => url.trim())
  const [projectId, setProjectId] = useState<string>('')
  const [dueDate, setDueDate] = useState('')
  const [priority, setPriority] = useState('medium')
  const [severity, setSeverity] = useState('minor')
  const [attachments, setAttachments] = useState<File[]>([])
  const [loading, setLoading] = useState(false)
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const [isDragging, setIsDragging] = useState(false)

  const selectedProject = projects.find(p => p.id.toString() === projectId)
  // Every project is listed, but an issue can only be created in an active one
  const hasActiveProject = projects.some(p => p.status === 'active')
  const selectedPriority = priorityOptions.find(p => p.value === priority)
  const PriorityIcon = selectedPriority?.icon || AlertCircle

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    setLoading(true)
    setErrors({})

    try {
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

      // Blank rows are dropped, so the rows on screen line up with any errors the server sends back
      const pageUrlCheck = checkPageUrls(pageUrls, true)
      setPageUrls(pageUrlCheck.urls.length > 0 ? pageUrlCheck.urls : [''])
      if (Object.keys(pageUrlCheck.errors).length > 0) {
        setErrors(pageUrlCheck.errors)
        setLoading(false)
        return
      }

      const submitData = new FormData()
      submitData.append('title', title)
      submitData.append('description', description)
      pageUrlCheck.urls.forEach((url, index) => {
        submitData.append(`page_urls[${index}]`, url)
      })
      submitData.append('project_id', projectId)
      submitData.append('priority', priority)
      submitData.append('type', issueType)
      // Severity only describes bugs; a feature request is saved without one
      if (issueType === 'bug') {
        submitData.append('severity', severity)
      }
      if (dueDate) {
        submitData.append('due_date', dueDate)
      }

      attachments.forEach((file, index) => {
        submitData.append(`attachments[${index}]`, file)
      })

      const tokenResponse = await fetch('/api/csrf-token', {
        credentials: 'same-origin'
      })

      let csrfToken = ''
      if (tokenResponse.ok) {
        const tokenData = await tokenResponse.json()
        csrfToken = tokenData.token
        const metaTag = document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement
        if (metaTag) {
          metaTag.content = csrfToken
        }
      } else {
        csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
      }

      if (!csrfToken) {
        flashError('Your session has expired. Please refresh the page and try again.')
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
        flashSuccess(data.message, 'Issue created successfully.')
        router.get('/dashboard')
      } else {
        if (data.errors) {
          setErrors(data.errors)
        } else {
          flashError(data.message, 'Failed to create issue.')
        }
      }
    } catch (error) {
      flashError('Network error. Please try again.')
    } finally {
      setLoading(false)
    }
  }

  const handleFileChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const files = Array.from(e.target.files || [])
    addFiles(files)
  }

  const addFiles = (files: File[]) => {
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

  const handleDragOver = useCallback((e: React.DragEvent) => {
    e.preventDefault()
    setIsDragging(true)
  }, [])

  const handleDragLeave = useCallback((e: React.DragEvent) => {
    e.preventDefault()
    setIsDragging(false)
  }, [])

  const handleDrop = useCallback((e: React.DragEvent) => {
    e.preventDefault()
    setIsDragging(false)
    const files = Array.from(e.dataTransfer.files)
    addFiles(files)
  }, [attachments])

  // A screenshot pasted anywhere on the page is attached, so it does not have
  // to be saved to a file first. Text pastes are left alone.
  useEffect(() => {
    const handlePaste = (e: ClipboardEvent) => {
      const files = Array.from(e.clipboardData?.files ?? [])
      if (files.length === 0) return
      e.preventDefault()
      // Clipboard images all arrive as "image.png"; give each a distinct name
      const stamp = new Date().toISOString().replace(/[:.]/g, '-')
      addFiles(files.map((file, i) => file.name === 'image.png'
        ? new window.File([file], `screenshot-${stamp}${files.length > 1 ? `-${i + 1}` : ''}.png`, { type: file.type })
        : file
      ))
    }
    document.addEventListener('paste', handlePaste)
    return () => document.removeEventListener('paste', handlePaste)
  }, [attachments])

  const getFileIcon = (file: File) => {
    if (file.type === 'application/pdf') {
      return <File className="w-5 h-5 text-red-500" />
    }
    return <FileImage className="w-5 h-5 text-blue-500" />
  }

  return (
    <AppLayout>
      <Head title="Create Issue" />

      <div className="space-y-6">
        {/* Header */}
        <div className="flex items-center gap-4">
          <Button
            variant="outline"
            size="sm"
            onClick={() => goBack('/dashboard')}
          >
            <ArrowLeft className="w-4 h-4 mr-2" />
            Back
          </Button>

          <div className="flex-1">
            <h1 className="text-3xl font-extrabold tracking-tight">Create Issue</h1>
            <p className="text-muted-foreground text-sm mt-1">
              Report a bug or request a new feature
            </p>
          </div>
        </div>

        {/* Main Content */}
        <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
          {/* Left Column - Main Form */}
          <div className="lg:col-span-2 space-y-6">
            <form onSubmit={handleSubmit} className="space-y-6">
              {/* Issue Type Selector */}
              <div className="bg-card border rounded-xl p-6">
                <Label className="text-sm font-medium text-gray-700 mb-3 block">Issue Type</Label>
                <div className="grid grid-cols-2 gap-3">
                  <button
                    type="button"
                    onClick={() => setIssueType('bug')}
                    className={`flex items-center gap-3 p-4 rounded-xl border-2 transition-all ${
                      issueType === 'bug'
                        ? 'border-red-500 bg-red-50'
                        : 'border-gray-200 hover:border-gray-300 bg-card'
                    }`}
                  >
                    <div className={`p-2 rounded-lg ${issueType === 'bug' ? 'bg-red-100' : 'bg-gray-100'}`}>
                      <Bug className={`w-5 h-5 ${issueType === 'bug' ? 'text-red-600' : 'text-gray-500'}`} />
                    </div>
                    <div className="text-left">
                      <p className={`font-medium ${issueType === 'bug' ? 'text-red-900' : 'text-gray-900'}`}>Bug</p>
                      <p className="text-xs text-gray-500">Something isn't working</p>
                    </div>
                  </button>
                  <button
                    type="button"
                    onClick={() => setIssueType('feature')}
                    className={`flex items-center gap-3 p-4 rounded-xl border-2 transition-all ${
                      issueType === 'feature'
                        ? 'border-purple-500 bg-purple-50'
                        : 'border-gray-200 hover:border-gray-300 bg-card'
                    }`}
                  >
                    <div className={`p-2 rounded-lg ${issueType === 'feature' ? 'bg-purple-100' : 'bg-gray-100'}`}>
                      <Sparkles className={`w-5 h-5 ${issueType === 'feature' ? 'text-purple-600' : 'text-gray-500'}`} />
                    </div>
                    <div className="text-left">
                      <p className={`font-medium ${issueType === 'feature' ? 'text-purple-900' : 'text-gray-900'}`}>Feature</p>
                      <p className="text-xs text-gray-500">New functionality request</p>
                    </div>
                  </button>
                </div>
              </div>

              {/* Project & Title */}
              <div className="bg-card border rounded-xl p-6 space-y-4">
                {!hasActiveProject && (
                  <div role="alert" className="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                    <p className="font-medium">There are no active projects</p>
                    <p className="mt-1">
                      An issue has to belong to an active project. Set a project's status to Active, or create a new project, then come back here.
                    </p>
                    <Button type="button" variant="outline" size="sm" className="mt-3 bg-card" onClick={() => router.get('/projects')}>
                      Go to Projects
                    </Button>
                  </div>
                )}
                {projects && projects.length > 0 && (
                  <div className="space-y-2">
                    <Label htmlFor="project" className="text-sm font-medium text-gray-700">
                      Project <span className="text-red-600">*</span>
                    </Label>
                    <SearchableSelect
                      value={projectId}
                      onChange={setProjectId}
                      placeholder="Select a project"
                      searchPlaceholder="Search projects..."
                      emptyText="No projects found"
                      options={projects.map((project) => ({
                        value: project.id.toString(),
                        label: project.prefix ? `${project.name} (${project.prefix})` : project.name,
                        hint: project.status !== 'active' ? `${project.status}, cannot take new issues` : undefined,
                        disabled: project.status !== 'active',
                      }))}
                      className="h-11 w-full bg-card"
                      contentClassName="w-(--radix-popover-trigger-width)"
                    />
                    {errors.project_id && (
                      <p className="text-red-600 text-sm">{errors.project_id[0]}</p>
                    )}
                  </div>
                )}

                <div className="space-y-2">
                  <Label htmlFor="title" className="text-sm font-medium text-gray-700">
                    Summary <span className="text-red-600">*</span>
                  </Label>
                  <Input
                    id="title"
                    value={title}
                    onChange={(e) => setTitle(e.target.value)}
                    placeholder={issueType === 'bug' ? "e.g., Login button not working on mobile" : "e.g., Add dark mode support"}
                    className="h-11"
                    required
                  />
                  {errors.title && (
                    <p className="text-red-600 text-sm">{errors.title[0]}</p>
                  )}
                </div>

                <div className="space-y-2">
                  <Label htmlFor="page_url" className="text-sm font-medium text-gray-700 flex items-center gap-2">
                    <Link2 className="w-4 h-4 text-gray-400" />
                    <span>Page URL <span className="text-red-600">*</span></span>
                  </Label>
                  <p className="text-xs text-gray-500">
                    {issueType === 'bug'
                      ? 'Copy the address of the page where the problem happens from your browser. Add more if it happens on other pages too.'
                      : 'Copy the address of the page this feature is for from your browser. Add more if it is for other pages too.'}
                  </p>
                  <PageUrlFields urls={pageUrls} onChange={setPageUrls} errors={errors} required />
                </div>
              </div>

              {/* Description */}
              <div className="bg-card border rounded-xl p-6 space-y-4">
                <div className="space-y-2">
                  <Label htmlFor="description" className="text-sm font-medium text-gray-700">
                    Description <span className="text-red-600">*</span>
                  </Label>
                  <Textarea
                    id="description"
                    value={description}
                    onChange={(e) => setDescription(e.target.value)}
                    placeholder={issueType === 'bug'
                      ? "Steps to reproduce:\n1. Go to...\n2. Click on...\n3. See error\n\nExpected behavior:\nWhat should happen\n\nActual behavior:\nWhat actually happens"
                      : "Describe the feature you'd like:\n\nWhy is this needed:\n\nPossible solution:"}
                    rows={10}
                    className="resize-none font-mono text-sm"
                    required
                  />
                  {errors.description && (
                    <p className="text-red-600 text-sm">{errors.description[0]}</p>
                  )}
                </div>
              </div>

              {/* Attachments */}
              <div className="bg-card border rounded-xl p-6 space-y-4">
                <Label className="text-sm font-medium text-gray-700">Attachments</Label>
                <div
                  onDragOver={handleDragOver}
                  onDragLeave={handleDragLeave}
                  onDrop={handleDrop}
                  className={`border-2 border-dashed rounded-xl p-8 transition-all ${
                    isDragging
                      ? 'border-blue-500 bg-blue-50'
                      : 'border-gray-200 hover:border-gray-300'
                  }`}
                >
                  <input
                    type="file"
                    id="attachments"
                    multiple
                    accept="image/jpeg,image/png,image/gif,image/webp,application/pdf"
                    onChange={handleFileChange}
                    className="hidden"
                  />
                  <label
                    htmlFor="attachments"
                    className="flex flex-col items-center justify-center cursor-pointer"
                  >
                    <div className={`p-3 rounded-full mb-3 ${isDragging ? 'bg-blue-100' : 'bg-gray-100'}`}>
                      <Upload className={`w-6 h-6 ${isDragging ? 'text-blue-600' : 'text-gray-400'}`} />
                    </div>
                    <p className="text-sm text-gray-700 font-medium mb-1">
                      Drop files here, click to upload, or paste a screenshot
                    </p>
                    <p className="text-xs text-gray-500">
                      PNG, JPG, GIF, PDF up to 5MB (max 5 files)
                    </p>
                  </label>
                </div>

                {attachments.length > 0 && (
                  <div className="space-y-2">
                    {attachments.map((file, index) => (
                      <div key={index} className="flex items-center gap-3 p-3 bg-gray-50 rounded-lg border">
                        {getFileIcon(file)}
                        <div className="flex-1 min-w-0">
                          <p className="text-sm font-medium text-gray-900 truncate">{file.name}</p>
                          <p className="text-xs text-gray-500">
                            {(file.size / 1024 / 1024).toFixed(2)} MB
                          </p>
                        </div>
                        <Button
                          type="button"
                          variant="ghost"
                          size="sm"
                          onClick={() => removeAttachment(index)}
                          className="text-gray-400 hover:text-red-600"
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
            </form>
          </div>

          {/* Right Column - Details */}
          <div className="space-y-6">
            {/* Priority & Severity */}
            <div className="bg-card border rounded-xl p-6 space-y-5">
              <h3 className="font-medium text-gray-900">Details</h3>

              {/* Priority */}
              <div className="space-y-2">
                <Label className="text-sm font-medium text-gray-700">Priority</Label>
                <Select value={priority} onValueChange={setPriority}>
                  <SelectTrigger className="h-11">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {priorityOptions.map((option) => {
                      const Icon = option.icon
                      return (
                        <SelectItem key={option.value} value={option.value}>
                          <div className="flex items-center gap-2">
                            <Icon className={`w-4 h-4 ${option.color}`} />
                            <span>{option.label}</span>
                          </div>
                        </SelectItem>
                      )
                    })}
                  </SelectContent>
                </Select>
                <p className="text-xs text-gray-500">{selectedPriority?.description}</p>
              </div>

              {/* Severity (only for bugs) */}
              {issueType === 'bug' && (
                <div className="space-y-2">
                  <Label className="text-sm font-medium text-gray-700">Severity</Label>
                  <Select value={severity} onValueChange={setSeverity}>
                    <SelectTrigger className="h-11">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      {severityOptions.map((option) => (
                        <SelectItem key={option.value} value={option.value}>
                          {option.label}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                  <p className="text-xs text-gray-500">
                    {severityOptions.find(s => s.value === severity)?.description}
                  </p>
                </div>
              )}

              {/* Due Date */}
              <div className="space-y-2">
                <Label className="text-sm font-medium text-gray-700 flex items-center gap-2">
                  <Calendar className="w-4 h-4 text-gray-400" />
                  Due Date
                </Label>
                <DatePicker
                  value={dueDate}
                  onChange={setDueDate}
                  placeholder="Select date"
                  className="w-full"
                />
                <p className="text-xs text-gray-500">Optional deadline for this issue</p>
              </div>
            </div>

            {/* Preview Card */}
            {(title || selectedProject) && (
              <div className="bg-card border rounded-xl p-6 space-y-4">
                <h3 className="font-medium text-gray-900">Preview</h3>
                <div className="p-4 bg-gray-50 rounded-lg border space-y-2">
                  {selectedProject && (
                    <p className="text-xs font-mono text-blue-600">
                      {selectedProject.prefix || 'PROJ'}-???
                    </p>
                  )}
                  <p className="font-medium text-gray-900">
                    {title || 'Issue title...'}
                  </p>
                  <div className="flex items-center gap-2 flex-wrap">
                    <span className={`inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium ${
                      issueType === 'bug' ? 'bg-red-100 text-red-700' : 'bg-purple-100 text-purple-700'
                    }`}>
                      {issueType === 'bug' ? <Bug className="w-3 h-3" /> : <Sparkles className="w-3 h-3" />}
                      {issueType === 'bug' ? 'Bug' : 'Feature'}
                    </span>
                    <span className={`inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium ${selectedPriority?.bg} ${selectedPriority?.color}`}>
                      <PriorityIcon className="w-3 h-3" />
                      {selectedPriority?.label}
                    </span>
                    <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-700">
                      Open
                    </span>
                  </div>
                </div>
              </div>
            )}

            {/* Submit Button */}
            <div className="space-y-3">
              <Button
                onClick={handleSubmit}
                disabled={loading || !title.trim() || !description.trim() || !projectId || !hasPageUrl}
                className="w-full h-11"
              >
                {loading ? 'Creating...' : 'Create Issue'}
              </Button>
              {!loading && (!projectId || !title.trim() || !description.trim() || !hasPageUrl) && (
                <p className="text-center text-xs text-muted-foreground">
                  {!hasActiveProject
                    ? 'No active project to create the issue in.'
                    : `Still needed: ${[!projectId && 'project', !title.trim() && 'summary', !hasPageUrl && 'page URL', !description.trim() && 'description'].filter(Boolean).join(', ')}.`}
                </p>
              )}
              <Button
                type="button"
                variant="outline"
                onClick={() => router.get('/dashboard')}
                className="w-full h-11"
              >
                Cancel
              </Button>
            </div>
          </div>
        </div>
      </div>
    </AppLayout>
  )
}
