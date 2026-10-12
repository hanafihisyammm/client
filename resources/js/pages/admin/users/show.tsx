import AppLayout from '@/layouts/app-layout';
import { Head, Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { ArrowLeft, Edit, Mail, Calendar, Shield, UserCog, Users, Code, User, Pencil } from 'lucide-react';

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

// Avatar component with initials
function UserAvatar({ name, role }: { name: string; role: string }) {
    const initials = name
        .split(' ')
        .map(n => n[0])
        .join('')
        .toUpperCase()
        .slice(0, 2);

    const bgColor = {
        super_admin: 'bg-gradient-to-br from-red-500 to-rose-600',
        admin: 'bg-gradient-to-br from-blue-500 to-indigo-600',
        manager: 'bg-gradient-to-br from-purple-500 to-violet-600',
        staff: 'bg-gradient-to-br from-emerald-500 to-teal-600',
    }[role] || 'bg-gradient-to-br from-gray-400 to-gray-500';

    return (
        <div className={`w-24 h-24 rounded-full ${bgColor} flex items-center justify-center text-white font-bold text-3xl shadow-lg`}>
            {initials}
        </div>
    );
}

// Role badge with icon
function RoleBadge({ role }: { role: string }) {
    const config = {
        super_admin: {
            label: 'Super Admin',
            className: 'bg-red-50 text-red-700 border-red-200',
            icon: Shield
        },
        admin: {
            label: 'Admin',
            className: 'bg-blue-50 text-blue-700 border-blue-200',
            icon: UserCog
        },
        manager: {
            label: 'Manager',
            className: 'bg-purple-50 text-purple-700 border-purple-200',
            icon: Users
        },
        staff: {
            label: 'Staff',
            className: 'bg-emerald-50 text-emerald-700 border-emerald-200',
            icon: Code
        },
    }[role] || {
        label: role,
        className: 'bg-gray-50 text-gray-700 border-gray-200',
        icon: User
    };

    const Icon = config.icon;

    return (
        <span className={`inline-flex items-center gap-2 px-4 py-2 rounded-full text-sm font-medium border ${config.className}`}>
            <Icon className="w-4 h-4" />
            {config.label}
        </span>
    );
}

// Status badge
function StatusBadge({ isActive }: { isActive: boolean }) {
    return isActive ? (
        <span className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-sm font-medium bg-green-50 text-green-700 border border-green-200">
            <span className="w-2 h-2 rounded-full bg-green-500 animate-pulse" />
            Active
        </span>
    ) : (
        <span className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-sm font-medium bg-gray-50 text-gray-600 border border-gray-200">
            <span className="w-2 h-2 rounded-full bg-gray-400" />
            Pending Verification
        </span>
    );
}

export default function Show({ user }: Props) {
    const formatDate = (date: string) => {
        return new Date(date).toLocaleDateString('en-GB', {
            day: 'numeric',
            month: 'long',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
    };

    return (
        <AppLayout>
            <Head title={`${user.name} - User Profile`} />

            <div className="space-y-6">
                {/* Header */}
                <div className="flex items-center justify-between">
                    <div className="flex items-center gap-4">
                        <Link href="/users">
                            <Button variant="outline" size="sm">
                                <ArrowLeft className="h-4 w-4 mr-2" />
                                Back
                            </Button>
                        </Link>
                        <div>
                            <h1 className="text-3xl font-extrabold tracking-tight">User Profile</h1>
                            <p className="text-muted-foreground text-sm mt-1">
                                View user details and information
                            </p>
                        </div>
                    </div>
                    <Link href={`/users/${user.uuid}/edit`}>
                        <Button className="shadow-sm">
                            <Pencil className="h-4 w-4 mr-2" />
                            Edit User
                        </Button>
                    </Link>
                </div>

                {/* Profile Card */}
                <div className="bg-white shadow-sm border rounded-xl overflow-hidden">
                    {/* Profile Content */}
                    <div className="px-8 py-8">
                        {/* Avatar & Name */}
                        <div className="flex flex-col sm:flex-row sm:items-center gap-4">
                            <UserAvatar name={user.name} role={user.role} />
                            <div className="flex-1">
                                <h2 className="text-2xl font-bold text-gray-900">{user.name}</h2>
                                <div className="flex items-center gap-2 mt-1 text-muted-foreground">
                                    <Mail className="w-4 h-4" />
                                    <span>{user.email}</span>
                                </div>
                            </div>
                            <div className="flex gap-2">
                                <RoleBadge role={user.role} />
                                <StatusBadge isActive={!!user.email_verified_at} />
                            </div>
                        </div>

                        {/* Info Grid */}
                        <div className="mt-8 grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div className="bg-slate-50 rounded-xl p-5 border">
                                <h3 className="text-sm font-medium text-muted-foreground uppercase tracking-wider mb-3">
                                    Account Information
                                </h3>
                                <div className="space-y-4">
                                    <div>
                                        <p className="text-sm text-muted-foreground">Full Name</p>
                                        <p className="font-medium text-gray-900">{user.name}</p>
                                    </div>
                                    <div>
                                        <p className="text-sm text-muted-foreground">Email Address</p>
                                        <p className="font-medium text-gray-900">{user.email}</p>
                                    </div>
                                    <div>
                                        <p className="text-sm text-muted-foreground">Role</p>
                                        <p className="font-medium text-gray-900 capitalize">{user.role.replace('_', ' ')}</p>
                                    </div>
                                </div>
                            </div>

                            <div className="bg-slate-50 rounded-xl p-5 border">
                                <h3 className="text-sm font-medium text-muted-foreground uppercase tracking-wider mb-3">
                                    Activity
                                </h3>
                                <div className="space-y-4">
                                    <div>
                                        <p className="text-sm text-muted-foreground">Account Created</p>
                                        <p className="font-medium text-gray-900 flex items-center gap-2">
                                            <Calendar className="w-4 h-4 text-muted-foreground" />
                                            {formatDate(user.created_at)}
                                        </p>
                                    </div>
                                    <div>
                                        <p className="text-sm text-muted-foreground">Email Verified</p>
                                        <p className="font-medium text-gray-900">
                                            {user.email_verified_at
                                                ? formatDate(user.email_verified_at)
                                                : 'Not verified'
                                            }
                                        </p>
                                    </div>
                                    <div>
                                        <p className="text-sm text-muted-foreground">Account Status</p>
                                        <p className="font-medium text-gray-900">
                                            {user.email_verified_at ? 'Active' : 'Pending Verification'}
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
