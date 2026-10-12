import { useState, useEffect } from 'react'
import { Head, usePage, router } from '@inertiajs/react'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Input } from '@/components/ui/input'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import {
  Plus, Search, FileText, User as UserIcon, Calendar, ChevronUp, ChevronDown,
  ChevronsUpDown, Eye, Edit, LayoutGrid, List, Clock, AlertTriangle,
  ArrowUpRight, AlertCircle, Info, CheckCircle2, RotateCcw, X, Filter, SlidersHorizontal, AlarmClock,
  type LucideIcon
} from 'lucide-react'
import AppLayout from '@/layouts/app-layout'
import KanbanBoard from '@/components/kanban-board'
import { SearchableSelect } from '@/components/searchable-select'
import type { User } from '@/types'

interface Label {
  id: number
  name: string
  color: string
}

interface Report {
  id: number
  uuid: string
  issue_key?: string
  title: string
  description: string
  project: string | { id: number; name: string; prefix?: string }
  status: 'open' | 'in_progress' | 'resolved' | 'closed' | 'reopened'
  due_date?: string | null
  priority: 'low' | 'medium' | 'high' | 'urgent'
  severity?: 'trivial' | 'minor' | 'major' | 'critical'
  reporter: { id: number; name: string; email: string }
  assignee?: { id: number; name: string; email: string }
  labels?: Label[]
  total_time_spent?: number
  attachments: Array<{
    id: number
    filename: string
    original_filename: string
    storage_path: string
  }>
  created_at: string
  updated_at: string
}

interface Project {
  id: number
  name: string
  prefix?: string
  status?: 'active' | 'inactive' | 'completed'
}

interface PageProps extends Record<string, unknown> {
  auth: {
    user: User & { role: 'client' | 'admin' | 'super_admin' | 'manager' | 'developer' }
  }
  projects: Project[]
  userRole: string
  stats: Record<StatKey, number>
}

type StatKey = 'open' | 'in_progress' | 'resolved' | 'reopened' | 'overdue'

// Counts come from the server so they cover every issue the user can see, not only the rows loaded below
const statCards: Array<{ key: StatKey; label: string; hint: string; icon: LucideIcon; color: string }> = [
  { key: 'open', label: 'Open', hint: 'Not yet started', icon: AlertCircle, color: 'text-blue-600 bg-blue-50' },
  { key: 'in_progress', label: 'In Progress', hint: 'Being worked on', icon: Clock, color: 'text-amber-600 bg-amber-50' },
  { key: 'resolved', label: 'Resolved', hint: 'Waiting for client to confirm', icon: CheckCircle2, color: 'text-purple-600 bg-purple-50' },
  { key: 'reopened', label: 'Reopened', hint: 'Sent back, needs rework', icon: RotateCcw, color: 'text-red-600 bg-red-50' },
  { key: 'overdue', label: 'Overdue', hint: 'Past due date, still open', icon: AlarmClock, color: 'text-orange-600 bg-orange-50' },
]

// New status colors matching Jira
const statusConfig = {
  open: {
    label: 'Open',
    bg: 'bg-blue-100',
    text: 'text-blue-800',
    icon: AlertCircle,
    dot: 'bg-blue-500'
  },
  in_progress: {
    label: 'In Progress',
    bg: 'bg-amber-100',
    text: 'text-amber-800',
    icon: Clock,
    dot: 'bg-amber-500'
  },
  resolved: {
    label: 'Resolved',
    bg: 'bg-purple-100',
    text: 'text-purple-800',
    icon: CheckCircle2,
    dot: 'bg-purple-500'
  },
  closed: {
    label: 'Closed',
    bg: 'bg-green-100',
    text: 'text-green-800',
    icon: CheckCircle2,
    dot: 'bg-green-500'
  },
  reopened: {
    label: 'Reopened',
    bg: 'bg-red-100',
    text: 'text-red-800',
    icon: RotateCcw,
    dot: 'bg-red-500'
  },
}

const priorityConfig = {
  urgent: { icon: AlertTriangle, color: 'text-red-600', bg: 'bg-red-100', label: 'Urgent' },
  high: { icon: ArrowUpRight, color: 'text-orange-600', bg: 'bg-orange-100', label: 'High' },
  medium: { icon: AlertCircle, color: 'text-yellow-600', bg: 'bg-yellow-100', label: 'Medium' },
  low: { icon: Info, color: 'text-blue-600', bg: 'bg-blue-100', label: 'Low' },
}

const severityColors = {
  critical: 'bg-red-600 text-white',
  major: 'bg-orange-500 text-white',
  minor: 'bg-yellow-500 text-white',
  trivial: 'bg-gray-400 text-white'
}

type SortField = 'issue_key' | 'title' | 'project' | 'status' | 'priority' | 'severity' | 'due_date' | 'assignee' | 'created_at'
type SortOrder = 'asc' | 'desc'
type ViewMode = 'list' | 'board'

export default function Dashboard() {
  const { auth, projects, userRole, stats } = usePage<PageProps>().props
  // The filter lists every project; new issues can only be created in active ones
  const activeProjects = projects.filter(project => project.status === 'active')
  const [allReports, setAllReports] = useState<Report[]>([])
  const [filteredReports, setFilteredReports] = useState<Report[]>([])
  const [loading, setLoading] = useState(true)
  const [viewMode, setViewMode] = useState<ViewMode>('board')
  const [sortField, setSortField] = useState<SortField>('created_at')
  const [sortOrder, setSortOrder] = useState<SortOrder>('desc')
  const [filters, setFilters] = useState({
    status: 'all',
    priority: 'all',
    severity: 'all',
    project: 'all',
    assignee: 'all',
    search: '',
    overdue: 'all'
  })

  const activeStat: StatKey | null = filters.overdue === '1'
    ? 'overdue'
    : statCards.some(card => card.key === filters.status) ? filters.status as StatKey : null

  // Clicking a card shows just those issues; clicking it again shows everything
  const toggleStat = (key: StatKey) => {
    setFilters(prev => ({
      ...prev,
      status: key === 'overdue' || activeStat === key ? 'all' : key,
      overdue: key === 'overdue' && activeStat !== 'overdue' ? '1' : 'all'
    }))
  }

  const fetchReports = async (currentFilters = filters) => {
    try {
      setLoading(true)
      // The list and board have no paging, so ask for enough rows to match the KPI counts
      const params = new URLSearchParams({ per_page: '200' })

      Object.entries(currentFilters).forEach(([key, value]) => {
        if (value && value !== 'all') {
          params.append(key, value)
        }
      })

      const response = await fetch(`/api/reports?${params}`)
      const data = await response.json()
      const reports = data.data || []
      setAllReports(reports)
      setFilteredReports(sortReports(reports, sortField, sortOrder))
    } catch (error) {
      console.error('Failed to fetch reports:', error)
    } finally {
      setLoading(false)
    }
  }

  const sortReports = (reports: Report[], field: SortField, order: SortOrder): Report[] => {
    return [...reports].sort((a, b) => {
      let aValue: any
      let bValue: any

      switch (field) {
        case 'issue_key':
          aValue = a.issue_key || ''
          bValue = b.issue_key || ''
          break
        case 'title':
          aValue = a.title?.toLowerCase() || ''
          bValue = b.title?.toLowerCase() || ''
          break
        case 'project':
          aValue = typeof a.project === 'object' ? a.project?.name?.toLowerCase() || '' : a.project?.toLowerCase() || ''
          bValue = typeof b.project === 'object' ? b.project?.name?.toLowerCase() || '' : b.project?.toLowerCase() || ''
          break
        case 'status':
          const statusOrder = { 'open': 1, 'reopened': 2, 'in_progress': 3, 'resolved': 4, 'closed': 5 }
          aValue = statusOrder[a.status] || 0
          bValue = statusOrder[b.status] || 0
          break
        case 'priority':
          const priorityOrder = { 'urgent': 4, 'high': 3, 'medium': 2, 'low': 1 }
          aValue = priorityOrder[a.priority] || 0
          bValue = priorityOrder[b.priority] || 0
          break
        case 'severity':
          const severityOrder = { 'critical': 4, 'major': 3, 'minor': 2, 'trivial': 1 }
          aValue = severityOrder[a.severity || 'minor'] || 0
          bValue = severityOrder[b.severity || 'minor'] || 0
          break
        case 'due_date':
          aValue = a.due_date ? new Date(a.due_date).getTime() : 0
          bValue = b.due_date ? new Date(b.due_date).getTime() : 0
          break
        case 'assignee':
          aValue = a.assignee?.name?.toLowerCase() || ''
          bValue = b.assignee?.name?.toLowerCase() || ''
          break
        case 'created_at':
          aValue = new Date(a.created_at).getTime()
          bValue = new Date(b.created_at).getTime()
          break
        default:
          return 0
      }

      if (aValue < bValue) return order === 'asc' ? -1 : 1
      if (aValue > bValue) return order === 'asc' ? 1 : -1
      return 0
    })
  }

  const handleSort = (field: SortField) => {
    const newOrder = sortField === field && sortOrder === 'asc' ? 'desc' : 'asc'
    setSortField(field)
    setSortOrder(newOrder)
    setFilteredReports(sortReports(allReports, field, newOrder))
  }

  const getSortIcon = (field: SortField) => {
    if (sortField !== field) {
      return <ChevronsUpDown className="ml-1 h-3 w-3 opacity-50" />
    }
    return sortOrder === 'asc' ?
      <ChevronUp className="ml-1 h-3 w-3" /> :
      <ChevronDown className="ml-1 h-3 w-3" />
  }

  useEffect(() => {
    fetchReports()
  }, [])

  useEffect(() => {
    fetchReports(filters)
  }, [filters])

  useEffect(() => {
    if (allReports.length > 0) {
      setFilteredReports(sortReports(allReports, sortField, sortOrder))
    }
  }, [sortField, sortOrder, allReports])

  return (
    <AppLayout>
      <Head title="Issues" />

      <div className="space-y-6">
        {/* Header */}
        <div className="flex items-center justify-between">
          <div>
            <p className="eyebrow mb-1">Sidra Desk</p>
            <h1 className="text-3xl font-extrabold tracking-tight">Issues</h1>
            <p className="text-muted-foreground text-sm mt-1">
              Track and manage issues across all projects
            </p>
          </div>

          <div className="flex items-center gap-3">
            {/* View Toggle */}
            <div className="flex items-center bg-muted rounded-lg p-1">
              <Button
                variant={viewMode === 'board' ? 'secondary' : 'ghost'}
                size="sm"
                className="h-8 px-3"
                onClick={() => setViewMode('board')}
              >
                <LayoutGrid className="w-4 h-4 mr-1" />
                Board
              </Button>
              <Button
                variant={viewMode === 'list' ? 'secondary' : 'ghost'}
                size="sm"
                className="h-8 px-3"
                onClick={() => setViewMode('list')}
              >
                <List className="w-4 h-4 mr-1" />
                List
              </Button>
            </div>

            {/* Create Button */}
            {(userRole === 'admin' || userRole === 'super_admin' || userRole === 'manager' ||
              (userRole === 'client' && activeProjects.length > 0) ||
              (userRole === 'developer' && activeProjects.length > 0)) && (
              <Button onClick={() => router.get('/reports/create')} className="shadow-sm">
                <Plus className="w-4 h-4 mr-2" />
                Create Issue
              </Button>
            )}
            {(userRole === 'client' || userRole === 'developer') && activeProjects.length === 0 && (
              <p className="max-w-56 text-right text-xs text-muted-foreground">
                You have no active projects, so you can't create issues yet. Ask your administrator to assign or reopen one.
              </p>
            )}
          </div>
        </div>

        {/* Stats Cards */}
        <div className="grid grid-cols-2 gap-4 md:grid-cols-3 xl:grid-cols-5">
          {statCards.map(({ key, label, hint, icon: Icon, color }) => (
            <button
              key={key}
              type="button"
              onClick={() => toggleStat(key)}
              aria-pressed={activeStat === key}
              className={`rounded-[14px] border bg-card p-5 text-left transition hover:-translate-y-0.5 hover:border-brand-gold hover:shadow-[0_9px_24px_#81725426] ${activeStat === key ? 'border-brand-gold ring-1 ring-brand-gold' : ''}`}
            >
              <div className="flex items-center justify-between">
                <span className="eyebrow">{label}</span>
                <span className={`rounded-md p-1.5 ${color}`}>
                  <Icon className="h-4 w-4" />
                </span>
              </div>
              <p className="mt-3 font-[family-name:var(--font-heading)] text-4xl font-extrabold tracking-tight">{stats[key]}</p>
              <p className="mt-1 text-xs text-muted-foreground">{hint}</p>
            </button>
          ))}
        </div>

        {/* Filters */}
        <div className="space-y-3">
          {/* Search and Filter Controls */}
          <div className="flex items-center gap-3">
            {/* Search */}
            <div className="relative flex-1 max-w-md">
              <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" />
              <Input
                placeholder="Search issues..."
                value={filters.search}
                onChange={(e) => setFilters(prev => ({ ...prev, search: e.target.value }))}
                className="pl-9 h-9 bg-white"
              />
              {filters.search && (
                <button
                  onClick={() => setFilters(prev => ({ ...prev, search: '' }))}
                  className="absolute right-2 top-1/2 -translate-y-1/2 p-1 hover:bg-gray-100 rounded"
                >
                  <X className="w-3 h-3 text-gray-400" />
                </button>
              )}
            </div>

            {/* Filter Dropdowns */}
            <div className="flex items-center gap-2">
              <Select value={filters.status} onValueChange={(value) => setFilters(prev => ({ ...prev, status: value, overdue: 'all' }))}>
                <SelectTrigger className={`h-9 w-auto min-w-[120px] shrink-0 whitespace-nowrap text-sm ${filters.status !== 'all' ? 'border-blue-500 bg-blue-50 text-blue-700' : 'bg-white'}`}>
                  <SelectValue placeholder="Status" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">All Status</SelectItem>
                  <SelectItem value="open">
                    <div className="flex items-center gap-2">
                      <span className="w-2 h-2 rounded-full bg-blue-500" />
                      Open
                    </div>
                  </SelectItem>
                  <SelectItem value="in_progress">
                    <div className="flex items-center gap-2">
                      <span className="w-2 h-2 rounded-full bg-amber-500" />
                      In Progress
                    </div>
                  </SelectItem>
                  <SelectItem value="resolved">
                    <div className="flex items-center gap-2">
                      <span className="w-2 h-2 rounded-full bg-purple-500" />
                      Resolved
                    </div>
                  </SelectItem>
                  <SelectItem value="closed">
                    <div className="flex items-center gap-2">
                      <span className="w-2 h-2 rounded-full bg-green-500" />
                      Closed
                    </div>
                  </SelectItem>
                  <SelectItem value="reopened">
                    <div className="flex items-center gap-2">
                      <span className="w-2 h-2 rounded-full bg-red-500" />
                      Reopened
                    </div>
                  </SelectItem>
                </SelectContent>
              </Select>

              <Select value={filters.priority} onValueChange={(value) => setFilters(prev => ({ ...prev, priority: value }))}>
                <SelectTrigger className={`h-9 w-auto min-w-[110px] shrink-0 whitespace-nowrap text-sm ${filters.priority !== 'all' ? 'border-blue-500 bg-blue-50 text-blue-700' : 'bg-white'}`}>
                  <SelectValue placeholder="Priority" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">All Priority</SelectItem>
                  <SelectItem value="urgent">
                    <div className="flex items-center gap-2">
                      <AlertTriangle className="w-3.5 h-3.5 text-red-600" />
                      Urgent
                    </div>
                  </SelectItem>
                  <SelectItem value="high">
                    <div className="flex items-center gap-2">
                      <ArrowUpRight className="w-3.5 h-3.5 text-orange-600" />
                      High
                    </div>
                  </SelectItem>
                  <SelectItem value="medium">
                    <div className="flex items-center gap-2">
                      <AlertCircle className="w-3.5 h-3.5 text-yellow-600" />
                      Medium
                    </div>
                  </SelectItem>
                  <SelectItem value="low">
                    <div className="flex items-center gap-2">
                      <Info className="w-3.5 h-3.5 text-blue-600" />
                      Low
                    </div>
                  </SelectItem>
                </SelectContent>
              </Select>

              <Select value={filters.severity} onValueChange={(value) => setFilters(prev => ({ ...prev, severity: value }))}>
                <SelectTrigger className={`h-9 w-auto min-w-[130px] shrink-0 whitespace-nowrap text-sm ${filters.severity !== 'all' ? 'border-blue-500 bg-blue-50 text-blue-700' : 'bg-white'}`}>
                  <SelectValue placeholder="Severity" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">All Severity</SelectItem>
                  <SelectItem value="critical">
                    <div className="flex items-center gap-2">
                      <span className="w-2 h-2 rounded-full bg-red-600" />
                      Critical
                    </div>
                  </SelectItem>
                  <SelectItem value="major">
                    <div className="flex items-center gap-2">
                      <span className="w-2 h-2 rounded-full bg-orange-500" />
                      Major
                    </div>
                  </SelectItem>
                  <SelectItem value="minor">
                    <div className="flex items-center gap-2">
                      <span className="w-2 h-2 rounded-full bg-yellow-500" />
                      Minor
                    </div>
                  </SelectItem>
                  <SelectItem value="trivial">
                    <div className="flex items-center gap-2">
                      <span className="w-2 h-2 rounded-full bg-gray-400" />
                      Trivial
                    </div>
                  </SelectItem>
                </SelectContent>
              </Select>

              <SearchableSelect
                value={filters.project}
                onChange={(value) => setFilters(prev => ({ ...prev, project: value }))}
                allLabel="All Projects"
                searchPlaceholder="Search projects..."
                emptyText="No projects found"
                options={projects.map(project => ({
                  value: project.id.toString(),
                  label: project.name,
                  hint: project.status && project.status !== 'active' ? project.status : undefined,
                }))}
                className={`min-w-[130px] ${filters.project !== 'all' ? 'border-blue-500 bg-blue-50 text-blue-700' : 'bg-white'}`}
              />

              {/* Clear Filters */}
              {(filters.status !== 'all' || filters.priority !== 'all' || filters.severity !== 'all' || filters.project !== 'all' || filters.overdue !== 'all' || filters.search) && (
                <Button
                  variant="ghost"
                  size="sm"
                  onClick={() => setFilters({ status: 'all', priority: 'all', severity: 'all', project: 'all', assignee: 'all', search: '', overdue: 'all' })}
                  className="h-9 px-3 text-gray-500 hover:text-gray-700"
                >
                  <X className="w-3.5 h-3.5 mr-1" />
                  Clear
                </Button>
              )}
            </div>
          </div>

          {/* Active Filters Display */}
          {(filters.status !== 'all' || filters.priority !== 'all' || filters.severity !== 'all' || filters.project !== 'all' || filters.overdue !== 'all') && (
            <div className="flex items-center gap-2 flex-wrap">
              <span className="text-xs text-gray-500">Active filters:</span>
              {filters.overdue === '1' && (
                <span className="inline-flex items-center gap-1 px-2 py-1 bg-orange-100 text-orange-700 rounded-full text-xs font-medium">
                  Overdue
                  <button onClick={() => setFilters(prev => ({ ...prev, overdue: 'all' }))} className="ml-0.5 hover:bg-orange-200 rounded-full p-0.5">
                    <X className="w-3 h-3" />
                  </button>
                </span>
              )}
              {filters.status !== 'all' && (
                <span className="inline-flex items-center gap-1 px-2 py-1 bg-blue-100 text-blue-700 rounded-full text-xs font-medium">
                  <span className={`w-1.5 h-1.5 rounded-full ${statusConfig[filters.status as keyof typeof statusConfig]?.dot}`} />
                  {statusConfig[filters.status as keyof typeof statusConfig]?.label}
                  <button onClick={() => setFilters(prev => ({ ...prev, status: 'all' }))} className="ml-0.5 hover:bg-blue-200 rounded-full p-0.5">
                    <X className="w-3 h-3" />
                  </button>
                </span>
              )}
              {filters.priority !== 'all' && (
                <span className="inline-flex items-center gap-1 px-2 py-1 bg-orange-100 text-orange-700 rounded-full text-xs font-medium">
                  {priorityConfig[filters.priority as keyof typeof priorityConfig]?.label}
                  <button onClick={() => setFilters(prev => ({ ...prev, priority: 'all' }))} className="ml-0.5 hover:bg-orange-200 rounded-full p-0.5">
                    <X className="w-3 h-3" />
                  </button>
                </span>
              )}
              {filters.severity !== 'all' && (
                <span className="inline-flex items-center gap-1 px-2 py-1 bg-purple-100 text-purple-700 rounded-full text-xs font-medium">
                  {filters.severity.charAt(0).toUpperCase() + filters.severity.slice(1)}
                  <button onClick={() => setFilters(prev => ({ ...prev, severity: 'all' }))} className="ml-0.5 hover:bg-purple-200 rounded-full p-0.5">
                    <X className="w-3 h-3" />
                  </button>
                </span>
              )}
              {filters.project !== 'all' && (
                <span className="inline-flex items-center gap-1 px-2 py-1 bg-green-100 text-green-700 rounded-full text-xs font-medium">
                  {projects.find(p => p.id.toString() === filters.project)?.name}
                  <button onClick={() => setFilters(prev => ({ ...prev, project: 'all' }))} className="ml-0.5 hover:bg-green-200 rounded-full p-0.5">
                    <X className="w-3 h-3" />
                  </button>
                </span>
              )}
            </div>
          )}
        </div>

        {/* Content */}
        {loading ? (
          <div className="flex items-center justify-center py-16">
            <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-primary"></div>
          </div>
        ) : filteredReports.length === 0 ? (
          <div className="text-center py-16 bg-white rounded-xl border">
            <div className="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4">
              <FileText className="w-8 h-8 text-gray-400" />
            </div>
            <h3 className="text-lg font-medium text-gray-900 mb-2">No issues found</h3>
            <p className="text-gray-600 mb-6">
              {Object.values(filters).some(value => value && value !== 'all')
                ? 'No issues match these filters. Try clearing a filter or picking another card.'
                : 'Get started by creating your first issue.'}
            </p>
            {(userRole !== 'client' || activeProjects.length > 0) && (
              <Button onClick={() => router.get('/reports/create')}>
                <Plus className="w-4 h-4 mr-2" />
                Create Issue
              </Button>
            )}
          </div>
        ) : viewMode === 'board' ? (
          <KanbanBoard
            reports={filteredReports}
            onlyStatus={filters.status !== 'all' ? filters.status as Report['status'] : null}
            canDrag={['super_admin', 'admin', 'manager', 'developer'].includes(userRole)}
            onMoved={() => {
              fetchReports()
              router.reload({ only: ['stats'] })
            }}
          />
        ) : (
          <div className="bg-white shadow-sm border rounded-xl overflow-hidden">
            <Table>
              <TableHeader>
                <TableRow className="bg-slate-50/50">
                  <TableHead className="w-28">
                    <Button
                      variant="ghost"
                      className="h-auto p-0 font-medium text-xs uppercase tracking-wider hover:bg-transparent"
                      onClick={() => handleSort('issue_key')}
                    >
                      Key
                      {getSortIcon('issue_key')}
                    </Button>
                  </TableHead>
                  <TableHead>
                    <Button
                      variant="ghost"
                      className="h-auto p-0 font-medium text-xs uppercase tracking-wider hover:bg-transparent"
                      onClick={() => handleSort('title')}
                    >
                      Summary
                      {getSortIcon('title')}
                    </Button>
                  </TableHead>
                  <TableHead>
                    <Button
                      variant="ghost"
                      className="h-auto p-0 font-medium text-xs uppercase tracking-wider hover:bg-transparent"
                      onClick={() => handleSort('status')}
                    >
                      Status
                      {getSortIcon('status')}
                    </Button>
                  </TableHead>
                  <TableHead>
                    <Button
                      variant="ghost"
                      className="h-auto p-0 font-medium text-xs uppercase tracking-wider hover:bg-transparent"
                      onClick={() => handleSort('priority')}
                    >
                      Priority
                      {getSortIcon('priority')}
                    </Button>
                  </TableHead>
                  <TableHead>
                    <Button
                      variant="ghost"
                      className="h-auto p-0 font-medium text-xs uppercase tracking-wider hover:bg-transparent"
                      onClick={() => handleSort('assignee')}
                    >
                      Assignee
                      {getSortIcon('assignee')}
                    </Button>
                  </TableHead>
                  <TableHead>
                    <Button
                      variant="ghost"
                      className="h-auto p-0 font-medium text-xs uppercase tracking-wider hover:bg-transparent"
                      onClick={() => handleSort('due_date')}
                    >
                      Due Date
                      {getSortIcon('due_date')}
                    </Button>
                  </TableHead>
                  <TableHead>
                    <Button
                      variant="ghost"
                      className="h-auto p-0 font-medium text-xs uppercase tracking-wider hover:bg-transparent"
                      onClick={() => handleSort('created_at')}
                    >
                      Created
                      {getSortIcon('created_at')}
                    </Button>
                  </TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {filteredReports.map((report) => {
                  const status = statusConfig[report.status]
                  const priority = priorityConfig[report.priority]
                  const PriorityIcon = priority?.icon || Info
                  const isOverdue = report.due_date && new Date(report.due_date) < new Date() && report.status !== 'closed'

                  return (
                    <TableRow
                      key={`report-${report.id}-${report.uuid}`}
                      className="hover:bg-slate-50/50 cursor-pointer transition-colors"
                      onClick={() => router.get(`/reports/${report.uuid}`)}
                    >
                      <TableCell>
                        <span className="font-mono text-sm text-blue-600 hover:underline">
                          {report.issue_key || `#${report.id}`}
                        </span>
                      </TableCell>
                      <TableCell>
                        <div className="max-w-md">
                          <p className="font-medium text-gray-900 truncate">{report.title}</p>
                          <p className="text-sm text-gray-500 truncate">{report.description}</p>
                        </div>
                      </TableCell>
                      <TableCell>
                        <span className={`inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium ${status?.bg} ${status?.text}`}>
                          <span className={`w-1.5 h-1.5 rounded-full ${status?.dot}`} />
                          {status?.label}
                        </span>
                      </TableCell>
                      <TableCell>
                        <div className="flex items-center gap-1.5">
                          <PriorityIcon className={`w-4 h-4 ${priority?.color}`} />
                          <span className="text-sm text-gray-600">{priority?.label}</span>
                        </div>
                      </TableCell>
                      <TableCell>
                        {report.assignee ? (
                          <div className="flex items-center gap-2">
                            <div className="w-6 h-6 rounded-full bg-gradient-to-br from-indigo-500 to-purple-600 flex items-center justify-center text-white text-xs font-medium">
                              {report.assignee.name.charAt(0)}
                            </div>
                            <span className="text-sm text-gray-600">{report.assignee.name}</span>
                          </div>
                        ) : (
                          <span className="text-sm text-gray-400">Unassigned</span>
                        )}
                      </TableCell>
                      <TableCell>
                        {report.due_date ? (
                          <span className={`text-sm ${isOverdue ? 'text-red-600 font-medium' : 'text-gray-600'}`}>
                            {new Date(report.due_date).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' })}
                          </span>
                        ) : (
                          <span className="text-sm text-gray-400">-</span>
                        )}
                      </TableCell>
                      <TableCell>
                        <span className="text-sm text-gray-500">
                          {new Date(report.created_at).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' })}
                        </span>
                      </TableCell>
                    </TableRow>
                  )
                })}
              </TableBody>
            </Table>
          </div>
        )}
      </div>
    </AppLayout>
  )
}
