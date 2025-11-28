import { login } from '@/routes';
import { Head } from '@inertiajs/react';
import { useEffect } from 'react';
import { router } from '@inertiajs/react';

export default function Register() {
    useEffect(() => {
        // Redirect to login since registration is disabled
        router.visit(login().url);
    }, []);

    return (
        <>
            <Head title="Registration Disabled" />
            <div className="flex min-h-screen items-center justify-center bg-gray-50 dark:bg-gray-900">
                <div className="w-full max-w-md space-y-6">
                    <div className="text-center">
                        <h1 className="text-2xl font-bold text-gray-900 dark:text-white">
                            Registration Disabled
                        </h1>
                        <p className="mt-2 text-sm text-gray-600 dark:text-gray-400">
                            New account registration is disabled. Please contact your system administrator.
                        </p>
                    </div>
                </div>
            </div>
        </>
    );
}