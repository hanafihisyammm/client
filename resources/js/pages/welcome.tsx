import { dashboard, login } from '@/routes';
import { type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import SidraMark from '@/components/sidra-mark';
import { ArrowRight } from 'lucide-react';

const goldButton =
    'inline-flex items-center justify-center rounded-lg bg-brand-gold font-semibold text-brand-ink transition hover:-translate-y-0.5 hover:shadow-[0_9px_24px_#81725436] focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-gold focus-visible:ring-offset-2 focus-visible:ring-offset-background';

export default function Welcome() {
    const { auth } = usePage<SharedData>().props;

    return (
        <>
            <Head title="Welcome to Sidra Desk">
                <meta name="description" content="Streamline your client communications with our comprehensive issue and requirements tracking system." />
            </Head>
            
            <div className="flex min-h-screen flex-col bg-background text-foreground">
                {/* Header */}
                <header className="relative z-10 border-b border-border">
                    <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                        <div className="flex h-16 items-center justify-between">
                            <div className="flex items-center space-x-3">
                                <SidraMark className="h-9 w-9" />
                                <span className="font-[family-name:var(--font-heading)] text-lg font-extrabold tracking-tight">
                                    Sidra Desk
                                </span>
                            </div>
                            
                            <nav className="flex items-center space-x-4">
                                {auth.user ? (
                                    <Link href={dashboard()} className={`${goldButton} px-4 py-2.5 text-sm`}>
                                        Go to Issues
                                        <ArrowRight className="ml-2 h-4 w-4" />
                                    </Link>
                                ) : (
                                    <Link href={login()} className={`${goldButton} px-4 py-2.5 text-sm`}>
                                        Sign In
                                    </Link>
                                )}
                            </nav>
                        </div>
                    </div>
                </header>

                {/* Hero Section */}
                <main className="relative flex flex-1 items-center justify-center">
                    <div className="mx-auto max-w-7xl px-4 py-20 sm:px-6 lg:px-8">
                        <div className="text-center">
                            {/* Badge */}
                            <div className="mb-8 inline-flex items-center gap-2 rounded-full border border-border bg-card px-4 py-2 text-[10px] font-bold uppercase tracking-[1.35px] text-brand-gold-dark dark:text-[#e0c98a]">
                                <span className="h-1.5 w-1.5 rounded-full bg-brand-gold" />
                                Professional Issue Tracking Platform
                            </div>
                            
                            <h1 className="mb-6 text-4xl font-extrabold tracking-[-0.04em] sm:text-5xl md:text-6xl">
                                <span className="block">Client Issue &</span>
                                <span className="block text-brand-gold-dark dark:text-brand-gold">
                                    Requirements Tracking
                                </span>
                            </h1>
                            <p className="mx-auto mt-6 max-w-3xl text-lg leading-relaxed text-muted-foreground">
                                Streamline your client communications with our comprehensive tracking system. 
                                Replace inefficient PowerPoint and Excel workflows with real-time collaboration, 
                                file attachments, and automated status updates.
                            </p>
                            
                            {!auth.user && (
                                <div className="mt-10 flex flex-col items-center justify-center gap-4 sm:flex-row">
                                    <Link href={login()} className={`${goldButton} group px-7 py-4 text-base`}>
                                        Sign In to Access System
                                        <ArrowRight className="ml-3 h-5 w-5 transition-transform group-hover:translate-x-1" />
                                    </Link>
                                    <div className="text-sm text-muted-foreground">
                                        Trusted by businesses worldwide
                                    </div>
                                </div>
                            )}
                        </div>
                    </div>

                </main>

            </div>
        </>
    );
}
