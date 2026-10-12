import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import InputError from '@/components/input-error';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, useForm, router } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { useState } from 'react';

interface User {
    id: number;
    uuid: string;
    name: string;
    email: string;
    role: string;
    created_at: string;
    email_verified_at: string | null;
}

interface Props {
    user: User & { company_id?: number | null };
    companies: { id: number; name: string }[];
}

const ROLE_LABELS: Record<string, string> = {
    super_admin: 'Super Admin',
    admin: 'Admin',
    manager: 'Manager',
    staff: 'Staff',
};

export default function Edit({ user, companies }: Props) {
    const { data, setData, put, processing, errors, setError, clearErrors } = useForm({
        name: user.name,
        email: user.email,
        password: '',
        password_confirmation: '',
        company_id: user.company_id ? String(user.company_id) : ''
    });
    
    const [isSubmitting, setIsSubmitting] = useState(false);

    const handleSubmit = async (e: React.FormEvent) => {
        e.preventDefault();
        
        if (isSubmitting) return;
        
        setIsSubmitting(true);
        clearErrors();

        try {
            // Refresh CSRF token before submitting
            const tokenResponse = await fetch('/api/csrf-token', {
                credentials: 'same-origin'
            });
            
            if (tokenResponse.ok) {
                const tokenData = await tokenResponse.json();
                const metaTag = document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement;
                if (metaTag) {
                    metaTag.content = tokenData.token;
                }
            }

            put(`/users/${user.uuid}`, {
                onSuccess: () => {
                    setIsSubmitting(false);
                },
                onError: (errors) => {
                    setIsSubmitting(false);
                    // If still getting CSRF errors, try to reload the page
                    if (Object.keys(errors).some(key => errors[key]?.includes?.('CSRF') || errors[key]?.includes?.('419'))) {
                        window.location.reload();
                    }
                }
            });
        } catch (error) {
            setIsSubmitting(false);
            setError('general', 'Network error. Please try again.');
        }
    };

    return (
        <AppLayout
            title="Edit User"
            description="Update user account information"
        >
            <Head title="Edit User" />

            <div className="flex flex-col gap-6">
                <div className="flex items-center gap-4">
                    <Link href="/users">
                        <Button variant="outline" size="sm">
                            <ArrowLeft className="h-4 w-4 mr-2" />
                            Back
                        </Button>
                    </Link>
                    <div>
                        <h1 className="text-3xl font-extrabold tracking-tight">Edit User</h1>
                        <p className="text-gray-600">Update {user.name}'s account information</p>
                    </div>
                </div>

                <div className="bg-card shadow-sm border rounded-lg p-6">
                    <form onSubmit={handleSubmit} className="space-y-6">
                        <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div className="space-y-2">
                                <Label htmlFor="name">Full Name</Label>
                                <Input
                                    id="name"
                                    type="text"
                                    value={data.name}
                                    onChange={(e) => setData('name', e.target.value)}
                                    placeholder="Enter full name"
                                    required
                                />
                                <InputError message={errors.name} />
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="email">Email Address</Label>
                                <Input
                                    id="email"
                                    type="email"
                                    value={data.email}
                                    onChange={(e) => setData('email', e.target.value)}
                                    placeholder="Enter email address"
                                    required
                                />
                                <InputError message={errors.email} />
                            </div>
                        </div>

                        <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div className="space-y-2">
                                <Label htmlFor="password">New Password</Label>
                                <Input
                                    id="password"
                                    type="password"
                                    value={data.password}
                                    onChange={(e) => setData('password', e.target.value)}
                                    placeholder="Leave blank to keep current password"
                                />
                                <InputError message={errors.password} />
                                <p className="text-sm text-gray-600">
                                    Leave blank to keep the current password
                                </p>
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="password_confirmation">Confirm New Password</Label>
                                <Input
                                    id="password_confirmation"
                                    type="password"
                                    value={data.password_confirmation}
                                    onChange={(e) => setData('password_confirmation', e.target.value)}
                                    placeholder="Confirm new password"
                                />
                            </div>
                        </div>

                        <div className="space-y-2">
                            <Label>User Role</Label>
                            <p className="rounded-md border bg-gray-50 px-3 py-2 text-sm">{ROLE_LABELS[user.role] ?? user.role}</p>
                            <p className="text-sm text-gray-600">
                                The role cannot be changed here. A manager is put in charge of a company from the Clients page.
                            </p>
                        </div>

                        {user.role === 'staff' && (
                            <div className="space-y-2">
                                <Label htmlFor="company_id">Client company</Label>
                                <Select value={data.company_id} onValueChange={(value) => setData('company_id', value)}>
                                    <SelectTrigger id="company_id">
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
                                <InputError message={errors.company_id} />
                                <p className="text-sm text-gray-600">
                                    Moving staff to another company removes them from their current projects.
                                </p>
                            </div>
                        )}

                        <div className="flex justify-end gap-3 pt-6">
                            <Link href="/users">
                                <Button variant="outline">
                                    Cancel
                                </Button>
                            </Link>
                            <Button type="submit" disabled={processing || isSubmitting}>
                                {(processing || isSubmitting) ? 'Updating...' : 'Update User'}
                            </Button>
                        </div>
                    </form>
                </div>

                <div className="bg-blue-50 border border-blue-200 rounded-lg p-4">
                    <h3 className="font-medium text-blue-900 mb-2">Role Permissions</h3>
                    <div className="text-sm text-blue-800 space-y-1">
                        <div><strong>Staff:</strong> Belongs to one client company; creates and works on issues in the projects they are assigned to</div>
                        <div><strong>Manager:</strong> Runs one client company: its projects and issues, and assigns its staff to projects</div>
                        <div><strong>Admin:</strong> Manages every client company, user, project and issue</div>
                    </div>
                </div>

                <div className="bg-gray-50 border border-gray-200 rounded-lg p-4">
                    <h3 className="font-medium text-gray-900 mb-2">User Information</h3>
                    <div className="text-sm text-gray-700 space-y-1">
                        <div><strong>User UUID:</strong> {user.uuid}</div>
                        <div><strong>Created:</strong> {new Date(user.created_at).toLocaleDateString()}</div>
                        <div><strong>Email Verified:</strong> {user.email_verified_at ? 'Yes' : 'No'}</div>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}