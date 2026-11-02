import { Form, Head, Link } from '@inertiajs/react';

import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import AuthLayout from '@/layouts/auth-layout';

interface ChangeTemporaryPasswordProps {
    email: string;
    name: string;
}

export default function ChangeTemporaryPassword({ email, name }: ChangeTemporaryPasswordProps) {
    return (
        <AuthLayout
            title="Choose a new password"
            description="You signed in with a password you were given. Choose your own to continue."
        >
            <Head title="Choose a new password" />

            <Form action="/change-password" method="put" resetOnError={['password', 'password_confirmation']}>
                {({ processing, errors }) => (
                    <div className="grid gap-6">
                        <div className="grid gap-2">
                            <Label htmlFor="email">Email</Label>
                            <Input id="email" type="email" value={email} className="h-11" readOnly disabled />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="name">Your name</Label>
                            <Input id="name" type="text" name="name" defaultValue={name} autoComplete="name" className="h-11" required />
                            <InputError message={errors.name} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="password">New password</Label>
                            <Input
                                id="password"
                                type="password"
                                name="password"
                                autoComplete="new-password"
                                className="h-11"
                                placeholder="New password"
                                required
                                autoFocus
                            />
                            <InputError message={errors.password} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="password_confirmation">Confirm new password</Label>
                            <Input
                                id="password_confirmation"
                                type="password"
                                name="password_confirmation"
                                autoComplete="new-password"
                                className="h-11"
                                placeholder="Confirm new password"
                                required
                            />
                            <InputError message={errors.password_confirmation} />
                        </div>

                        <Button type="submit" className="mt-2 h-11 w-full font-semibold" disabled={processing} data-test="change-password-button">
                            {processing && <Spinner />}
                            Save password and continue
                        </Button>

                        <Link href="/logout" method="post" as="button" className="text-center text-sm text-muted-foreground underline underline-offset-4">
                            Log out
                        </Link>
                    </div>
                )}
            </Form>
        </AuthLayout>
    );
}
