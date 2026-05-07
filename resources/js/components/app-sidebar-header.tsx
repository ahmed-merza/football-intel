import { Search } from 'lucide-react';
import { useSyncExternalStore } from 'react';
import { Breadcrumbs } from '@/components/breadcrumbs';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { useCommandPalette } from '@/hooks/use-command-palette';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

// SSR-safe: useSyncExternalStore's server snapshot returns false, the
// client snapshot reads navigator.platform. No effect, no flash of wrong
// shortcut hint.
const subscribe = (): (() => void) => () => {};
const getIsMac = (): boolean => /Mac|iPhone|iPad|iPod/.test(navigator.platform);
const getServerIsMac = (): boolean => false;

export function AppSidebarHeader({
    breadcrumbs = [],
}: {
    breadcrumbs?: BreadcrumbItemType[];
}) {
    const { setOpen } = useCommandPalette();
    const isMac = useSyncExternalStore(subscribe, getIsMac, getServerIsMac);

    return (
        <header className="flex h-16 shrink-0 items-center gap-2 border-b border-sidebar-border/50 px-6 transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12 md:px-4">
            <div className="flex items-center gap-2">
                <SidebarTrigger className="-ml-1" />
                <Breadcrumbs breadcrumbs={breadcrumbs} />
            </div>
            <button
                type="button"
                onClick={() => setOpen(true)}
                aria-label="Open command palette"
                className="ml-auto inline-flex items-center gap-2 rounded-md border bg-background/50 px-3 py-1.5 text-sm text-muted-foreground shadow-xs transition-colors duration-150 hover:bg-accent hover:text-foreground"
            >
                <Search className="size-4" />
                <span className="hidden sm:inline">Search…</span>
                <kbd className="hidden items-center gap-0.5 rounded border bg-muted px-1.5 font-mono text-[10px] font-medium text-muted-foreground tabular-nums sm:inline-flex">
                    {isMac ? '⌘' : 'Ctrl'} K
                </kbd>
            </button>
        </header>
    );
}
