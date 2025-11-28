import AppLogoIcon from '@/components/app-logo-icon';
import { home } from '@/routes';
import { Link } from '@inertiajs/react';
import { type PropsWithChildren } from 'react';
import { ClipboardCheck } from 'lucide-react';

interface AuthLayoutProps {
    name?: string;
    title?: string;
    description?: string;
}

export default function AuthSimpleLayout({
    children,
    title,
    description,
}: PropsWithChildren<AuthLayoutProps>) {
    return (
        <div className="min-h-screen bg-gradient-to-br from-blue-50 via-indigo-50 to-purple-50 dark:from-gray-900 dark:via-gray-800 dark:to-gray-900 flex flex-col items-center justify-center p-6 md:p-10">

            {/* Main Content */}
            <div className="w-full max-w-sm">
                <div className="flex flex-col gap-8">
                    {/* Header */}
                    <div className="flex flex-col items-center gap-4">
                        <Link
                            href={home()}
                            className="flex flex-col items-center gap-3 font-medium group"
                        >
                            <div className="mb-2 flex h-12 w-12 items-center justify-center rounded-xl bg-gradient-to-r from-blue-600 to-purple-600 shadow-lg group-hover:shadow-xl transition-all duration-200 group-hover:scale-105">
                                <ClipboardCheck className="h-7 w-7 text-white" />
                            </div>
                            <div className="text-center">
                                <h2 className="text-lg font-bold text-gray-900 dark:text-white">
                                    Sidra Core Sdn Bhd
                                </h2>
                                <p className="text-xs text-blue-600 dark:text-blue-400 font-medium">
                                    Client Issue & Requirements Tracking
                                </p>
                            </div>
                            <span className="sr-only">{title}</span>
                        </Link>

                        <div className="space-y-2 text-center">
                            <h1 className="text-2xl font-bold text-gray-900 dark:text-white">{title}</h1>
                            <p className="text-center text-sm text-gray-600 dark:text-gray-300">
                                {description}
                            </p>
                        </div>
                    </div>

                    {/* Form Container */}
                    <div className="bg-white/80 dark:bg-gray-800/80 backdrop-blur-sm rounded-xl shadow-xl border border-white/20 dark:border-gray-700/20 p-6">
                        {children}
                    </div>
                </div>
            </div>
        </div>
    );
}
