// Components
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import AuthLayout from '@/layouts/auth-layout';
import { logoutUser } from '@/lib/csrf';
import { send } from '@/routes/verification';
import { Form, Head } from '@inertiajs/react';

export default function VerifyEmail({ status }: { status?: string }) {
    const handleLogout = async (e: React.MouseEvent) => {
        e.preventDefault();
        
        try {
            await logoutUser('/');
        } catch (error) {
            console.error('Logout error:', error);
            // Fallback: redirect anyway
            window.location.href = '/';
        }
    };
    return (
        <AuthLayout
            title="Verify email"
            description="Please verify your email address by clicking on the link we just emailed to you."
        >
            <Head title="Email verification" />

            {status === 'verification-link-sent' && (
                <div className="mb-4 text-center text-sm font-medium text-green-600">
                    A new verification link has been sent to the email address
                    you provided during registration.
                </div>
            )}

            <Form {...send.form()} className="space-y-6 text-center">
                {({ processing }) => (
                    <>
                        <Button disabled={processing} variant="secondary">
                            {processing && <Spinner />}
                            Resend verification email
                        </Button>

                        <button
                            onClick={handleLogout}
                            className="mx-auto block text-sm text-gray-600 underline-offset-4 hover:underline"
                        >
                            Log out
                        </button>
                    </>
                )}
            </Form>
        </AuthLayout>
    );
}
