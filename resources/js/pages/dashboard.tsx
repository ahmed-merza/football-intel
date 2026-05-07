import { Head, Link } from '@inertiajs/react';
import { formatDistanceToNow, parseISO } from 'date-fns';
import {
    AlertTriangle,
    ArrowUpRight,
    BookOpenText,
    ClipboardList,
    Inbox,
    Sparkles,
    UploadCloud,
    Users,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { ComponentProps, ReactNode } from 'react';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';

type StatCardProps = {
    label: string;
    value: string | number;
    href?: string;
    helper?: string;
    icon: LucideIcon;
    tone?: 'default' | 'warning' | 'critical';
};

function StatCard({
    label,
    value,
    helper,
    icon: Icon,
    tone = 'default',
    href,
}: StatCardProps) {
    const toneStyles: Record<NonNullable<StatCardProps['tone']>, string> = {
        default: 'text-foreground',
        warning: 'text-warning',
        critical: 'text-destructive',
    };

    const card = (
        <Card
            className={cn(
                'h-full transition-colors duration-150 hover:border-primary/40',
                href && 'cursor-pointer',
            )}
        >
            <CardHeader className="flex-row items-center justify-between gap-2 space-y-0 pb-2">
                <CardTitle className="text-sm font-medium text-muted-foreground">
                    {label}
                </CardTitle>
                <Icon
                    className={cn(
                        'size-4 text-muted-foreground',
                        tone !== 'default' && toneStyles[tone],
                    )}
                />
            </CardHeader>
            <CardContent>
                <div
                    data-numeric
                    className={cn(
                        'text-3xl leading-none font-semibold',
                        toneStyles[tone],
                    )}
                >
                    {value}
                </div>
                {helper && (
                    <p className="mt-1.5 text-xs text-muted-foreground">
                        {helper}
                    </p>
                )}
            </CardContent>
        </Card>
    );

    if (href) {
        return (
            <Link href={href} className="block">
                {card}
            </Link>
        );
    }

    return card;
}

type EmptyPanelProps = {
    title: string;
    description: string;
    action?: ReactNode;
    icon: LucideIcon;
} & ComponentProps<'div'>;

function EmptyPanel({
    title,
    description,
    action,
    icon: Icon,
    className,
    ...props
}: EmptyPanelProps) {
    return (
        <div
            className={cn(
                'flex h-full flex-col items-start justify-between gap-6 rounded-xl border border-dashed bg-card/50 p-6',
                className,
            )}
            {...props}
        >
            <div className="flex items-start gap-3">
                <div className="grid size-9 place-items-center rounded-md bg-muted text-muted-foreground">
                    <Icon className="size-4" />
                </div>
                <div>
                    <h3 className="text-sm font-semibold">{title}</h3>
                    <p className="mt-1 text-sm text-muted-foreground">
                        {description}
                    </p>
                </div>
            </div>
            {action}
        </div>
    );
}

type DashboardStats = {
    active_players: number;
    open_alerts: number;
    open_alerts_critical: number;
    needs_review: number;
    recent_uploads: number;
};

type LatestAnalysis = {
    id: number;
    player_id: number;
    player_name: string | null;
    summary_text: string | null;
    generated_at: string | null;
};

type ActivityEntry = {
    kind: 'upload' | 'analysis' | 'alert';
    at: string | null;
    player_id: number | null;
    player_name: string | null;
    message: string;
    severity?: string;
};

type DashboardProps = {
    stats: DashboardStats;
    latest_analyses: LatestAnalysis[];
    recent_activity: ActivityEntry[];
};

export default function Dashboard({
    stats,
    latest_analyses,
    recent_activity,
}: DashboardProps) {
    const alertHelper =
        stats.open_alerts_critical > 0
            ? `${stats.open_alerts_critical} critical`
            : 'Vit D, ferritin, body-comp flags';

    return (
        <>
            <Head title="Dashboard" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <section>
                    <div className="flex items-end justify-between gap-4 pb-4">
                        <div>
                            <h1 className="text-2xl font-semibold tracking-tight">
                                Overview
                            </h1>
                            <p className="text-sm text-muted-foreground">
                                A private space for Dr. Fadhel to monitor squad
                                health, upload new records, and generate
                                reports.
                            </p>
                        </div>
                        <Link
                            href="/players"
                            className="inline-flex items-center gap-1.5 text-sm font-medium text-primary transition-opacity hover:opacity-80"
                        >
                            View roster
                            <ArrowUpRight className="size-4" />
                        </Link>
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <StatCard
                            label="Active players"
                            value={stats.active_players}
                            helper={
                                stats.active_players === 0
                                    ? 'Add a player to start tracking'
                                    : 'Across the federation'
                            }
                            icon={Users}
                            href="/players"
                        />
                        <StatCard
                            label="Open alerts"
                            value={stats.open_alerts}
                            helper={alertHelper}
                            icon={AlertTriangle}
                            tone={
                                stats.open_alerts_critical > 0
                                    ? 'critical'
                                    : stats.open_alerts > 0
                                      ? 'warning'
                                      : 'default'
                            }
                            href="/alerts"
                        />
                        <StatCard
                            label="Needs review"
                            value={stats.needs_review}
                            helper="Low-confidence extractions"
                            icon={ClipboardList}
                            href="/review"
                        />
                        <StatCard
                            label="Recent uploads"
                            value={stats.recent_uploads}
                            helper="Past 7 days"
                            icon={Inbox}
                        />
                    </div>
                </section>

                <section className="grid gap-4 lg:grid-cols-3">
                    <Card className="lg:col-span-2">
                        <CardHeader>
                            <CardTitle>Latest AI analyses</CardTitle>
                            <CardDescription>
                                Most recent Nutritionist Assistant runs across
                                the federation.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            {latest_analyses.length === 0 ? (
                                <EmptyPanel
                                    icon={Sparkles}
                                    title="No analyses yet"
                                    description="Open a player profile, head to the AI analysis tab, and run an analysis. The assistant combines the latest blood + body-comp records into football-specific recommendations."
                                />
                            ) : (
                                <ul className="divide-y">
                                    {latest_analyses.map((a) => (
                                        <li key={a.id}>
                                            <Link
                                                href={`/players/${a.player_id}`}
                                                className="block py-3 transition-colors duration-150 first:pt-0 last:pb-0 hover:opacity-80"
                                            >
                                                <div className="flex flex-wrap items-baseline justify-between gap-2">
                                                    <span className="text-sm font-medium">
                                                        {a.player_name ??
                                                            'Player'}
                                                    </span>
                                                    <span className="text-xs text-muted-foreground">
                                                        {a.generated_at &&
                                                            formatDistanceToNow(
                                                                parseISO(
                                                                    a.generated_at,
                                                                ),
                                                                {
                                                                    addSuffix: true,
                                                                },
                                                            )}
                                                    </span>
                                                </div>
                                                {a.summary_text && (
                                                    <p className="mt-1 line-clamp-2 text-sm text-muted-foreground">
                                                        {a.summary_text}
                                                    </p>
                                                )}
                                            </Link>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Knowledge base</CardTitle>
                            <CardDescription>
                                Supplement + nutrition protocols that ground
                                every recommendation.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <EmptyPanel
                                icon={BookOpenText}
                                title="Coming soon"
                                description="Drag-drop supplement guidelines + nutrition plans here when this feature lands. Every Nutritionist Assistant run will then cite specific chunks."
                                action={
                                    <Link
                                        href="/knowledge"
                                        className="inline-flex items-center gap-1.5 text-xs font-medium text-primary"
                                    >
                                        See planned scope
                                        <ArrowUpRight className="size-3.5" />
                                    </Link>
                                }
                            />
                        </CardContent>
                    </Card>
                </section>

                <section>
                    <Card>
                        <CardHeader>
                            <CardTitle>Recent activity</CardTitle>
                            <CardDescription>
                                Uploads, AI analyses, and alerts from the past
                                two weeks.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            {recent_activity.length === 0 ? (
                                <EmptyPanel
                                    icon={Inbox}
                                    title="Nothing here yet"
                                    description="As records flow in, you'll see them streamed here."
                                />
                            ) : (
                                <ul className="divide-y">
                                    {recent_activity.map((row, i) => (
                                        <ActivityRow key={i} row={row} />
                                    ))}
                                </ul>
                            )}
                        </CardContent>
                    </Card>
                </section>
            </div>
        </>
    );
}

function ActivityRow({ row }: { row: ActivityEntry }) {
    const Icon =
        row.kind === 'upload'
            ? UploadCloud
            : row.kind === 'analysis'
              ? Sparkles
              : AlertTriangle;
    const tone =
        row.kind === 'alert' && row.severity === 'critical'
            ? 'text-destructive'
            : row.kind === 'alert' && row.severity === 'warn'
              ? 'text-warning'
              : 'text-muted-foreground';
    const when = row.at
        ? formatDistanceToNow(parseISO(row.at), { addSuffix: true })
        : '';

    const content = (
        <div className="flex items-start gap-3 py-2.5 first:pt-0 last:pb-0">
            <Icon className={cn('mt-0.5 size-4 shrink-0', tone)} />
            <div className="min-w-0 flex-1">
                <p className="text-sm">
                    <span className="font-medium">
                        {row.player_name ?? 'Unknown player'}
                    </span>{' '}
                    <span className="text-muted-foreground">
                        — {row.message}
                    </span>
                </p>
                <p className="text-xs text-muted-foreground">{when}</p>
            </div>
        </div>
    );

    if (row.player_id !== null) {
        return (
            <li>
                <Link
                    href={`/players/${row.player_id}`}
                    className="block transition-opacity duration-150 hover:opacity-80"
                >
                    {content}
                </Link>
            </li>
        );
    }

    return <li>{content}</li>;
}

Dashboard.layout = {
    breadcrumbs: [{ title: 'Dashboard', href: dashboard() }],
};
