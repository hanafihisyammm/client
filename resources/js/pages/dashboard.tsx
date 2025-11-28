import { useState, useEffect } from 'react'
import { Head, usePage, router } from '@inertiajs/react'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Input } from '@/components/ui/input'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Plus, Search, FileText, User as UserIcon, Calendar, ChevronUp, ChevronDown, ChevronsUpDown, Eye, Edit, Trash2 } from 'lucide-react'
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
}

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
  const { auth, projects, userRole } = usePage<PageProps>().props
  const [allReports, setAllReports] = useState<Report[]>([])
  const [filteredReports, setFilteredReports] = useState<Report[]>([])
  const [loading, setLoading] = useState(true)
  const [sortField, setSortField] = useState<SortField>('created_at')
  const [sortOrder, setSortOrder] = useState<SortOrder>('desc')
  const [filters, setFilters] = useState({
    status: 'all',
    priority: 'all',
    project: 'all',
    search: ''
  })
  const fetchReports = async (currentFilters = filters) => {
    try {
      setLoading(true)
      const params = new URLSearchParams()
      
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
      <Head title="Dashboard" />
      
      
      <div className="space-y-6 px-4 py-2">
        <div className="flex items-center justify-between">
          <div>
            <h1 className="text-3xl font-bold">Dashboard</h1>
            <p className="text-muted-foreground mt-1">
              Manage your issues and requirements
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
                <Button 
                  disabled 
                  title="You must be assigned to a project before creating reports"
                >
                  <Plus className="w-4 h-4 mr-2" />
                  Create Report
                </Button>
              );
            }
            
            return null;
          })()}
        </div>

        <div className="flex flex-wrap gap-4">
          <div className="flex items-center space-x-2">
            <Search className="w-4 h-4 text-muted-foreground" />
            <Input
              placeholder="Search reports..."
              value={filters.search}
              onChange={(e) => setFilters(prev => ({ ...prev, search: e.target.value }))}
              className="w-64"
            />
          </div>
          
          <Select value={filters.status} onValueChange={(value) => setFilters(prev => ({ ...prev, status: value }))}>
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
              <h3 className="text-lg font-medium text-gray-900 mb-2">No reports yet</h3>
              <p className="text-gray-600 mb-6">Get started by creating your first report.</p>
              {auth.user.role === 'client' && (
                <Button onClick={() => router.get('/reports/create')} className="bg-blue-600 hover:bg-blue-700">
                  <Plus className="w-4 h-4 mr-2" />
                  Create Your First Report
                </Button>
              )}
            </div>
          </div>
        ) : (
          <div className="bg-white shadow-sm border rounded-lg">
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
                    className="hover:bg-gray-50"
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
                        {(userRole === 'admin' || userRole === 'super_admin' || 
                          (userRole === 'client' && report.reporter?.id === auth.user.id)) && (
                          <Button 
                            size="sm" 
                            variant="ghost" 
                            onClick={(e) => {
                              e.stopPropagation()
                              router.get(`/reports/${report.uuid}`)
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
