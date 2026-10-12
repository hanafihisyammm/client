import { PageHeader } from '@/components/page-header';
import { StatCard } from '@/components/stat-card';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { Head, router, useForm } from '@inertiajs/react';
import { Ban, Building2, Edit, Eye, FolderKanban, MoreHorizontal, PlusIcon, Search, Users, X, Pencil } from 'lucide-react';
import { useState } from 'react';

interface Manager {
    id: number;
    uuid?: string;
    name: string;
    email: string;
    deactivated_at?: string | null;
}

interface Company {
    id: number;
    uuid: string;
    name: string;
    status: 'active' | 'inactive';
    manager: Manager | null;
    staff_count: number;
    active_staff_count: number;
    projects_count: number;
    open_issues_count: number;
}

interface Props {
    companies: Company[];
    /** Managers who are not yet in charge of a company */
    availableManagers: Manager[];
}

const NEW_MANAGER = 'new';

export default function ClientsIndex({ companies, availableManagers }: Props) {
    const [search, setSearch] = useState('');
    const [showCreate, setShowCreate] = useState(false);
    const [managerChoice, setManagerChoice] = useState(availableManagers.length > 0 ? String(availableManagers[0].id) : NEW_MANAGER);

    const { data, setData, post, processing, errors, reset, clearErrors, transform } = useForm({
        name: '',
        manager_email: '',
    });

    const filtered = companies.filter((company) => {
        const term = search.trim().toLowerCase();
        return !term || company.name.toLowerCase().includes(term) || (company.manager?.name ?? '').toLowerCase().includes(term);
    });

    const closeCreate = () => {
        setShowCreate(false);
        reset();
        clearErrors();
    };

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        // Either an existing manager without a company, or a new one invited by email
        transform((form) =>
            managerChoice === NEW_MANAGER
                ? { name: form.name, manager_email: form.manager_email }
                : { name: form.name, manager_id: Number(managerChoice) },
        );
        post('/clients', { preserveScroll: true, onSuccess: closeCreate });
    };

    return (
        <AppLayout>
            <Head title="Clients" />

            <div className="space-y-6">
                <PageHeader title="Clients" description="Client companies, each with its own manager, staff, projects and issues">
                    <Button className="shadow-sm" onClick={() => setShowCreate(true)}>
                        <PlusIcon className="mr-2 h-4 w-4" />
                        Add Client
                    </Button>
                </PageHeader>

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <StatCard icon={Building2} label="Client Companies" value={companies.length} />
                    <StatCard icon={Users} label="Staff" value={companies.reduce((sum, company) => sum + company.staff_count, 0)} />
                    <StatCard icon={FolderKanban} label="Projects" value={companies.reduce((sum, company) => sum + company.projects_count, 0)} />
                </div>

                <div className="flex items-center gap-3">
                    <div className="relative max-w-md flex-1">
                        <Search className="absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-gray-400" />
                        <Input
                            placeholder="Search clients or managers..."
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            className="h-9 bg-white pl-9"
                        />
                        {search && (
                            <button
                                type="button"
                                aria-label="Clear search"
                                onClick={() => setSearch('')}
                                className="absolute top-1/2 right-2 -translate-y-1/2 rounded p-1 hover:bg-gray-100"
                            >
                                <X className="h-3 w-3 text-gray-400" />
                            </button>
                        )}
                    </div>
                </div>

                <div className="overflow-hidden rounded-xl border bg-white shadow-sm">
                    <Table>
                        <TableHeader>
                            <TableRow className="bg-slate-50/50 text-xs font-medium tracking-wider uppercase [&>th]:text-muted-foreground">
                                <TableHead className="w-[50px]"></TableHead>
                                <TableHead>Client company</TableHead>
                                <TableHead>Manager</TableHead>
                                <TableHead className="text-center">Staff</TableHead>
                                <TableHead className="text-center">Projects</TableHead>
                                <TableHead className="text-center">Open issues</TableHead>
                                <TableHead>Status</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {filtered.length === 0 ? (
                                <TableRow>
                                    <TableCell colSpan={7} className="h-32 text-center text-muted-foreground">
                                        {companies.length === 0 ? 'No client companies yet. Add the first one.' : 'No clients match your search.'}
                                    </TableCell>
                                </TableRow>
                            ) : (
                                filtered.map((company) => (
                                    <TableRow
                                        key={company.id}
                                        className="cursor-pointer hover:bg-brand-paper"
                                        onClick={() => router.get(`/clients/${company.uuid}`)}
                                    >
                                        <TableCell onClick={(event) => event.stopPropagation()}>
                                            <DropdownMenu>
                                                <DropdownMenuTrigger asChild>
                                                    <Button variant="ghost" size="sm" className="h-8 w-8 p-0" aria-label={`Actions for ${company.name}`}>
                                                        <MoreHorizontal className="h-4 w-4" />
                                                    </Button>
                                                </DropdownMenuTrigger>
                                                <DropdownMenuContent align="start" className="w-48">
                                                    <DropdownMenuItem onClick={() => router.get(`/clients/${company.uuid}`)} className="cursor-pointer">
                                                        <Eye className="mr-2 h-4 w-4" />
                                                        View Client
                                                    </DropdownMenuItem>
                                                    <DropdownMenuItem onClick={() => router.get(`/clients/${company.uuid}?edit=1`)} className="cursor-pointer">
                                                        <Pencil className="mr-2 h-4 w-4" />
                                                        Edit Client
                                                    </DropdownMenuItem>
                                                    <DropdownMenuSeparator />
                                                    <DropdownMenuItem
                                                        onClick={() =>
                                                            router.patch(
                                                                `/clients/${company.uuid}`,
                                                                { name: company.name, status: company.status === 'active' ? 'inactive' : 'active' },
                                                                { preserveScroll: true },
                                                            )
                                                        }
                                                        className="cursor-pointer"
                                                    >
                                                        <Ban className="mr-2 h-4 w-4" />
                                                        {company.status === 'active' ? 'Mark as inactive' : 'Mark as active'}
                                                    </DropdownMenuItem>
                                                </DropdownMenuContent>
                                            </DropdownMenu>
                                        </TableCell>
                                        <TableCell className="font-medium">{company.name}</TableCell>
                                        <TableCell>
                                            {company.manager ? (
                                                <div>
                                                    <p className="text-sm">{company.manager.name}</p>
                                                    <p className="text-xs text-muted-foreground">{company.manager.email}</p>
                                                </div>
                                            ) : (
                                                <span className="text-sm text-red-600">No manager</span>
                                            )}
                                        </TableCell>
                                        <TableCell className="text-center">
                                            {company.active_staff_count}
                                            {company.staff_count > company.active_staff_count && (
                                                <span className="text-xs text-muted-foreground"> of {company.staff_count}</span>
                                            )}
                                        </TableCell>
                                        <TableCell className="text-center">{company.projects_count}</TableCell>
                                        <TableCell className="text-center">{company.open_issues_count}</TableCell>
                                        <TableCell>
                                            <span
                                                className={`inline-flex rounded-full border px-2.5 py-0.5 text-xs font-medium ${
                                                    company.status === 'active'
                                                        ? 'border-green-200 bg-green-50 text-green-700'
                                                        : 'border-gray-200 bg-gray-50 text-gray-600'
                                                }`}
                                            >
                                                {company.status === 'active' ? 'Active' : 'Inactive'}
                                            </span>
                                        </TableCell>
                                    </TableRow>
                                ))
                            )}
                        </TableBody>
                    </Table>
                </div>
            </div>

            <Dialog open={showCreate} onOpenChange={(open) => (open ? setShowCreate(true) : closeCreate())}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Add Client</DialogTitle>
                        <DialogDescription>
                            A client company needs one manager, who will run its projects, issues and staff.
                        </DialogDescription>
                    </DialogHeader>
                    <form onSubmit={submit} className="space-y-4">
                        <div className="space-y-2">
                            <Label htmlFor="company-name">Company name</Label>
                            <Input
                                id="company-name"
                                value={data.name}
                                onChange={(event) => setData('name', event.target.value)}
                                placeholder="e.g. Acme Trading"
                                required
                                autoFocus
                            />
                            <InputError message={errors.name} />
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="company-manager">Manager</Label>
                            <Select value={managerChoice} onValueChange={setManagerChoice}>
                                <SelectTrigger id="company-manager">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {availableManagers.map((manager) => (
                                        <SelectItem key={manager.id} value={String(manager.id)}>
                                            {manager.name} ({manager.email})
                                        </SelectItem>
                                    ))}
                                    <SelectItem value={NEW_MANAGER}>Invite a new manager by email</SelectItem>
                                </SelectContent>
                            </Select>
                            <InputError message={(errors as Record<string, string>).manager_id} />
                        </div>

                        {managerChoice === NEW_MANAGER && (
                            <div className="space-y-2">
                                <Label htmlFor="manager-email">New manager's email</Label>
                                <Input
                                    id="manager-email"
                                    type="email"
                                    value={data.manager_email}
                                    onChange={(event) => setData('manager_email', event.target.value)}
                                    placeholder="manager@company.com"
                                    required
                                />
                                <InputError message={errors.manager_email} />
                                <p className="text-sm text-muted-foreground">
                                    They'll get an email to set their own name and password.
                                </p>
                            </div>
                        )}

                        <div className="flex justify-end gap-3 pt-2">
                            <Button type="button" variant="outline" onClick={closeCreate}>
                                Cancel
                            </Button>
                            <Button type="submit" disabled={processing}>
                                {processing ? 'Creating...' : 'Create Client'}
                            </Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
