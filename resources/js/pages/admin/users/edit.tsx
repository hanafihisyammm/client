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
    user: User;
}

export default function Edit({ user }: Props) {
    const { data, setData, put, processing, errors, setError, clearErrors } = useForm({
        name: user.name,
        email: user.email,
        password: '',
        password_confirmation: '',
        role: user.role
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
                        <h1 className="text-2xl font-semibold text-gray-900">Edit User</h1>
                        <p className="text-gray-600">Update {user.name}'s account information</p>
                    </div>
                </div>

                <div className="bg-white shadow-sm border rounded-lg p-6">
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
                            <Label htmlFor="role">User Role</Label>
                            <Select
                                value={data.role}
                                onValueChange={(value) => setData('role', value)}
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Select a role" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="client">Client</SelectItem>
                                    <SelectItem value="admin">Admin</SelectItem>
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
                            <Button type="submit" disabled={processing || isSubmitting}>
                                {(processing || isSubmitting) ? 'Updating...' : 'Update User'}
                            </Button>
                        </div>
                    </form>
                </div>

                <div className="bg-blue-50 border border-blue-200 rounded-lg p-4">
                    <h3 className="font-medium text-blue-900 mb-2">Role Permissions</h3>
                    <div className="text-sm text-blue-800 space-y-1">
                        <div><strong>Client:</strong> Can create and view their own reports</div>
                        <div><strong>Developer:</strong> Can manage reports and view project details</div>
                        <div><strong>Admin:</strong> Can manage users and all system functions</div>
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