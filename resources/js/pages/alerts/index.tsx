import { Head, router } from '@inertiajs/react';
import { Inbox } from 'lucide-react';
import { AlertCard } from '@/components/domain/alert-card';
import type { AlertRow } from '@/components/domain/alert-card';
import { Card, CardContent } from '@/components/ui/card';
import { cn } from '@/lib/utils';

type PageProps = {
    alerts: AlertRow[];
    counts: { open: number; acknowledged: number };
    filter: 'open' | 'acknowledged' | 'all';
};

const TABS: { value: PageProps['filter']; label: string }[] = [
    { value: 'open', label: 'Open' },
    { value: 'acknowledged', label: 'Acknowledged' },
    { value: 'all', label: 'All' },
];

export default function AlertsIndex({ alerts, counts, filter }: PageProps) {
    return (
        <>
            <Head title="Alerts" />
            <div className="flex flex-1 flex-col gap-4 p-4">
                <header>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Alerts
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Medical flags raised by the rule-based flagger and the
                        Nutritionist Assistant. Acknowledge to clear from the
                        open queue.
                    </p>
                </header>

                <div className="flex w-fit flex-wrap items-center gap-1 rounded-md border bg-card p-1 text-sm">
                    {TABS.map((tab) => {
                        const active = filter === tab.value;
                        const count =
                            tab.value === 'open'
                                ? counts.open
                                : tab.value === 'acknowledged'
                                  ? counts.acknowledged
                                  : counts.open + counts.acknowledged;

                        return (
                            <button
                                key={tab.value}
                                type="button"
                                onClick={() =>
                                    router.get(
                                        '/alerts',
                                        { filter: tab.value },
                                        {
                                            preserveState: true,
                                            preserveScroll: true,
                                        },
                                    )
                                }
                                className={cn(
                                    'flex items-center gap-2 rounded px-3 py-1.5 font-medium transition-colors duration-150',
                                    active
                                        ? 'bg-primary text-primary-foreground shadow-sm'
                                        : 'text-muted-foreground hover:bg-muted hover:text-foreground',
                                )}
                            >
                                {tab.label}
                                <span
                                    data-numeric
                                    className={cn(
                                        'rounded px-1.5 text-xs tabular-nums',
                                        active
                                            ? 'bg-primary-foreground/15 text-primary-foreground'
                                            : 'bg-muted text-muted-foreground',
                                    )}
                                >
                                    {count}
                                </span>
                            </button>
                        );
                    })}
                </div>

                {alerts.length === 0 ? (
                    <EmptyState filter={filter} />
                ) : (
                    <div className="space-y-3">
                        {alerts.map((alert) => (
                            <AlertCard key={alert.id} alert={alert} />
                        ))}
                    </div>
                )}
            </div>
        </>
    );
}

function EmptyState({ filter }: { filter: PageProps['filter'] }) {
    const message =
        filter === 'open'
            ? 'Nothing to clear right now — every alert raised has been acknowledged.'
            : filter === 'acknowledged'
              ? 'No alerts have been acknowledged yet.'
              : 'No alerts on file. Upload some records and the rule-based flagger will start raising flags on out-of-range values.';

    return (
        <Card>
            <CardContent className="flex flex-col items-start gap-3 py-12 text-center">
                <div className="grid size-10 place-items-center rounded-lg bg-success/15 text-success">
                    <Inbox className="size-5" />
                </div>
                <h3 className="self-center text-sm font-semibold">All clear</h3>
                <p className="max-w-sm self-center text-sm text-muted-foreground">
                    {message}
                </p>
            </CardContent>
        </Card>
    );
}

AlertsIndex.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: '/dashboard' },
        { title: 'Alerts', href: '/alerts' },
    ],
};
