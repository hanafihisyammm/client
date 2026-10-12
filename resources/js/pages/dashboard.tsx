import { useState, useEffect } from 'react'
import { Head, usePage, router } from '@inertiajs/react'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Input } from '@/components/ui/input'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Plus, Search, FileText, User as UserIcon, Calendar, ChevronUp, ChevronDown, ChevronsUpDown, Eye, Edit, Trash2, Inbox, Loader, ClipboardCheck, RotateCcw, AlarmClock, X, type LucideIcon } from 'lucide-react'
import AppLayout from '@/layouts/app-layout'
import type { User } from '@/types'

interface Report {
  id: number
  uuid: string
  title: string
  description: string
  project: string | { id: number; name: string }
  status: 'pending' | 'in_progress' | 'submit_for_review' | 'approved' | 'rejected'
  due_date?: string | null
  priority: 'low' | 'medium' | 'high' | 'urgent'
  reporter: { id: number; name: string; email: string }
  assignee?: { id: number; name: string; email: string }
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
  id: number;
  name: string;
}

interface PageProps extends Record<string, unknown> {
  auth: {
    user: User & { role: 'client' | 'admin' | 'super_admin' }
  };
  projects: Project[];
  userRole: string;
  stats: Record<StatKey, number>;
}

type StatKey = 'pending' | 'in_progress' | 'submit_for_review' | 'rejected' | 'overdue'

const statCards: Array<{ key: StatKey; label: string; hint: string; icon: LucideIcon; color: string }> = [
  { key: 'pending', label: 'Pending', hint: 'Not yet acknowledged', icon: Inbox, color: 'text-brand-gold-dark bg-brand-cream' },
  { key: 'in_progress', label: 'In Progress', hint: 'Being worked on', icon: Loader, color: 'text-brand-navy bg-brand-navy/10' },
  { key: 'submit_for_review', label: 'Awaiting Review', hint: 'Waiting for client approval', icon: ClipboardCheck, color: 'text-brand-ink bg-brand-gold/40' },
  { key: 'rejected', label: 'Rejected', hint: 'Sent back, needs rework', icon: RotateCcw, color: 'text-red-600 bg-red-50' },
  { key: 'overdue', label: 'Overdue', hint: 'Past due date, still open', icon: AlarmClock, color: 'text-orange-600 bg-orange-50' },
]

const statusColors = {
  pending: 'bg-yellow-500',
  in_progress: 'bg-blue-500',
  submit_for_review: 'bg-purple-500',
  approved: 'bg-green-500',
  rejected: 'bg-red-500'
}


type SortField = 'title' | 'project' | 'status' | 'priority' | 'due_date' | 'reporter' | 'created_at'
type SortOrder = 'asc' | 'desc'

export default function Dashboard() {
  const { auth, projects, userRole, stats } = usePage<PageProps>().props
  const [allReports, setAllReports] = useState<Report[]>([])
  const [filteredReports, setFilteredReports] = useState<Report[]>([])
  const [loading, setLoading] = useState(true)
  const [sortField, setSortField] = useState<SortField>('created_at')
  const [sortOrder, setSortOrder] = useState<SortOrder>('desc')
  const [filters, setFilters] = useState({
    status: 'all',
    priority: 'all',
    project: 'all',
    search: '',
    overdue: 'all'
  })

  const activeStat: StatKey | null = filters.overdue === '1'
    ? 'overdue'
    : statCards.some(card => card.key === filters.status) ? filters.status as StatKey : null

  // Clicking a card shows just those reports; clicking it again shows everything
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
      // The table has no paging, so ask for enough rows to match the KPI counts
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
        case 'title':
          aValue = a.title?.toLowerCase() || ''
          bValue = b.title?.toLowerCase() || ''
          break
        case 'project':
          aValue = typeof a.project === 'object' ? a.project?.name?.toLowerCase() || '' : a.project?.toLowerCase() || ''
          bValue = typeof b.project === 'object' ? b.project?.name?.toLowerCase() || '' : b.project?.toLowerCase() || ''
          break
        case 'status':
          aValue = a.status
          bValue = b.status
          break
        case 'priority':
          const priorityOrder = { 'urgent': 4, 'high': 3, 'medium': 2, 'low': 1 }
          aValue = priorityOrder[a.priority] || 0
          bValue = priorityOrder[b.priority] || 0
          break
        case 'due_date':
          aValue = a.due_date ? new Date(a.due_date).getTime() : 0
          bValue = b.due_date ? new Date(b.due_date).getTime() : 0
          break
        case 'reporter':
          aValue = a.reporter?.name?.toLowerCase() || ''
          bValue = b.reporter?.name?.toLowerCase() || ''
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
      return <ChevronsUpDown className="ml-2 h-4 w-4 opacity-50" />
    }
    return sortOrder === 'asc' ? 
      <ChevronUp className="ml-2 h-4 w-4" /> : 
      <ChevronDown className="ml-2 h-4 w-4" />
  }

  useEffect(() => {
    fetchReports()
  }, [])

  useEffect(() => {
    fetchReports(filters)
  }, [filters])

  // Re-sort when sort field or order changes
  useEffect(() => {
    if (allReports.length > 0) {
      setFilteredReports(sortReports(allReports, sortField, sortOrder))
    }
  }, [sortField, sortOrder, allReports])



  const formatStatus = (status: string) => {
    return status.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase())
  }

  return (
    <AppLayout>
      <Head title="Reports" />
      
      
      <div className="space-y-6 px-4 py-2">
        <div className="flex items-center justify-between">
          <div>
            <p className="eyebrow mb-1">Sidra Desk</p>
            <h1 className="text-3xl font-extrabold">Reports</h1>
            <p className="text-muted-foreground mt-1">
              Track issues and requirements, and see what needs attention
            </p>
          </div>
          
          {(() => {
            // Show Create Report button for admins and super_admins always
            if (userRole === 'admin' || userRole === 'super_admin') {
              return (
                <Button onClick={() => router.get('/reports/create')}>
                  <Plus className="w-4 h-4 mr-2" />
                  Create Report
                </Button>
              );
            }
            
            // For clients, only show if they have assigned projects
            if (userRole === 'client' && projects.length > 0) {
              return (
                <Button onClick={() => router.get('/reports/create')}>
                  <Plus className="w-4 h-4 mr-2" />
                  Create Report
                </Button>
              );
            }
            
            // For clients with no projects, show a disabled button with tooltip
            if (userRole === 'client' && projects.length === 0) {
              return (
                <div className="text-right">
                  <Button 
                    disabled 
                    title="You have no active projects, so new reports can't be created"
                  >
                    <Plus className="w-4 h-4 mr-2" />
                    Create Report
                  </Button>
                  <p className="mt-1 text-xs text-muted-foreground">
                    You have no active projects. Ask your administrator to assign or reopen one.
                  </p>
                </div>
              );
            }
            
            return null;
          })()}
        </div>

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

        <div className="flex flex-wrap items-center gap-4">
          <div className="flex items-center space-x-2">
            <Search className="w-4 h-4 text-muted-foreground" />
            <Input
              placeholder="Search reports..."
              value={filters.search}
              onChange={(e) => setFilters(prev => ({ ...prev, search: e.target.value }))}
              className="w-64"
            />
          </div>
          
          <Select value={filters.status} onValueChange={(value) => setFilters(prev => ({ ...prev, status: value, overdue: 'all' }))}>
            <SelectTrigger className="w-48">
              <SelectValue placeholder="Filter by status" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All Statuses</SelectItem>
              <SelectItem value="pending">Pending</SelectItem>
              <SelectItem value="in_progress">In Progress</SelectItem>
              <SelectItem value="submit_for_review">Submit for Review</SelectItem>
              <SelectItem value="approved">Approved</SelectItem>
              <SelectItem value="rejected">Rejected</SelectItem>
            </SelectContent>
          </Select>


          <Select value={filters.project} onValueChange={(value) => setFilters(prev => ({ ...prev, project: value }))}>
            <SelectTrigger className="w-48">
              <SelectValue placeholder="Project" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All Projects</SelectItem>
              {projects.map(project => (
                <SelectItem key={project.id} value={project.id.toString()}>{project.name}</SelectItem>
              ))}
            </SelectContent>
          </Select>

          {filters.overdue === '1' && (
            <Button variant="outline" size="sm" onClick={() => setFilters(prev => ({ ...prev, overdue: 'all' }))}>
              Overdue only
              <X className="w-4 h-4 ml-1" />
            </Button>
          )}
        </div>

        {loading ? (
          <div className="flex items-center justify-center py-8">
            <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-primary"></div>
          </div>
        ) : filteredReports.length === 0 ? (
          <div className="text-center py-12">
            <div className="max-w-sm mx-auto">
              <div className="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4">
                <FileText className="w-8 h-8 text-gray-400" />
              </div>
              <h3 className="text-lg font-medium text-gray-900 mb-2">
                {Object.values(filters).some(value => value && value !== 'all') ? 'No reports match these filters' : 'No reports yet'}
              </h3>
              <p className="text-gray-600 mb-6">
                {Object.values(filters).some(value => value && value !== 'all') ? 'Try clearing a filter or picking another card.' : 'Get started by creating your first report.'}
              </p>
              {auth.user.role === 'client' && (
                <Button onClick={() => router.get('/reports/create')}>
                  <Plus className="w-4 h-4 mr-2" />
                  Create Your First Report
                </Button>
              )}
            </div>
          </div>
        ) : (
          <div className="overflow-hidden rounded-[14px] border bg-card">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead className="w-24">Actions</TableHead>
                  <TableHead>
                    <Button 
                      variant="ghost" 
                      className="h-auto p-0 font-semibold hover:bg-transparent"
                      onClick={() => handleSort('title')}
                    >
                      Title
                      {getSortIcon('title')}
                    </Button>
                  </TableHead>
                  <TableHead>
                    <Button 
                      variant="ghost" 
                      className="h-auto p-0 font-semibold hover:bg-transparent"
                      onClick={() => handleSort('project')}
                    >
                      Project
                      {getSortIcon('project')}
                    </Button>
                  </TableHead>
                  <TableHead>
                    <Button 
                      variant="ghost" 
                      className="h-auto p-0 font-semibold hover:bg-transparent"
                      onClick={() => handleSort('status')}
                    >
                      Status
                      {getSortIcon('status')}
                    </Button>
                  </TableHead>
                  <TableHead>
                    <Button 
                      variant="ghost" 
                      className="h-auto p-0 font-semibold hover:bg-transparent"
                      onClick={() => handleSort('priority')}
                    >
                      Priority
                      {getSortIcon('priority')}
                    </Button>
                  </TableHead>
                  <TableHead>
                    <Button 
                      variant="ghost" 
                      className="h-auto p-0 font-semibold hover:bg-transparent"
                      onClick={() => handleSort('due_date')}
                    >
                      Due Date
                      {getSortIcon('due_date')}
                    </Button>
                  </TableHead>
                  <TableHead>
                    <Button 
                      variant="ghost" 
                      className="h-auto p-0 font-semibold hover:bg-transparent"
                      onClick={() => handleSort('reporter')}
                    >
                      Reporter
                      {getSortIcon('reporter')}
                    </Button>
                  </TableHead>
                  <TableHead>Attachments</TableHead>
                  <TableHead>
                    <Button 
                      variant="ghost" 
                      className="h-auto p-0 font-semibold hover:bg-transparent"
                      onClick={() => handleSort('created_at')}
                    >
                      Created
                      {getSortIcon('created_at')}
                    </Button>
                  </TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {filteredReports.map((report) => (
                  <TableRow 
                    key={`report-${report.id}-${report.uuid}`}
                    className="hover:bg-brand-paper"
                  >
                    <TableCell>
                      <div className="flex items-center gap-1">
                        <Button 
                          size="sm" 
                          variant="ghost" 
                          onClick={(e) => {
                            e.stopPropagation()
                            router.get(`/reports/${report.uuid}`)
                          }}
                          title="View report"
                        >
                          <Eye className="h-4 w-4" />
                        </Button>
                        {/* Clients can only edit their own reports while they are still pending */}
                        {(userRole === 'admin' || userRole === 'super_admin' || 
                          (userRole === 'client' && report.reporter?.id === auth.user.id && report.status === 'pending')) && (
                          <Button 
                            size="sm" 
                            variant="ghost" 
                            onClick={(e) => {
                              e.stopPropagation()
                              router.get(`/reports/${report.uuid}?edit=1`)
                            }}
                            title="Edit report"
                          >
                            <Edit className="h-4 w-4" />
                          </Button>
                        )}
                      </div>
                    </TableCell>
                    <TableCell 
                      className="font-medium cursor-pointer" 
                      onClick={() => router.get(`/reports/${report.uuid}`)}
                    >
                      <div className="max-w-xs">
                        <div className="truncate font-medium text-gray-900">
                          {String(report.title || `Report #${report.id}`)}
                        </div>
                        <div className="text-sm text-gray-500 truncate">
                          {String(report.description || '')}
                        </div>
                      </div>
                    </TableCell>
                    <TableCell 
                      className="text-sm text-gray-600 cursor-pointer"
                      onClick={() => router.get(`/reports/${report.uuid}`)}
                    >
                      {typeof report.project === 'object' ? (report.project?.name || '-') : (report.project || '-')}
                    </TableCell>
                    <TableCell 
                      className="cursor-pointer"
                      onClick={() => router.get(`/reports/${report.uuid}`)}
                    >
                      <Badge className={`${statusColors[report.status]} text-white text-xs`}>
                        {formatStatus(report.status)}
                      </Badge>
                    </TableCell>
                    <TableCell 
                      className="cursor-pointer"
                      onClick={() => router.get(`/reports/${report.uuid}`)}
                    >
                      <Badge className={`text-xs ${
                        report.priority === 'urgent' ? 'bg-red-100 text-red-800' :
                        report.priority === 'high' ? 'bg-orange-100 text-orange-800' :
                        report.priority === 'medium' ? 'bg-yellow-100 text-yellow-800' :
                        'bg-green-100 text-green-800'
                      }`}>
                        {report.priority?.charAt(0).toUpperCase() + report.priority?.slice(1)}
                      </Badge>
                    </TableCell>
                    <TableCell 
                      className="text-sm text-gray-600 cursor-pointer"
                      onClick={() => router.get(`/reports/${report.uuid}`)}
                    >
                      {report.due_date ? new Date(report.due_date).toLocaleDateString() : '-'}
                    </TableCell>
                    <TableCell 
                      className="cursor-pointer"
                      onClick={() => router.get(`/reports/${report.uuid}`)}
                    >
                      <div className="flex items-center gap-2">
                        <UserIcon className="w-4 h-4 text-gray-400" />
                        <span className="text-sm">{report.reporter?.name || 'Unknown'}</span>
                      </div>
                    </TableCell>
                    <TableCell 
                      className="text-center cursor-pointer"
                      onClick={() => router.get(`/reports/${report.uuid}`)}
                    >
                      {report.attachments && report.attachments.length > 0 ? (
                        <div className="flex items-center justify-center">
                          <FileText className="w-4 h-4 text-gray-400" />
                          <span className="ml-1 text-sm text-gray-600">
                            {report.attachments.length}
                          </span>
                        </div>
                      ) : (
                        <span className="text-sm text-gray-400">-</span>
                      )}
                    </TableCell>
                    <TableCell 
                      className="cursor-pointer"
                      onClick={() => router.get(`/reports/${report.uuid}`)}
                    >
                      <div className="flex items-center gap-2">
                        <Calendar className="w-4 h-4 text-gray-400" />
                        <span className="text-sm text-gray-600">
                          {new Date(report.created_at).toLocaleDateString()}
                        </span>
                      </div>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </div>
        )}
      </div>
    </AppLayout>
  )
}
