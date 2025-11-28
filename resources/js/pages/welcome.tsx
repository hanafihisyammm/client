import { dashboard, login } from '@/routes';
import { type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { 
    ClipboardCheck,
    ArrowRight,
    Sparkles
} from 'lucide-react';

export default function Welcome() {
    const { auth } = usePage<SharedData>().props;

    return (
        <>
            <Head title="Welcome to Client Issue Tracking">
                <meta name="description" content="Streamline your client communications with our comprehensive issue and requirements tracking system." />
            </Head>
            
            <div className="min-h-screen bg-gradient-to-br from-blue-50 via-indigo-50 to-purple-50 dark:from-gray-900 dark:via-gray-800 dark:to-gray-900 flex flex-col">
                {/* Header */}
                <header className="relative z-10">
                    <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                        <div className="flex h-16 items-center justify-between">
                            <div className="flex items-center space-x-3">
                                <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-blue-600">
                                    <ClipboardCheck className="h-5 w-5 text-white" />
                                </div>
                                <h1 className="text-xl font-bold text-gray-900 dark:text-white">
                                    Sidra Core Sdn Bhd
                                </h1>
                            </div>
                            
                            <nav className="flex items-center space-x-4">
                                {auth.user ? (
                                    <Link
                                        href={dashboard()}
                                        className="inline-flex items-center rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2"
                                    >
                                        Go to Dashboard
                                        <ArrowRight className="ml-2 h-4 w-4" />
                                    </Link>
                                ) : (
                                    <div className="flex items-center space-x-3">
                                        <Link
                                            href={login()}
                                            className="inline-flex items-center rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-blue-700"
                                        >
                                            Sign In
                                        </Link>
                                    </div>
                                )}
                            </nav>
                        </div>
                    </div>
                </header>

                {/* Hero Section */}
                <main className="relative flex-1 flex items-center justify-center">
                    <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-20">
                        <div className="text-center">
                            {/* Badge */}
                            <div className="mb-8 inline-flex items-center rounded-full bg-white/80 backdrop-blur-sm px-6 py-2 text-sm font-medium text-blue-800 shadow-lg border border-blue-200/50 dark:bg-blue-900/20 dark:text-blue-300">
                                <Sparkles className="mr-2 h-4 w-4" />
                                Professional Issue Tracking Platform
                            </div>
                            
                            <h1 className="text-4xl font-extrabold tracking-tight text-gray-900 sm:text-5xl md:text-6xl dark:text-white mb-6">
                                <span className="block">Client Issue &</span>
                                <span className="block bg-gradient-to-r from-blue-600 via-purple-600 to-indigo-600 bg-clip-text text-transparent">
                                    Requirements Tracking
                                </span>
                            </h1>
                            <p className="mx-auto mt-6 max-w-3xl text-lg text-gray-600 dark:text-gray-300 leading-relaxed">
                                Streamline your client communications with our comprehensive tracking system. 
                                Replace inefficient PowerPoint and Excel workflows with real-time collaboration, 
                                file attachments, and automated status updates.
                            </p>
                            
                            {!auth.user && (
                                <div className="mt-10 flex flex-col sm:flex-row gap-4 justify-center items-center">
                                    <Link
                                        href={login()}
                                        className="group inline-flex items-center rounded-lg bg-gradient-to-r from-blue-600 to-purple-600 px-8 py-4 text-base font-medium text-white shadow-xl hover:shadow-2xl focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 transform transition-all duration-200 hover:scale-105 hover:from-blue-700 hover:to-purple-700 relative overflow-hidden"
                                    >
                                        <span className="absolute inset-0 bg-gradient-to-r from-white/0 via-white/25 to-white/0 -skew-x-12 -translate-x-full group-hover:translate-x-full transition-transform duration-700"></span>
                                        <span className="relative">Sign In to Access System</span>
                                        <ArrowRight className="ml-2 h-5 w-5 transition-transform group-hover:translate-x-1" />
                                    </Link>
                                    <div className="text-sm text-gray-500 dark:text-gray-400 animate-pulse">
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