import { cn } from '@/lib/utils';

/**
 * The Sidra Core logo mark. Its navy strands disappear on dark backgrounds,
 * so in dark mode it sits on a small white tile.
 */
export default function SidraMark({ className }: { className?: string }) {
    return (
        <img
            src="/images/sidra-mark.svg"
            alt="Sidra Core"
            className={cn('shrink-0 object-contain dark:rounded-md dark:bg-white dark:p-0.5', className)}
        />
    );
}
