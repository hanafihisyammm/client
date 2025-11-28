import { Button } from '@/components/ui/button';
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
    const { data, setData, post, processing, errors, recentlySuccessful } = useForm({
        name: '',
        email: '',
        password: '',
        password_confirmation: '',
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
                if (errors.message && errors.message.includes('419')) {
                    alert('Session expired. Please refresh the page and try again.');
                }
            }
        });
    };

    return (
        <AppLayout
            title="Add User"
            description="Create a new user account"
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
                        <h1 className="text-2xl font-semibold text-gray-900">Add New User</h1>
                        <p className="text-gray-600">Create a new user account with appropriate role</p>
                    </div>
                </div>

                <div className="bg-white shadow-sm border rounded-lg p-6">
                    {recentlySuccessful && (
                        <div className="mb-4 p-4 bg-green-50 border border-green-200 rounded-lg">
                            <p className="text-sm text-green-800">User created successfully!</p>
                        </div>
                    )}

                    {Object.keys(errors).length > 0 && (
                        <div className="mb-4 p-4 bg-red-50 border border-red-200 rounded-lg">
                            <h4 className="text-sm font-medium text-red-800 mb-2">There was an error:</h4>
                            {Object.keys(errors).map(key => (
                                <p key={key} className="text-sm text-red-700">{errors[key]}</p>
                            ))}
                            {(errors.message && errors.message.includes('419')) && (
                                <div className="mt-3">
                                    <Button 
                                        type="button" 
                                        variant="outline" 
                                        size="sm"
                                        onClick={() => window.location.reload()}
                                    >
                                        Refresh Page
                                    </Button>
                                </div>
                            )}
                        </div>
                    )}
                    
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
                                <Label htmlFor="password">Password</Label>
                                <Input
                                    id="password"
                                    type="password"
                                    value={data.password}
                                    onChange={(e) => setData('password', e.target.value)}
                                    placeholder="Enter password"
                                    required
                                />
                                <InputError message={errors.password} />
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="password_confirmation">Confirm Password</Label>
                                <Input
                                    id="password_confirmation"
                                    type="password"
                                    value={data.password_confirmation}
                                    onChange={(e) => setData('password_confirmation', e.target.value)}
                                    placeholder="Confirm password"
                                    required
                                />
                            </div>
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
                                {availableRoles.length === 1 
                                    ? `As an admin, you can only create ${availableRoles[0].label} accounts.`
                                    : "Choose the appropriate role for this user's access level"
                                }
                            </p>
                        </div>

                        <div className="flex justify-end gap-3 pt-6">
                            <Link href="/users">
                                <Button variant="outline">
                                    Cancel
                                </Button>
                            </Link>
                            <Button type="submit" disabled={processing}>
                                {processing ? 'Creating...' : 'Create User'}
                            </Button>
                        </div>
                    </form>
                </div>

                <div className="bg-blue-50 border border-blue-200 rounded-lg p-4">
                    <h3 className="font-medium text-blue-900 mb-2">Role Permissions</h3>
                    <div className="text-sm text-blue-800 space-y-1">
                        <div><strong>Client:</strong> Can create and view their own reports</div>
                        <div><strong>Admin:</strong> Can manage client users and all system functions</div>
                        <div><strong>Super Admin:</strong> Can manage all users including admin accounts</div>
                    </div>
                    {availableRoles.length === 1 && (
                        <div className="mt-3 p-3 bg-yellow-50 border border-yellow-200 rounded">
                            <p className="text-sm text-yellow-800">
                                <strong>Note:</strong> As an admin, you can only create client accounts. 
                                Only super admins can create admin accounts.
                            </p>
                        </div>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}