import { Sparkles } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { Card, CardContent } from '@/components/ui/card';

type ComingSoonPanelProps = {
    title: string;
    icon?: LucideIcon;
};

/**
 * Placeholder for nav-stub pages whose feature is in the roadmap but
 * not yet shipped. Deliberately quiet — no scope details, since the
 * shape of the feature can shift before it lands. Just a clean
 * "coming soon" surface so the admin sees the page is alive.
 */
export function ComingSoonPanel({
    title,
    icon: Icon = Sparkles,
}: ComingSoonPanelProps) {
    return (
        <div className="flex flex-1 flex-col gap-4 p-4">
            <header>
                <h1 className="text-2xl font-semibold tracking-tight">
                    {title}
                </h1>
            </header>

            <Card className="overflow-hidden">
                <CardContent className="relative grid place-items-center gap-4 px-6 py-20 text-center">
                    <div
                        aria-hidden
                        className="pointer-events-none absolute inset-0 bg-[radial-gradient(circle_at_50%_30%,theme(colors.primary/8%),transparent_60%)]"
                    />

                    <div className="relative grid size-16 place-items-center rounded-2xl border bg-card shadow-sm">
                        <Icon className="size-7 text-primary" />
                    </div>

                    <div className="relative space-y-1.5">
                        <h2 className="text-xl font-semibold tracking-tight">
                            Coming soon
                        </h2>
                        <p className="max-w-sm text-sm text-muted-foreground">
                            This part of the system is on the roadmap. Check
                            back as the federation tooling fills out.
                        </p>
                    </div>
                </CardContent>
            </Card>
        </div>
    );
}
