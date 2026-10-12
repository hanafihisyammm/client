import { Form, Head } from '@inertiajs/react';

import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import AuthLayout from '@/layouts/auth-layout';

interface AcceptInvitationProps {
    email: string;
    name: string;
    submitUrl: string;
}

export default function AcceptInvitation({ email, name, submitUrl }: AcceptInvitationProps) {
    return (
        <AuthLayout
            title="Set up your account"
            description="Confirm your name and choose a password to get started"
        >
            <Head title="Set up your account" />

            <Form action={submitUrl} method="post" resetOnError={['password', 'password_confirmation']}>
                {({ processing, errors }) => (
                    <div className="grid gap-6">
                        <div className="grid gap-2">
                            <Label htmlFor="email">Email</Label>
                            <Input id="email" type="email" value={email} className="h-11" readOnly disabled />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="name">Your name</Label>
                            <Input
                                id="name"
                                type="text"
                                name="name"
                                defaultValue={name}
                                autoComplete="name"
                                className="h-11"
                                required
                                autoFocus
                            />
                            <InputError message={errors.name} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="password">Password</Label>
                            <Input
                                id="password"
                                type="password"
                                name="password"
                                autoComplete="new-password"
                                className="h-11"
                                placeholder="Password"
                                required
                            />
                            <InputError message={errors.password} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="password_confirmation">Confirm password</Label>
                            <Input
                                id="password_confirmation"
                                type="password"
                                name="password_confirmation"
                                autoComplete="new-password"
                                className="h-11"
                                placeholder="Confirm password"
                                required
                            />
                            <InputError message={errors.password_confirmation} />
                        </div>

                        <Button
                            type="submit"
                            className="mt-2 h-11 w-full font-semibold"
                            disabled={processing}
                            data-test="accept-invitation-button"
                        >
                            {processing && <Spinner />}
                            Set password and continue
                        </Button>
                    </div>
                )}
            </Form>
        </AuthLayout>
    );
}
