import InputError from '@/components/input-error';
import { StatCard } from '@/components/stat-card';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { goBack } from '@/lib/navigation';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { TablePagination, paginate } from '@/components/table-pagination';
import { filterControlClass } from '@/lib/utils';
import { AlertCircle, ArrowLeft, Ban, ChevronDown, ChevronUp, ChevronsUpDown, Eye, FileText, FolderKanban, MoreHorizontal, Pencil, PlusIcon, RotateCcw, Search, Send, Trash2, Users, X } from 'lucide-react';
import { useMemo, useState } from 'react';

interface Manager {
    id: number;
    name: string;
    email: string;
    deactivated_at?: string | null;
    email_verified_at?: string | null;
}

interface ProjectSummary {
    id: number;
    uuid: string;
    name: string;
    status: 'active' | 'inactive' | 'completed';
    users_count?: number;
    reports_count?: number;
    open_reports_count?: number;
}

interface StaffMember {
    id: number;
    uuid: string;
    name: string;
    email: string;
    deactivated_at: string | null;
    email_verified_at: string | null;
    projects: ProjectSummary[];
}

interface Props {
    company: { id: number; uuid: string; name: string; status: 'active' | 'inactive'; manager_id: number | null; manager: Manager | null };
    staff: StaffMember[];
    projects: ProjectSummary[];
    counts: { staff: number; active_staff: number; projects: number; active_projects: number; issues: number; open_issues: number };
    /** Admins only: rename, change status, change manager */
    canManageCompany: boolean;
    /** Admins only: add staff, edit their details, deactivate, resend invitations */
    canManageStaff: boolean;
    /** Admins and this company's manager: choose which projects staff work on */
    canAssignStaff: boolean;
    /** Admins and this company's manager: create, edit and delete its projects */
    canManageProjects: boolean;
    availableManagers: Manager[];
}

const KEEP_MANAGER = 'keep';
const NEW_MANAGER = 'new';

type Tab = 'staff' | 'projects';
type SortOrder = 'asc' | 'desc';
type StaffSortField = 'name' | 'projects' | 'status';
type ProjectSortField = 'name' | 'status' | 'staff' | 'open' | 'all';
type StaffState = 'active' | 'invited' | 'deactivated';

function staffStateOf(member: StaffMember): StaffState {
    return member.deactivated_at ? 'deactivated' : member.email_verified_at ? 'active' : 'invited';
}

function compare(a: string | number, b: string | number, order: SortOrder): number {
    const result = typeof a === 'number' && typeof b === 'number' ? a - b : String(a).localeCompare(String(b));
    return order === 'asc' ? result : -result;
}

/** A column heading that sorts the table when clicked. */
function SortHead({ label, active, order, onClick, center = false }: { label: string; active: boolean; order: SortOrder; onClick: () => void; center?: boolean }) {
    const Icon = !active ? ChevronsUpDown : order === 'asc' ? ChevronUp : ChevronDown;

    return (
        <TableHead className={center ? 'text-center' : ''} aria-sort={active ? (order === 'asc' ? 'ascending' : 'descending') : 'none'}>
            <button
                type="button"
                onClick={onClick}
                className="inline-flex items-center text-xs font-medium tracking-wider whitespace-nowrap text-muted-foreground uppercase transition-colors hover:text-foreground"
            >
                {label}
                <Icon className={`ml-1 h-3.5 w-3.5 ${active ? '' : 'opacity-50'}`} />
            </button>
        </TableHead>
    );
}

function SearchBox({ value, onChange, placeholder }: { value: string; onChange: (value: string) => void; placeholder: string }) {
    return (
        <div className="relative max-w-md min-w-[220px] flex-1">
            <Search className="absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-gray-400" />
            <Input placeholder={placeholder} aria-label={placeholder} value={value} onChange={(event) => onChange(event.target.value)} className="h-9 bg-white pl-9" />
            {value && (
                <button
                    type="button"
                    aria-label="Clear search"
                    onClick={() => onChange('')}
                    className="absolute top-1/2 right-2 -translate-y-1/2 rounded p-1 hover:bg-gray-100"
                >
                    <X className="h-3 w-3 text-gray-400" />
                </button>
            )}
        </div>
    );
}

function ClearButton({ onClick }: { onClick: () => void }) {
    return (
        <Button variant="ghost" size="sm" onClick={onClick} className="h-9 px-3 text-gray-500 hover:text-gray-700">
            <X className="mr-1 h-3.5 w-3.5" />
            Clear
        </Button>
    );
}

function StaffStatus({ member }: { member: StaffMember }) {
    const [label, classes] = {
        deactivated: ['Deactivated', 'border-red-200 bg-red-50 text-red-700'],
        active: ['Active', 'border-green-200 bg-green-50 text-green-700'],
        invited: ['Invited', 'border-gray-200 bg-gray-50 text-gray-600'],
    }[staffStateOf(member)];

    return <span className={`inline-flex rounded-full border px-2.5 py-0.5 text-xs font-medium ${classes}`}>{label}</span>;
}

/** Tick boxes for the company's projects, used when adding or editing a staff member. */
function ProjectChecklist({ projects, selected, onChange }: { projects: ProjectSummary[]; selected: number[]; onChange: (ids: number[]) => void }) {
    if (projects.length === 0) {
        return <p className="text-sm text-muted-foreground">This company has no projects yet.</p>;
    }

    return (
        <div className="max-h-48 space-y-2 overflow-y-auto rounded-md border p-3">
            {projects.map((project) => (
                <label key={project.id} className="flex cursor-pointer items-center gap-2 text-sm">
                    <Checkbox
                        checked={selected.includes(project.id)}
                        onCheckedChange={(checked) =>
                            onChange(checked ? [...selected, project.id] : selected.filter((id) => id !== project.id))
                        }
                    />
                    <span className="min-w-0 flex-1 truncate">{project.name}</span>
                    {project.status !== 'active' && <span className="text-xs text-muted-foreground">{project.status}</span>}
                </label>
            ))}
        </div>
    );
}

export default function ClientShow({ company, staff, projects, counts, canManageCompany, canManageStaff, canAssignStaff, canManageProjects, availableManagers }: Props) {
    const base = `/clients/${company.uuid}`;
    const [showAddStaff, setShowAddStaff] = useState(false);
    const [editing, setEditing] = useState<StaffMember | null>(null);
    // Arriving from "Edit Client" on the list (?edit=1) opens the settings straight away
    const [showSettings, setShowSettings] = useState(
        () => canManageCompany && typeof window !== 'undefined' && new URLSearchParams(window.location.search).get('edit') === '1',
    );
    const [managerChoice, setManagerChoice] = useState(KEEP_MANAGER);

    // Deleting a project brings the viewer back here with ?tab=projects
    const [tab, setTab] = useState<Tab>(() =>
        typeof window !== 'undefined' && new URLSearchParams(window.location.search).get('tab') === 'projects' ? 'projects' : 'staff',
    );

    const [staffSearch, setStaffSearch] = useState('');
    const [staffStatus, setStaffStatus] = useState('all');
    const [staffProject, setStaffProject] = useState('all');
    const [staffSort, setStaffSort] = useState<{ field: StaffSortField; order: SortOrder }>({ field: 'name', order: 'asc' });
    const [staffPage, setStaffPage] = useState(1);

    const [projectSearch, setProjectSearch] = useState('');
    const [projectStatus, setProjectStatus] = useState('all');
    const [projectSort, setProjectSort] = useState<{ field: ProjectSortField; order: SortOrder }>({ field: 'name', order: 'asc' });
    const [projectPage, setProjectPage] = useState(1);
    const [deleteProject, setDeleteProject] = useState<ProjectSummary | null>(null);
    const [deleting, setDeleting] = useState(false);

    const sortStaffBy = (field: StaffSortField) => {
        setStaffSort((current) => ({ field, order: current.field === field && current.order === 'asc' ? 'desc' : 'asc' }));
        setStaffPage(1);
    };

    const sortProjectsBy = (field: ProjectSortField) => {
        setProjectSort((current) => ({ field, order: current.field === field && current.order === 'asc' ? 'desc' : 'asc' }));
        setProjectPage(1);
    };

    const staffPageData = useMemo(() => {
        const search = staffSearch.trim().toLowerCase();
        const rows = staff
            .filter(
                (member) =>
                    (!search || member.name.toLowerCase().includes(search) || member.email.toLowerCase().includes(search)) &&
                    (staffStatus === 'all' || staffStateOf(member) === staffStatus) &&
                    (staffProject === 'all' ||
                        (staffProject === 'none'
                            ? member.projects.length === 0
                            : member.projects.some((project) => String(project.id) === staffProject))),
            )
            .sort((a, b) => {
                switch (staffSort.field) {
                    case 'projects':
                        return compare(a.projects.length, b.projects.length, staffSort.order);
                    case 'status':
                        return compare(staffStateOf(a), staffStateOf(b), staffSort.order);
                    default:
                        return compare(a.name, b.name, staffSort.order);
                }
            });

        return paginate(rows, staffPage);
    }, [staff, staffSearch, staffStatus, staffProject, staffSort, staffPage]);

    const projectPageData = useMemo(() => {
        const search = projectSearch.trim().toLowerCase();
        const rows = projects
            .filter(
                (project) =>
                    (!search || project.name.toLowerCase().includes(search)) && (projectStatus === 'all' || project.status === projectStatus),
            )
            .sort((a, b) => {
                switch (projectSort.field) {
                    case 'status':
                        return compare(a.status, b.status, projectSort.order);
                    case 'staff':
                        return compare(a.users_count ?? 0, b.users_count ?? 0, projectSort.order);
                    case 'open':
                        return compare(a.open_reports_count ?? 0, b.open_reports_count ?? 0, projectSort.order);
                    case 'all':
                        return compare(a.reports_count ?? 0, b.reports_count ?? 0, projectSort.order);
                    default:
                        return compare(a.name, b.name, projectSort.order);
                }
            });

        return paginate(rows, projectPage);
    }, [projects, projectSearch, projectStatus, projectSort, projectPage]);

    const confirmDeleteProject = () => {
        if (!deleteProject) return;
        setDeleting(true);
        // from=client tells the server to come back to this page instead of the Projects list
        router.delete(`/projects/${deleteProject.id}?from=client`, {
            preserveScroll: true,
            onFinish: () => {
                setDeleting(false);
                setDeleteProject(null);
            },
        });
    };

    const addForm = useForm({ name: '', email: '', project_ids: [] as number[] });
    const editForm = useForm({ name: '', email: '', project_ids: [] as number[] });
    const settingsForm = useForm({ name: company.name, status: company.status as string, manager_email: '' });

    const closeAdd = () => {
        setShowAddStaff(false);
        addForm.reset();
        addForm.clearErrors();
    };

    const openEdit = (member: StaffMember) => {
        editForm.clearErrors();
        editForm.setData({ name: member.name, email: member.email, project_ids: member.projects.map((project) => project.id) });
        setEditing(member);
    };

    const submitAdd = (event: React.FormEvent) => {
        event.preventDefault();
        addForm.post(`${base}/staff`, { preserveScroll: true, preserveState: true, onSuccess: closeAdd });
    };

    const submitEdit = (event: React.FormEvent) => {
        event.preventDefault();
        if (!editing) return;
        editForm.patch(`${base}/staff/${editing.uuid}`, { preserveScroll: true, preserveState: true, onSuccess: () => setEditing(null) });
    };

    const submitSettings = (event: React.FormEvent) => {
        event.preventDefault();
        settingsForm.transform((form) => ({
            name: form.name,
            status: form.status,
            ...(managerChoice === NEW_MANAGER
                ? { manager_email: form.manager_email }
                : managerChoice !== KEEP_MANAGER
                  ? { manager_id: Number(managerChoice) }
                  : {}),
        }));
        settingsForm.patch(base, {
            preserveScroll: true,
            onSuccess: () => {
                setShowSettings(false);
                setManagerChoice(KEEP_MANAGER);
            },
        });
    };

    const staffAction = (member: StaffMember, action: 'deactivate' | 'reactivate' | 'invitation') =>
        // preserveState keeps the search, filters and page where they are
        router.post(`${base}/staff/${member.uuid}/${action}`, {}, { preserveScroll: true, preserveState: true });

    return (
        <AppLayout>
            <Head title={company.name} />

            <div className="space-y-6">
                {/* Header */}
                <div className="flex items-start justify-between gap-4">
                    <div className="flex items-start gap-4">
                        {canManageCompany && (
                            <Button variant="outline" size="sm" onClick={() => goBack('/clients')}>
                                <ArrowLeft className="mr-2 h-4 w-4" />
                                Back
                            </Button>
                        )}
                        <div>
                            <p className="eyebrow mb-1">Client company</p>
                            <div className="flex items-center gap-3">
                                <h1 className="text-3xl font-extrabold tracking-tight">{company.name}</h1>
                                <span
                                    className={`inline-flex rounded-full border px-2.5 py-0.5 text-xs font-medium ${
                                        company.status === 'active'
                                            ? 'border-green-200 bg-green-50 text-green-700'
                                            : 'border-gray-200 bg-gray-50 text-gray-600'
                                    }`}
                                >
                                    {company.status === 'active' ? 'Active' : 'Inactive'}
                                </span>
                            </div>
                            <p className="mt-1 text-sm text-muted-foreground">
                                Manager: {company.manager ? `${company.manager.name} (${company.manager.email})` : 'not assigned'}
                            </p>
                        </div>
                    </div>
                    {canManageCompany && (
                        <Button variant="outline" onClick={() => setShowSettings(true)}>
                            <Pencil className="mr-2 h-4 w-4" />
                            Edit Client
                        </Button>
                    )}
                </div>

                {!company.manager && (
                    <div role="alert" className="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                        This company has no manager. Assign one so its projects, issues and staff can be managed.
                    </div>
                )}

                {/* Counts */}
                <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                    <StatCard icon={Users} label="Staff" value={counts.active_staff} hint={`${counts.staff} in total`} />
                    <StatCard icon={FolderKanban} label="Projects" value={counts.active_projects} hint={`${counts.projects} in total`} />
                    <StatCard icon={AlertCircle} label="Open Issues" value={counts.open_issues} hint="Not yet closed" color="text-blue-600 bg-blue-50" />
                    <StatCard icon={FileText} label="All Issues" value={counts.issues} hint="Since the start" />
                </div>

                {/* Staff and projects share one card, a tab each, so neither is pushed down the page */}
                <div className="overflow-hidden rounded-xl border bg-white shadow-sm">
                    <div className="flex items-center justify-between gap-4 border-b px-5">
                        <div role="tablist" aria-label="Company details" className="flex gap-6">
                            {(
                                [
                                    ['staff', 'Staff', staff.length],
                                    ['projects', 'Projects', projects.length],
                                ] as const
                            ).map(([key, label, count]) => (
                                <button
                                    key={key}
                                    type="button"
                                    role="tab"
                                    aria-selected={tab === key}
                                    onClick={() => setTab(key)}
                                    className={`-mb-px flex items-center gap-2 border-b-2 py-4 text-sm font-semibold transition-colors ${
                                        tab === key
                                            ? 'border-brand-gold text-foreground'
                                            : 'border-transparent text-muted-foreground hover:text-foreground'
                                    }`}
                                >
                                    {label}
                                    <span className="rounded-full bg-muted px-2 py-0.5 text-xs font-medium text-muted-foreground">{count}</span>
                                </button>
                            ))}
                        </div>
                        {tab === 'staff'
                            ? canManageStaff && (
                                  <Button onClick={() => setShowAddStaff(true)}>
                                      <PlusIcon className="mr-2 h-4 w-4" />
                                      Add Staff
                                  </Button>
                              )
                            : canManageProjects && (
                                  <Link href="/projects/create">
                                      <Button>
                                          <PlusIcon className="mr-2 h-4 w-4" />
                                          New Project
                                      </Button>
                                  </Link>
                              )}
                    </div>

                    {tab === 'staff' ? (
                        <>
                            <div className="flex flex-wrap items-center gap-3 border-b px-5 py-3">
                                <SearchBox value={staffSearch} onChange={(value) => { setStaffSearch(value); setStaffPage(1); }} placeholder="Search staff by name or email..." />
                                <Select value={staffStatus} onValueChange={(value) => { setStaffStatus(value); setStaffPage(1); }}>
                                    <SelectTrigger aria-label="Filter by status" className={`${filterControlClass(staffStatus !== 'all')} w-auto min-w-[140px] shrink-0`}>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">All Status</SelectItem>
                                        <SelectItem value="active">Active</SelectItem>
                                        <SelectItem value="invited">Invited</SelectItem>
                                        <SelectItem value="deactivated">Deactivated</SelectItem>
                                    </SelectContent>
                                </Select>
                                <Select value={staffProject} onValueChange={(value) => { setStaffProject(value); setStaffPage(1); }}>
                                    <SelectTrigger aria-label="Filter by project" className={`${filterControlClass(staffProject !== 'all')} w-auto min-w-[160px] shrink-0`}>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">All Projects</SelectItem>
                                        <SelectItem value="none">Not on any project</SelectItem>
                                        {projects.map((project) => (
                                            <SelectItem key={project.id} value={String(project.id)}>
                                                {project.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {(staffSearch || staffStatus !== 'all' || staffProject !== 'all') && (
                                    <ClearButton
                                        onClick={() => {
                                            setStaffSearch('');
                                            setStaffStatus('all');
                                            setStaffProject('all');
                                            setStaffPage(1);
                                        }}
                                    />
                                )}
                            </div>
                            <Table>
                                <TableHeader>
                                    <TableRow className="bg-slate-50/50">
                                        {canAssignStaff && <TableHead className="w-[50px]"></TableHead>}
                                        <SortHead label="Name" active={staffSort.field === 'name'} order={staffSort.order} onClick={() => sortStaffBy('name')} />
                                        <SortHead label="Projects" active={staffSort.field === 'projects'} order={staffSort.order} onClick={() => sortStaffBy('projects')} />
                                        <SortHead label="Status" active={staffSort.field === 'status'} order={staffSort.order} onClick={() => sortStaffBy('status')} />
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {staffPageData.total === 0 ? (
                                        <TableRow>
                                            <TableCell colSpan={4} className="h-24 text-center text-muted-foreground">
                                                {staff.length === 0
                                                    ? canManageStaff
                                                        ? 'No staff yet. Add the first staff account.'
                                                        : 'No staff yet. An admin adds staff accounts for this company.'
                                                    : 'No staff match your search or filters.'}
                                            </TableCell>
                                        </TableRow>
                                    ) : (
                                        staffPageData.rows.map((member) => (
                                            <TableRow key={member.id} className={member.deactivated_at ? 'opacity-60' : ''}>
                                                {canAssignStaff && (
                                                    <TableCell>
                                                        <DropdownMenu>
                                                            <DropdownMenuTrigger asChild>
                                                                <Button variant="ghost" size="sm" className="h-8 w-8 p-0" aria-label={`Actions for ${member.name}`}>
                                                                    <MoreHorizontal className="h-4 w-4" />
                                                                </Button>
                                                            </DropdownMenuTrigger>
                                                            <DropdownMenuContent align="start" className="w-52">
                                                                <DropdownMenuItem onClick={() => openEdit(member)} className="cursor-pointer">
                                                                    {canManageStaff ? <Pencil className="mr-2 h-4 w-4" /> : <FolderKanban className="mr-2 h-4 w-4" />}
                                                                    {canManageStaff ? 'Edit and assign projects' : 'Assign projects'}
                                                                </DropdownMenuItem>
                                                                {canManageStaff && !member.email_verified_at && !member.deactivated_at && (
                                                                    <DropdownMenuItem onClick={() => staffAction(member, 'invitation')} className="cursor-pointer">
                                                                        <Send className="mr-2 h-4 w-4" />
                                                                        Resend invitation
                                                                    </DropdownMenuItem>
                                                                )}
                                                                {canManageStaff && <DropdownMenuSeparator />}
                                                                {canManageStaff &&
                                                                    (member.deactivated_at ? (
                                                                        <DropdownMenuItem onClick={() => staffAction(member, 'reactivate')} className="cursor-pointer">
                                                                            <RotateCcw className="mr-2 h-4 w-4" />
                                                                            Reactivate
                                                                        </DropdownMenuItem>
                                                                    ) : (
                                                                        <DropdownMenuItem
                                                                            onClick={() => staffAction(member, 'deactivate')}
                                                                            className="cursor-pointer text-red-600 focus:bg-red-50 focus:text-red-600"
                                                                        >
                                                                            <Ban className="mr-2 h-4 w-4" />
                                                                            Deactivate
                                                                        </DropdownMenuItem>
                                                                    ))}
                                                            </DropdownMenuContent>
                                                        </DropdownMenu>
                                                    </TableCell>
                                                )}
                                                <TableCell>
                                                    <p className="font-medium">{member.name}</p>
                                                    <p className="text-xs text-muted-foreground">{member.email}</p>
                                                </TableCell>
                                                <TableCell className="whitespace-normal">
                                                    {member.projects.length === 0 ? (
                                                        <span className="text-sm text-muted-foreground">Not on any project</span>
                                                    ) : (
                                                        <div className="flex flex-wrap gap-1.5">
                                                            {member.projects.map((project) => (
                                                                <span key={project.id} className="rounded-md bg-muted px-2 py-0.5 text-xs">
                                                                    {project.name}
                                                                </span>
                                                            ))}
                                                        </div>
                                                    )}
                                                </TableCell>
                                                <TableCell>
                                                    <StaffStatus member={member} />
                                                </TableCell>
                                            </TableRow>
                                        ))
                                    )}
                                </TableBody>
                            </Table>
                            <TablePagination page={staffPageData} noun="staff" onPageChange={setStaffPage} />
                        </>
                    ) : (
                        <>
                            <div className="flex flex-wrap items-center gap-3 border-b px-5 py-3">
                                <SearchBox value={projectSearch} onChange={(value) => { setProjectSearch(value); setProjectPage(1); }} placeholder="Search projects..." />
                                <Select value={projectStatus} onValueChange={(value) => { setProjectStatus(value); setProjectPage(1); }}>
                                    <SelectTrigger aria-label="Filter by status" className={`${filterControlClass(projectStatus !== 'all')} w-auto min-w-[140px] shrink-0`}>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">All Status</SelectItem>
                                        <SelectItem value="active">Active</SelectItem>
                                        <SelectItem value="inactive">Inactive</SelectItem>
                                        <SelectItem value="completed">Completed</SelectItem>
                                    </SelectContent>
                                </Select>
                                {(projectSearch || projectStatus !== 'all') && (
                                    <ClearButton
                                        onClick={() => {
                                            setProjectSearch('');
                                            setProjectStatus('all');
                                            setProjectPage(1);
                                        }}
                                    />
                                )}
                            </div>
                            <Table>
                                <TableHeader>
                                    <TableRow className="bg-slate-50/50">
                                        <TableHead className="w-[50px]"></TableHead>
                                        <SortHead label="Project" active={projectSort.field === 'name'} order={projectSort.order} onClick={() => sortProjectsBy('name')} />
                                        <SortHead label="Status" active={projectSort.field === 'status'} order={projectSort.order} onClick={() => sortProjectsBy('status')} />
                                        <SortHead label="Staff" center active={projectSort.field === 'staff'} order={projectSort.order} onClick={() => sortProjectsBy('staff')} />
                                        <SortHead label="Open issues" center active={projectSort.field === 'open'} order={projectSort.order} onClick={() => sortProjectsBy('open')} />
                                        <SortHead label="All issues" center active={projectSort.field === 'all'} order={projectSort.order} onClick={() => sortProjectsBy('all')} />
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {projectPageData.total === 0 ? (
                                        <TableRow>
                                            <TableCell colSpan={6} className="h-24 text-center text-muted-foreground">
                                                {projects.length === 0 ? 'No projects yet.' : 'No projects match your search or filters.'}
                                            </TableCell>
                                        </TableRow>
                                    ) : (
                                        projectPageData.rows.map((project) => (
                                            <TableRow
                                                key={project.id}
                                                className="cursor-pointer hover:bg-brand-paper"
                                                onClick={() => router.get(`/projects/view/${project.uuid}`)}
                                            >
                                                <TableCell onClick={(event) => event.stopPropagation()}>
                                                    <DropdownMenu>
                                                        <DropdownMenuTrigger asChild>
                                                            <Button variant="ghost" size="sm" className="h-8 w-8 p-0" aria-label={`Actions for ${project.name}`}>
                                                                <MoreHorizontal className="h-4 w-4" />
                                                            </Button>
                                                        </DropdownMenuTrigger>
                                                        <DropdownMenuContent align="start" className="w-48">
                                                            <DropdownMenuItem asChild>
                                                                <Link href={`/projects/view/${project.uuid}`} className="flex cursor-pointer items-center">
                                                                    <Eye className="mr-2 h-4 w-4" />
                                                                    View Project
                                                                </Link>
                                                            </DropdownMenuItem>
                                                            {canManageProjects && (
                                                                <>
                                                                    <DropdownMenuItem asChild>
                                                                        <Link href={`/projects/${project.uuid}/edit`} className="flex cursor-pointer items-center">
                                                                            <Pencil className="mr-2 h-4 w-4" />
                                                                            Edit Project
                                                                        </Link>
                                                                    </DropdownMenuItem>
                                                                    <DropdownMenuSeparator />
                                                                    <DropdownMenuItem
                                                                        onClick={() => setDeleteProject(project)}
                                                                        className="cursor-pointer text-red-600 focus:bg-red-50 focus:text-red-600"
                                                                    >
                                                                        <Trash2 className="mr-2 h-4 w-4" />
                                                                        Delete Project
                                                                    </DropdownMenuItem>
                                                                </>
                                                            )}
                                                        </DropdownMenuContent>
                                                    </DropdownMenu>
                                                </TableCell>
                                                <TableCell className="font-medium">{project.name}</TableCell>
                                                <TableCell className="text-sm capitalize">{project.status}</TableCell>
                                                <TableCell className="text-center">{project.users_count ?? 0}</TableCell>
                                                <TableCell className="text-center">{project.open_reports_count ?? 0}</TableCell>
                                                <TableCell className="text-center">{project.reports_count ?? 0}</TableCell>
                                            </TableRow>
                                        ))
                                    )}
                                </TableBody>
                            </Table>
                            <TablePagination page={projectPageData} noun="projects" onPageChange={setProjectPage} />
                        </>
                    )}
                </div>
            </div>

            {/* Delete project */}
            <Dialog open={!!deleteProject} onOpenChange={(open) => !open && setDeleteProject(null)}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <div className="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-red-100">
                            <Trash2 className="h-6 w-6 text-red-600" />
                        </div>
                        <DialogTitle className="text-center">Delete Project</DialogTitle>
                        <DialogDescription className="text-center">
                            Are you sure you want to delete <span className="font-medium text-gray-900">{deleteProject?.name}</span>? This will also
                            delete all associated issues and data. This action cannot be undone.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="mt-6 flex gap-3">
                        <Button variant="outline" onClick={() => setDeleteProject(null)} className="flex-1">
                            Cancel
                        </Button>
                        <Button variant="destructive" onClick={confirmDeleteProject} disabled={deleting} className="flex-1">
                            {deleting ? 'Deleting...' : 'Delete Project'}
                        </Button>
                    </div>
                </DialogContent>
            </Dialog>

            {/* Add staff */}
            <Dialog open={showAddStaff} onOpenChange={(open) => (open ? setShowAddStaff(true) : closeAdd())}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Add Staff</DialogTitle>
                        <DialogDescription>
                            Creates a staff login for {company.name}. They'll get an email to set their own password.
                        </DialogDescription>
                    </DialogHeader>
                    <form onSubmit={submitAdd} className="space-y-4">
                        <div className="space-y-2">
                            <Label htmlFor="staff-email">Email address</Label>
                            <Input
                                id="staff-email"
                                type="email"
                                value={addForm.data.email}
                                onChange={(event) => addForm.setData('email', event.target.value)}
                                required
                                autoFocus
                            />
                            <InputError message={addForm.errors.email} />
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="staff-name">Name (optional)</Label>
                            <Input id="staff-name" value={addForm.data.name} onChange={(event) => addForm.setData('name', event.target.value)} />
                            <InputError message={addForm.errors.name} />
                        </div>
                        <div className="space-y-2">
                            <Label>Projects</Label>
                            <ProjectChecklist projects={projects} selected={addForm.data.project_ids} onChange={(ids) => addForm.setData('project_ids', ids)} />
                            <InputError message={addForm.errors.project_ids} />
                        </div>
                        <div className="flex justify-end gap-3 pt-2">
                            <Button type="button" variant="outline" onClick={closeAdd}>
                                Cancel
                            </Button>
                            <Button type="submit" disabled={addForm.processing}>
                                {addForm.processing ? 'Adding...' : 'Add Staff'}
                            </Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>

            {/* Edit staff */}
            <Dialog open={!!editing} onOpenChange={(open) => !open && setEditing(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{canManageStaff ? 'Edit Staff' : 'Assign Projects'}</DialogTitle>
                        <DialogDescription>
                            {canManageStaff
                                ? 'Update their details and the projects they work on.'
                                : `Choose the projects ${editing?.name ?? 'this staff member'} works on.`}
                        </DialogDescription>
                    </DialogHeader>
                    <form onSubmit={submitEdit} className="space-y-4">
                        {/* Only admins change a staff member's name and email */}
                        {canManageStaff && (
                        <>
                        <div className="space-y-2">
                            <Label htmlFor="edit-staff-name">Name</Label>
                            <Input id="edit-staff-name" value={editForm.data.name} onChange={(event) => editForm.setData('name', event.target.value)} required />
                            <InputError message={editForm.errors.name} />
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="edit-staff-email">Email address</Label>
                            <Input
                                id="edit-staff-email"
                                type="email"
                                value={editForm.data.email}
                                onChange={(event) => editForm.setData('email', event.target.value)}
                                required
                            />
                            <InputError message={editForm.errors.email} />
                        </div>
                        </>
                        )}
                        <div className="space-y-2">
                            <Label>Projects</Label>
                            <ProjectChecklist projects={projects} selected={editForm.data.project_ids} onChange={(ids) => editForm.setData('project_ids', ids)} />
                            <InputError message={editForm.errors.project_ids} />
                        </div>
                        <div className="flex justify-end gap-3 pt-2">
                            <Button type="button" variant="outline" onClick={() => setEditing(null)}>
                                Cancel
                            </Button>
                            <Button type="submit" disabled={editForm.processing}>
                                {editForm.processing ? 'Saving...' : 'Save Changes'}
                            </Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>

            {/* Company settings (admins) */}
            <Dialog open={showSettings} onOpenChange={setShowSettings}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Edit Client</DialogTitle>
                        <DialogDescription>Rename the company, change its status, or put a different manager in charge.</DialogDescription>
                    </DialogHeader>
                    <form onSubmit={submitSettings} className="space-y-4">
                        <div className="space-y-2">
                            <Label htmlFor="settings-name">Company name</Label>
                            <Input id="settings-name" value={settingsForm.data.name} onChange={(event) => settingsForm.setData('name', event.target.value)} required />
                            <InputError message={settingsForm.errors.name} />
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="settings-status">Status</Label>
                            <Select value={settingsForm.data.status} onValueChange={(value) => settingsForm.setData('status', value)}>
                                <SelectTrigger id="settings-status">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="active">Active</SelectItem>
                                    <SelectItem value="inactive">Inactive</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="settings-manager">Manager</Label>
                            <Select value={managerChoice} onValueChange={setManagerChoice}>
                                <SelectTrigger id="settings-manager">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={KEEP_MANAGER}>
                                        {company.manager ? `Keep ${company.manager.name}` : 'No manager assigned'}
                                    </SelectItem>
                                    {availableManagers.map((manager) => (
                                        <SelectItem key={manager.id} value={String(manager.id)}>
                                            Change to {manager.name} ({manager.email})
                                        </SelectItem>
                                    ))}
                                    <SelectItem value={NEW_MANAGER}>Invite a new manager by email</SelectItem>
                                </SelectContent>
                            </Select>
                            <InputError message={(settingsForm.errors as Record<string, string>).manager_id} />
                            {managerChoice !== KEEP_MANAGER && company.manager && (
                                <p className="text-sm text-amber-700">
                                    {company.manager.name} will lose access to this company and will have no company until an admin assigns one.
                                </p>
                            )}
                        </div>
                        {managerChoice === NEW_MANAGER && (
                            <div className="space-y-2">
                                <Label htmlFor="settings-manager-email">New manager's email</Label>
                                <Input
                                    id="settings-manager-email"
                                    type="email"
                                    value={settingsForm.data.manager_email}
                                    onChange={(event) => settingsForm.setData('manager_email', event.target.value)}
                                    required
                                />
                                <InputError message={settingsForm.errors.manager_email} />
                            </div>
                        )}
                        <div className="flex justify-end gap-3 pt-2">
                            <Button type="button" variant="outline" onClick={() => setShowSettings(false)}>
                                Cancel
                            </Button>
                            <Button type="submit" disabled={settingsForm.processing}>
                                {settingsForm.processing ? 'Saving...' : 'Save Changes'}
                            </Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
