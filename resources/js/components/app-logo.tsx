import SidraMark from '@/components/sidra-mark';

export default function AppLogo() {
    return (
        <>
            <SidraMark className="size-8" />
            <div className="ml-2 grid flex-1 text-left">
                <span className="truncate text-xs font-semibold leading-tight text-sidebar-foreground">
                    Sidra Desk
                </span>
                <span className="truncate text-xs leading-tight text-sidebar-foreground/60">
                    by Sidra Core
                </span>
            </div>
        </>
    );
}
