import { Button } from '@/components/ui/button';
import { flashError } from '@/lib/flash';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import InputError from '@/components/input-error';
import { generateTemporaryPassword, TemporaryPasswordInput } from '@/components/temporary-password-input';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, Check, Copy, PlusIcon, X } from 'lucide-react';
import { useState } from 'react';

interface Props {
    availableRoles: { value: string; label: string }[];
    companies: { id: number; name: string }[];
}

interface Invite {
    email: string;
    role: string;
    company_id: string;
    password: string;
}

const MAX_INVITES = 50;

// Addresses pasted or typed together: one per line, or separated by commas, semicolons or spaces
const splitAddresses = (text: string) => text.split(/[\s,;]+/).map((email) => email.trim()).filter(Boolean);

/** The logins just created. The passwords exist only in this page, so this is the last chance to copy them. */
function CreatedLogins({ logins, onAddMore }: { logins: Invite[]; onAddMore: () => void }) {
    const [copied, setCopied] = useState(false);

    const copyAll = async () => {
        const lines = logins.map((login) => `${login.email}\t${login.password}`);
        await navigator.clipboard.writeText([`Log in at ${window.location.origin}/login`, ...lines].join('\n'));
        setCopied(true);
        setTimeout(() => setCopied(false), 1500);
    };

    return (
        <div className="space-y-4 rounded-lg border bg-card p-6 shadow-sm">
            <div>
                <h2 className="text-lg font-semibold">{logins.length === 1 ? 'Account created' : `${logins.length} accounts created`}</h2>
                <p className="text-sm text-muted-foreground">
                    Copy these now and pass them on, as the passwords won't be shown again. Each person must choose their own password when they first log in.
                </p>
            </div>

            <div className="divide-y rounded-md border">
                {logins.map((login) => (
                    <div key={login.email} className="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 px-3 py-2 text-sm">
                        <span className="min-w-0 break-all">{login.email}</span>
                        <code className="font-mono">{login.password}</code>
                    </div>
                ))}
            </div>

            <div className="flex flex-wrap justify-end gap-3">
                <Button type="button" variant="outline" onClick={copyAll}>
                    {copied ? <Check className="mr-2 h-4 w-4" /> : <Copy className="mr-2 h-4 w-4" />}
                    {copied ? 'Copied' : 'Copy all'}
                </Button>
                <Button type="button" variant="outline" onClick={onAddMore}>
                    Add more people
                </Button>
                <Link href="/users">
                    <Button>Done</Button>
                </Link>
            </div>
        </div>
    );
}

export default function Create({ availableRoles, companies }: Props) {
    const defaultRole = availableRoles.length > 0 ? availableRoles[0].value : 'staff';
    const { data, setData, post, processing, errors, clearErrors } = useForm<{ invites: Invite[] }>({
        invites: [{ email: '', role: defaultRole, company_id: '', password: '' }],
    });
    const [created, setCreated] = useState<Invite[] | null>(null);
    const fieldErrors = errors as Record<string, string>;
    const invites = data.invites;
    const filled = invites.filter((invite) => invite.email.trim() !== '');

    const setInvites = (next: Invite[]) => {
        // Row errors are tied to row positions, which no longer match once rows move
        clearErrors();
        setData('invites', next);
    };

    const updateInvite = (index: number, changes: Partial<Invite>) =>
        setData('invites', invites.map((invite, position) => (position === index ? { ...invite, ...changes } : invite)));

    // A new row starts with the same role and company as the one before it
    const addInvite = () => {
        const last = invites[invites.length - 1];
        setInvites([...invites, { email: '', role: last?.role ?? defaultRole, company_id: last?.company_id ?? '', password: generateTemporaryPassword() }]);
    };

    const removeInvite = (index: number) => setInvites(invites.filter((_, position) => position !== index));

    // Pasting a list of addresses into one row turns it into one row per address
    const handlePaste = (index: number, event: React.ClipboardEvent<HTMLInputElement>) => {
        const addresses = splitAddresses(event.clipboardData.getData('text'));
        if (addresses.length < 2) return;

        event.preventDefault();
        const { role, company_id } = invites[index];
        setInvites([
            ...invites.slice(0, index),
            ...addresses.map((email) => ({ email, role, company_id, password: generateTemporaryPassword() })),
            ...invites.slice(index + 1),
        ]);
    };

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();

        post('/users/bulk', {
            preserveScroll: true,
            // The server sends us back to this page; staying on it keeps the passwords to show below
            preserveState: true,
            onSuccess: () => {
                setCreated(invites.map((invite) => ({ ...invite, email: invite.email.trim().toLowerCase() })));
                setData('invites', [{ email: '', role: defaultRole, company_id: '', password: generateTemporaryPassword() }]);
            },
            onError: (errors) => {
                // Row errors show under their row; anything else is shown as a flash message
                const general = Object.entries(errors).filter(([key]) => !key.startsWith('invites'));
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
            title="Add Users"
            description="Create accounts for one or more users"
        >
            <Head title="Add Users" />

            <div className="flex flex-col gap-6">
                <div className="flex items-center gap-4">
                    <Link href="/users">
                        <Button variant="outline" size="sm">
                            <ArrowLeft className="h-4 w-4 mr-2" />
                            Back
                        </Button>
                    </Link>
                    <div>
                        <h1 className="text-3xl font-extrabold tracking-tight">Add Users</h1>
                        <p className="text-gray-600">Add one person or many at once, each with their own role and password</p>
                    </div>
                </div>

                {created ? (
                    <CreatedLogins logins={created} onAddMore={() => setCreated(null)} />
                ) : (
                    <div className="bg-card shadow-sm border rounded-lg p-6">
                        <form onSubmit={handleSubmit} className="space-y-6">
                            <div className="space-y-3">
                                <div className="flex items-center justify-between">
                                    <Label>People to add</Label>
                                    <span className={`text-xs ${invites.length > MAX_INVITES ? 'font-medium text-red-600' : 'text-gray-500'}`}>
                                        {invites.length} {invites.length === 1 ? 'person' : 'people'}
                                        {invites.length > MAX_INVITES && ` (the limit is ${MAX_INVITES} at a time)`}
                                    </span>
                                </div>

                                {/* Column headings, shown once the rows sit side by side */}
                                <div className="hidden gap-3 text-xs font-medium tracking-wider text-muted-foreground uppercase lg:grid lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_minmax(0,1.3fr)_minmax(0,2fr)_2.25rem]">
                                    <span>Email address</span>
                                    <span>Role</span>
                                    <span>Client company</span>
                                    <span>Temporary password</span>
                                    <span />
                                </div>

                                {invites.map((invite, index) => (
                                    <div key={index} className="grid grid-cols-1 gap-3 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_minmax(0,1.3fr)_minmax(0,2fr)_2.25rem] lg:items-start">
                                        <div className="space-y-1">
                                            <Input
                                                type="email"
                                                aria-label={`Email address of person ${index + 1}`}
                                                value={invite.email}
                                                onChange={(e) => updateInvite(index, { email: e.target.value })}
                                                onPaste={(e) => handlePaste(index, e)}
                                                placeholder="name@company.com"
                                                required
                                                autoFocus={index === 0}
                                            />
                                            <InputError message={fieldErrors[`invites.${index}.email`]} />
                                        </div>
                                        <div className="space-y-1">
                                            <Select value={invite.role} onValueChange={(role) => updateInvite(index, { role })}>
                                                <SelectTrigger aria-label={`Role of person ${index + 1}`}>
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
                                            <InputError message={fieldErrors[`invites.${index}.role`]} />
                                        </div>
                                        <div className="space-y-1">
                                            {invite.role === 'staff' ? (
                                                <Select value={invite.company_id} onValueChange={(company_id) => updateInvite(index, { company_id })}>
                                                    <SelectTrigger aria-label={`Client company of person ${index + 1}`}>
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
                                            ) : (
                                                <p className="flex h-9 items-center text-sm text-muted-foreground">
                                                    {invite.role === 'manager' ? 'Assigned from the Clients page' : 'All companies'}
                                                </p>
                                            )}
                                            <InputError message={fieldErrors[`invites.${index}.company_id`]} />
                                        </div>
                                        <div className="space-y-1">
                                            <TemporaryPasswordInput
                                                label={`temporary password of person ${index + 1}`}
                                                value={invite.password}
                                                onChange={(password) => updateInvite(index, { password })}
                                            />
                                            <InputError message={fieldErrors[`invites.${index}.password`]} />
                                        </div>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            aria-label={`Remove person ${index + 1}`}
                                            disabled={invites.length === 1}
                                            onClick={() => removeInvite(index)}
                                            className="h-9 w-9 p-0 text-gray-500 hover:text-red-600"
                                        >
                                            <X className="h-4 w-4" />
                                        </Button>
                                    </div>
                                ))}

                                <InputError message={fieldErrors.invites} />

                                <Button type="button" variant="outline" size="sm" onClick={addInvite} disabled={invites.length >= MAX_INVITES}>
                                    <PlusIcon className="mr-2 h-4 w-4" />
                                    Add another person
                                </Button>

                                <p className="text-sm text-gray-600">
                                    A password is generated for each person, or type your own. To add many at once, paste a list of addresses into an email box
                                    and it becomes one row per address. No email is sent: you pass on the logins yourself, and each person must choose their own
                                    password when they first log in.
                                </p>
                            </div>

                            {invites.some((invite) => invite.role === 'manager') && (
                                <p className="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">
                                    A new manager has no company yet. Put them in charge of one from the Clients page.
                                </p>
                            )}

                            <div className="flex justify-end gap-3 pt-2">
                                <Link href="/users">
                                    <Button variant="outline">
                                        Cancel
                                    </Button>
                                </Link>
                                <Button type="submit" disabled={processing || invites.length > MAX_INVITES}>
                                    {processing ? 'Creating...' : filled.length > 1 ? `Create ${filled.length} Accounts` : 'Create Account'}
                                </Button>
                            </div>
                        </form>
                    </div>
                )}

                <div className="bg-blue-50 border border-blue-200 rounded-lg p-4">
                    <h3 className="font-medium text-blue-900 mb-2">Role Permissions</h3>
                    <div className="text-sm text-blue-800 space-y-1">
                        <div><strong>Staff:</strong> Belongs to one client company; creates and works on issues in the projects they are assigned to</div>
                        <div><strong>Manager:</strong> Runs one client company: its projects and issues, and assigns its staff to projects</div>
                        <div><strong>Admin:</strong> Manages every client company, user, project and issue</div>
                        <div><strong>Super Admin:</strong> Everything an admin can do, plus creating other admins</div>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
