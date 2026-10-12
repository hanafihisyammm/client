import { Button } from '@/components/ui/button';
import { flashError } from '@/lib/flash';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import InputError from '@/components/input-error';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';

interface Props {
    availableRoles: { value: string; label: string }[];
}

export default function Create({ availableRoles }: Props) {
    const { csrfToken } = usePage().props as { csrfToken: string };
    const { data, setData, post, processing, errors } = useForm({
        email: '',
        role: availableRoles.length > 0 ? availableRoles[0].value : 'client'
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        console.log('CSRF Token:', csrfToken);
        console.log('Form data:', data);
        
        post('/users', {
            preserveScroll: true,
            onBefore: () => {
                console.log('Starting form submission...');
            },
            onSuccess: () => {
                console.log('User created successfully');
            },
            onError: (errors) => {
                console.error('Form submission errors:', errors);
                // Field errors show under their field; anything else is shown as a flash message
                const general = Object.entries(errors).filter(([key]) => !['email', 'role'].includes(key));
                if (general.some(([, message]) => String(message).includes('419'))) {
                    flashError('Session expired. Please refresh the page and try again.');
                } else if (general.length > 0) {
                    flashError(String(general[0][1]));
                }
            }
        });
    };

    return (
        <AppLayout
            title="Add User"
            description="Invite a new user by email"
        >
            <Head title="Add User" />

            <div className="flex flex-col gap-6">
                <div className="flex items-center gap-4">
                    <Link href="/users">
                        <Button variant="outline" size="sm">
                            <ArrowLeft className="h-4 w-4 mr-2" />
                            Back
                        </Button>
                    </Link>
                    <div>
                        <h1 className="text-3xl font-extrabold tracking-tight">Add User</h1>
                        <p className="text-gray-600">Invite someone by email and choose their role</p>
                    </div>
                </div>

                <div className="bg-white shadow-sm border rounded-lg p-6">
                    <form onSubmit={handleSubmit} className="space-y-6">
                        <div className="space-y-2">
                            <Label htmlFor="email">Email Address</Label>
                            <Input
                                id="email"
                                type="email"
                                value={data.email}
                                onChange={(e) => setData('email', e.target.value)}
                                placeholder="Enter email address"
                                required
                                autoFocus
                            />
                            <InputError message={errors.email} />
                            <p className="text-sm text-gray-600">
                                We'll email this person a link to set their own name and password. The link is valid for 7 days.
                            </p>
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="role">User Role</Label>
                            <Select
                                value={data.role}
                                onValueChange={(value) => setData('role', value)}
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Select a role" />
                                </SelectTrigger>
                                <SelectContent>
                                    {availableRoles.map((role) => (
                                        <SelectItem key={role.value} value={role.value}>
                                            {role.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={errors.role} />
                            <p className="text-sm text-gray-600">
                                Choose the appropriate role for this user's access level
                            </p>
                        </div>

                        <div className="flex justify-end gap-3 pt-6">
                            <Link href="/users">
                                <Button variant="outline">
                                    Cancel
                                </Button>
                            </Link>
                            <Button type="submit" disabled={processing}>
                                {processing ? 'Sending...' : 'Send Invitation'}
                            </Button>
                        </div>
                    </form>
                </div>

                <div className="bg-blue-50 border border-blue-200 rounded-lg p-4">
                    <h3 className="font-medium text-blue-900 mb-2">Role Permissions</h3>
                    <div className="text-sm text-blue-800 space-y-1">
                        <div><strong>Client:</strong> Can create and view their own issues</div>
                        <div><strong>Developer:</strong> Can be assigned to issues and track time</div>
                        <div><strong>Manager:</strong> Can manage projects and assign developers</div>
                        <div><strong>Admin:</strong> Can manage users and all system functions</div>
                        <div><strong>Super Admin:</strong> Can manage all users including admin accounts</div>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}