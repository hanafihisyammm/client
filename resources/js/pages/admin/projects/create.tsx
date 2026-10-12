import { Head, useForm, router } from '@inertiajs/react';
import { flashError } from '@/lib/flash';
import { FormEventHandler, useState } from 'react';
import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import {
    ArrowLeft, FolderKanban, Users, CheckCircle2, Clock, Archive,
    Search, UserPlus, Check
} from 'lucide-react';

interface User {
    id: number;
    name: string;
    email: string;
    role?: string;
}

interface Props {
    /** Staff who may be put on a project. Admins get every company's; the list below shows the chosen company's */
    clients: User[];
    companies: { id: number; name: string }[];
    /** Set for a manager, whose projects always belong to their own company */
    fixedCompanyId: number | null;
}

const statusOptions = [
    { value: 'active', label: 'Active', icon: CheckCircle2, color: 'text-green-700', bg: 'bg-green-50' },
    { value: 'inactive', label: 'Inactive', icon: Clock, color: 'text-gray-600', bg: 'bg-gray-50' },
    { value: 'completed', label: 'Completed', icon: Archive, color: 'text-blue-600', bg: 'bg-blue-50' },
];

const roleColors: Record<string, string> = {
    super_admin: 'bg-gradient-to-br from-red-500 to-rose-600',
    admin: 'bg-gradient-to-br from-blue-500 to-indigo-600',
    manager: 'bg-gradient-to-br from-purple-500 to-violet-600',
    staff: 'bg-gradient-to-br from-emerald-500 to-teal-600',
};

function generatePrefix(name: string): string {
    if (!name.trim()) return 'PROJ';
    const words = name.trim().split(/[\s\-_]+/).filter(w => w.length > 0);
    if (words.length === 1) {
        return words[0].substring(0, 4).toUpperCase();
    }
    return words.slice(0, 4).map(w => w[0]).join('').toUpperCase() || 'PROJ';
}

export default function ProjectCreate({ clients: allStaff, companies, fixedCompanyId }: Props) {
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        description: '',
        status: 'active',
        company_id: fixedCompanyId ? String(fixedCompanyId) : companies.length === 1 ? String(companies[0].id) : '',
        client_ids: [] as number[],
    });

    // Only staff of the chosen company can be put on its projects
    const clients = allStaff.filter(c => String((c as { company_id?: number }).company_id ?? '') === data.company_id);

    const [searchQuery, setSearchQuery] = useState('');

    const prefix = generatePrefix(data.name);
    const selectedStatus = statusOptions.find(s => s.value === data.status) || statusOptions[0];

    const filteredClients = searchQuery.trim()
        ? clients.filter(c =>
            c.name.toLowerCase().includes(searchQuery.toLowerCase()) ||
            c.email.toLowerCase().includes(searchQuery.toLowerCase())
        )
        : clients;

    const toggleClient = (clientId: number) => {
        const current = [...data.client_ids];
        const index = current.indexOf(clientId);
        if (index > -1) {
            current.splice(index, 1);
        } else {
            current.push(clientId);
        }
        setData('client_ids', current);
    };

    const isClientSelected = (clientId: number) => data.client_ids.includes(clientId);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        if (!data.name.trim()) {
            flashError('Project name is required.');
            return;
        }
        if (!data.company_id) {
            flashError('Choose the client company this project belongs to.');
            return;
        }
        post('/projects');
    };

    return (
        <AppLayout>
            <Head title="Create Project" />

            <div className="space-y-6">
                {/* Header */}
                <div className="flex items-center gap-4">
                    <Button variant="outline" size="sm" onClick={() => router.get('/projects')}>
                        <ArrowLeft className="w-4 h-4 mr-2" />
                        Back
                    </Button>
                    <div>
                        <h1 className="text-3xl font-extrabold tracking-tight">Create Project</h1>
                        <p className="text-muted-foreground text-sm mt-1">Set up a new project and assign team members</p>
                    </div>
                </div>

                <form onSubmit={submit}>
                    <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                        {/* Left Column */}
                        <div className="lg:col-span-2 space-y-6">
                            {/* Project Details */}
                            <div className="bg-card border rounded-xl p-6 space-y-5">
                                <div className="flex items-center gap-3 pb-4 border-b">
                                    <div className="p-2 bg-indigo-50 rounded-lg">
                                        <FolderKanban className="w-5 h-5 text-indigo-600" />
                                    </div>
                                    <div>
                                        <h2 className="font-medium text-gray-900">Project Details</h2>
                                        <p className="text-sm text-gray-500">Basic information about your project</p>
                                    </div>
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="name">Project Name <span className="text-red-600">*</span></Label>
                                    <Input
                                        id="name"
                                        value={data.name}
                                        onChange={(e) => setData('name', e.target.value)}
                                        placeholder="e.g., Website Redesign"
                                        className="h-11"
                                    />
                                    {data.name && (
                                        <p className="text-xs text-gray-500">
                                            Prefix: <span className="font-mono font-medium text-indigo-600">{prefix}</span>
                                        </p>
                                    )}
                                    {errors.name && <p className="text-red-600 text-sm">{errors.name}</p>}
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="description">Description</Label>
                                    <Textarea
                                        id="description"
                                        value={data.description}
                                        onChange={(e) => setData('description', e.target.value)}
                                        placeholder="Project scope and objectives..."
                                        rows={4}
                                    />
                                </div>

                                <div className="space-y-2">
                                    <Label>Status</Label>
                                    <div className="grid grid-cols-3 gap-3">
                                        {statusOptions.map((option) => {
                                            const Icon = option.icon;
                                            const isActive = data.status === option.value;
                                            return (
                                                <button
                                                    key={option.value}
                                                    type="button"
                                                    onClick={() => setData('status', option.value)}
                                                    className={`flex flex-col items-center gap-2 p-4 rounded-xl border-2 transition-all ${
                                                        isActive
                                                            ? 'border-indigo-500 bg-indigo-50'
                                                            : 'border-gray-200 hover:border-gray-300'
                                                    }`}
                                                >
                                                    <div className={`p-2 rounded-lg ${isActive ? 'bg-indigo-100' : option.bg}`}>
                                                        <Icon className={`w-5 h-5 ${isActive ? 'text-indigo-600' : option.color}`} />
                                                    </div>
                                                    <span className={`text-sm font-medium ${isActive ? 'text-indigo-900' : 'text-gray-700'}`}>
                                                        {option.label}
                                                    </span>
                                                </button>
                                            );
                                        })}
                                    </div>
                                </div>
                            </div>

                            {/* Client company */}
                            {!fixedCompanyId && (
                                <div className="bg-card border rounded-xl p-6 space-y-3">
                                    <Label htmlFor="company_id" className="text-sm font-medium text-gray-700">
                                        Client company <span className="text-red-600">*</span>
                                    </Label>
                                    <Select
                                        value={data.company_id}
                                        onValueChange={(value) => {
                                            // Staff picked for another company must not carry over
                                            setData((current) => ({ ...current, company_id: value, client_ids: [] }));
                                        }}
                                    >
                                        <SelectTrigger id="company_id" className="h-11">
                                            <SelectValue placeholder="Select a client company" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {companies.map((company) => (
                                                <SelectItem key={company.id} value={String(company.id)}>
                                                    {company.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {errors.company_id && <p className="text-sm text-red-600">{errors.company_id}</p>}
                                    <p className="text-sm text-gray-500">
                                        The project, its staff and its issues will belong to this company only.
                                    </p>
                                </div>
                            )}

                            {/* Team Members */}
                            <div className="bg-card border rounded-xl p-6 space-y-5">
                                <div className="flex items-center justify-between pb-4 border-b">
                                    <div className="flex items-center gap-3">
                                        <div className="p-2 bg-amber-50 rounded-lg">
                                            <Users className="w-5 h-5 text-amber-600" />
                                        </div>
                                        <div>
                                            <h2 className="font-medium text-gray-900">Staff</h2>
                                            <p className="text-sm text-gray-500">Assign staff of this company to the project. You can add more later.</p>
                                        </div>
                                    </div>
                                    {data.client_ids.length > 0 && (
                                        <span className="text-sm font-medium text-indigo-600 bg-indigo-50 px-3 py-1 rounded-full">
                                            {data.client_ids.length} selected
                                        </span>
                                    )}
                                </div>

                                {clients.length > 5 && (
                                    <div className="relative">
                                        <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" />
                                        <Input
                                            value={searchQuery}
                                            onChange={(e) => setSearchQuery(e.target.value)}
                                            placeholder="Search users..."
                                            className="pl-10"
                                        />
                                    </div>
                                )}

                                {clients.length === 0 ? (
                                    <div className="text-center py-8">
                                        <UserPlus className="w-10 h-10 text-gray-300 mx-auto mb-3" />
                                        <p className="text-gray-500">
                                            {data.company_id ? 'This company has no active staff yet.' : 'Choose a client company to see its staff.'}
                                        </p>
                                    </div>
                                ) : (
                                    <div className="space-y-2 max-h-72 overflow-y-auto">
                                        {filteredClients.map((client) => {
                                            const selected = isClientSelected(client.id);
                                            const role = client.role || 'staff';
                                            return (
                                                <div
                                                    key={client.id}
                                                    onClick={() => toggleClient(client.id)}
                                                    className={`flex items-center gap-3 p-3 rounded-lg border cursor-pointer transition-all ${
                                                        selected
                                                            ? 'border-indigo-300 bg-indigo-50'
                                                            : 'border-gray-200 hover:border-gray-300 hover:bg-gray-50'
                                                    }`}
                                                >
                                                    <div className={`w-5 h-5 rounded border-2 flex items-center justify-center ${
                                                        selected ? 'bg-indigo-600 border-indigo-600' : 'border-gray-300'
                                                    }`}>
                                                        {selected && <Check className="w-3 h-3 text-white" />}
                                                    </div>
                                                    <div className={`w-9 h-9 rounded-full ${roleColors[role]} flex items-center justify-center text-white text-sm font-medium`}>
                                                        {client.name.charAt(0)}
                                                    </div>
                                                    <div className="flex-1 min-w-0">
                                                        <p className="font-medium text-gray-900 truncate">{client.name}</p>
                                                        <p className="text-sm text-gray-500 truncate">{client.email}</p>
                                                    </div>
                                                    <span className={`text-xs px-2 py-1 rounded-full capitalize ${
                                                        role === 'staff' ? 'bg-emerald-100 text-emerald-700' :
                                                        role === 'manager' ? 'bg-purple-100 text-purple-700' :
                                                        'bg-gray-100 text-gray-700'
                                                    }`}>
                                                        {role.replace('_', ' ')}
                                                    </span>
                                                </div>
                                            );
                                        })}
                                    </div>
                                )}
                                {errors.client_ids && <p className="text-red-600 text-sm">{errors.client_ids}</p>}
                            </div>
                        </div>

                        {/* Right Column */}
                        <div className="space-y-6">
                            {/* Preview */}
                            <div className="bg-card border rounded-xl p-6">
                                <h3 className="font-medium text-gray-900 mb-4">Preview</h3>
                                <div className="p-4 bg-gray-50 rounded-lg border space-y-3">
                                    <div className="flex items-start gap-3">
                                        <div className="w-10 h-10 rounded-lg bg-gradient-to-br from-indigo-500 to-purple-600 flex items-center justify-center text-white font-bold text-sm">
                                            {prefix.substring(0, 2)}
                                        </div>
                                        <div>
                                            <p className="font-semibold text-gray-900">{data.name || 'Project Name'}</p>
                                            <p className="text-xs font-mono text-gray-500">{prefix}</p>
                                        </div>
                                    </div>
                                    <div className={`inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium ${selectedStatus.bg} ${selectedStatus.color}`}>
                                        <selectedStatus.icon className="w-3 h-3" />
                                        {selectedStatus.label}
                                    </div>
                                    {data.client_ids.length > 0 && (
                                        <div className="pt-2 border-t">
                                            <p className="text-xs text-gray-500 mb-2">Team ({data.client_ids.length})</p>
                                            <div className="flex -space-x-2">
                                                {data.client_ids.slice(0, 5).map((id) => {
                                                    const c = clients.find(x => x.id === id);
                                                    if (!c) return null;
                                                    return (
                                                        <div
                                                            key={id}
                                                            className={`w-7 h-7 rounded-full ${roleColors[c.role || 'staff']} flex items-center justify-center text-white text-xs font-medium border-2 border-white`}
                                                        >
                                                            {c.name.charAt(0)}
                                                        </div>
                                                    );
                                                })}
                                                {data.client_ids.length > 5 && (
                                                    <div className="w-7 h-7 rounded-full bg-gray-200 flex items-center justify-center text-xs text-gray-600 border-2 border-white">
                                                        +{data.client_ids.length - 5}
                                                    </div>
                                                )}
                                            </div>
                                        </div>
                                    )}
                                </div>
                            </div>

                            {/* Info */}
                            <div className="bg-blue-50 border border-blue-100 rounded-xl p-4">
                                <h4 className="font-medium text-blue-900 mb-2">Project Prefix</h4>
                                <p className="text-sm text-blue-700">
                                    Issues will use <span className="font-mono font-semibold">{prefix}-1</span>, <span className="font-mono font-semibold">{prefix}-2</span>, etc.
                                </p>
                            </div>

                            {/* Buttons */}
                            <div className="space-y-3">
                                <Button
                                    type="submit"
                                    disabled={processing || !data.name.trim() || data.client_ids.length === 0}
                                    className="w-full h-11"
                                >
                                    {processing ? 'Creating...' : 'Create Project'}
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => router.get('/projects')}
                                    className="w-full h-11"
                                >
                                    Cancel
                                </Button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
