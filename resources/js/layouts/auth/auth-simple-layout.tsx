import { home } from '@/routes';
import { Link } from '@inertiajs/react';
import { type PropsWithChildren } from 'react';
import SidraMark from '@/components/sidra-mark';

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
        <div className="flex min-h-screen flex-col items-center justify-center bg-background p-6 text-foreground md:p-10">

            {/* Main Content */}
            <div className="w-full max-w-sm">
                <div className="flex flex-col gap-8">
                    {/* Header */}
                    <div className="flex flex-col items-center gap-4">
                        <Link
                            href={home()}
                            className="flex flex-col items-center gap-3 font-medium group"
                        >
                            <SidraMark className="mb-2 h-14 w-14 transition-transform duration-200 group-hover:-translate-y-0.5" />
                            <div className="text-center">
                                <h2 className="text-lg font-extrabold">
                                    Sidra Desk
                                </h2>
                                <p className="eyebrow mt-1">
                                    Client Issue & Requirements Tracking
                                </p>
                            </div>
                            <span className="sr-only">{title}</span>
                        </Link>

                        <div className="space-y-2 text-center">
                            <h1 className="text-2xl font-extrabold">{title}</h1>
                            <p className="text-center text-sm text-muted-foreground">
                                {description}
                            </p>
                        </div>
                    </div>

                    {/* Form Container */}
                    <div className="rounded-[14px] border border-border bg-card p-6 shadow-[0_9px_24px_#81725414]">
                        {children}
                    </div>
                </div>
            </div>
        </div>
    );
}
