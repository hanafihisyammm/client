import { ClipboardCheck } from 'lucide-react';

export default function AppLogo() {
    return (
        <>
            <div className="flex aspect-square size-7 items-center justify-center rounded-md bg-gradient-to-r from-blue-600 to-purple-600 text-white">
                <ClipboardCheck className="size-4 text-white" />
            </div>
            <div className="ml-2 grid flex-1 text-left">
                <span className="truncate text-xs font-medium text-gray-900 dark:text-white leading-tight">
                    Sidra Core Sdn Bhd
                </span>
                <span className="truncate text-xs text-gray-500 dark:text-gray-400 leading-tight">
                    Issue Tracking
                </span>
            </div>
        </>
    );
}
