import { Head, Link, router } from '@inertiajs/react';
import {
    AlertTriangle,
    Archive,
    FileText,
    LineChart,
    ListTodo,
    Pencil,
    Sparkles,
    TimerReset,
    UploadCloud,
} from 'lucide-react';
import { lazy, Suspense, useEffect, useState } from 'react';
import { AlertCard } from '@/components/domain/alert-card';
import type { AlertRow } from '@/components/domain/alert-card';
import type { MetricPoint } from '@/components/domain/metric-chart';
import { PlayerAiAnalysis } from '@/components/domain/player-ai-analysis';
import type { Analysis } from '@/components/domain/player-ai-analysis';
import { PlayerAvatar } from '@/components/domain/player-avatar';
import { PlayerDocuments } from '@/components/domain/player-documents';
import type { DocumentEntry } from '@/components/domain/player-documents';
import { PlayerStatusBadge } from '@/components/domain/player-status-badge';
import { PlayerTimeline } from '@/components/domain/player-timeline';
import type {
    InflightSubmission,
    TimelineCursor,
} from '@/components/domain/player-timeline';
import { UploadDrawer } from '@/components/domain/upload-drawer';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';

// Lazy-loaded so ECharts (~140 KB gzipped) only downloads when the admin
// opens the Metrics tab. Timeline users pay nothing for it.
const PlayerMetrics = lazy(() =>
    import('@/components/domain/player-metrics').then((m) => ({
        default: m.PlayerMetrics,
    })),
);

type PlayerPayload = {
    id: number;
    full_name: string;
    name_ar: string | null;
    club: string | null;
    position: string | null;
    date_of_birth: string | null;
    nationality: string | null;
    height_cm: number | null;
    weight_kg: number | null;
    preferred_foot: string | null;
    player_code: string | null;
    phone: string | null;
    email: string | null;
    photo_url: string | null;
    status: 'active' | 'inactive' | 'archived';
    age: number | null;
    bmi: number | null;
};

type TimelineEntry = {
    id: number;
    record_date: string;
    category: { slug: string; label: string };
    summary_text: string | null;
    source_lab: string | null;
    reviewed: boolean;
    attachment_filename: string | null;
    extraction_pending: boolean;
    extraction_failed: boolean;
    extraction_failure_reason: string | null;
};

type PageProps = {
    player: PlayerPayload;
    timeline: TimelineEntry[];
    timeline_next_cursor: TimelineCursor | null;
    inflight: InflightSubmission[];
    metrics: Record<string, MetricPoint[]>;
    documents: DocumentEntry[];
    analyses: Analysis[];
    analysis_eligibility: {
        has_blood: boolean;
        has_inbody: boolean;
        can_run: boolean;
    };
    alerts: AlertRow[];
    counts: { records: number; open_alerts: number };
};

export default function PlayersShow({
    player,
    timeline,
    timeline_next_cursor,
    inflight,
    metrics,
    documents,
    analyses,
    analysis_eligibility,
    alerts,
    counts,
}: PageProps) {
    const [uploadOpen, setUploadOpen] = useState(false);

    // Poll while any upload is still processing OR a Nutritionist
    // Assistant run is queued — both can take 30–60s and the admin
    // shouldn't have to F5 to see the result. Once everything settles,
    // stop polling. Partial reload only refetches dynamic props.
    const hasProcessing = inflight.some((s) => s.status === 'processing');
    const hasPendingAnalysis = analyses.some((a) => a.status === 'pending');
    const shouldPoll = hasProcessing || hasPendingAnalysis;
    useEffect(() => {
        if (!shouldPoll) {
            return;
        }

        const timer = window.setInterval(() => {
            router.reload({
                only: [
                    'timeline',
                    'timeline_next_cursor',
                    'inflight',
                    'metrics',
                    'documents',
                    'analyses',
                    'analysis_eligibility',
                    'alerts',
                    'counts',
                ],
            });
        }, 10_000);

        return () => window.clearInterval(timer);
    }, [shouldPoll]);

    const archive = (): void => {
        router.post(`/players/${player.id}/archive`);
    };
    const restore = (): void => {
        router.post(`/players/${player.id}/restore`);
    };

    return (
        <>
            <Head title={player.full_name} />
            <UploadDrawer
                playerId={player.id}
                playerName={player.full_name}
                open={uploadOpen}
                onOpenChange={setUploadOpen}
            />
            <div className="flex flex-1 flex-col gap-4 p-4">
                <section className="flex flex-wrap items-start justify-between gap-4">
                    <div className="flex items-center gap-4">
                        <PlayerAvatar
                            name={player.full_name}
                            photoUrl={player.photo_url}
                            className="size-16"
                        />
                        <div>
                            <h1 className="text-2xl font-semibold tracking-tight">
                                {player.full_name}
                            </h1>
                            {player.name_ar && (
                                <p
                                    lang="ar"
                                    dir="rtl"
                                    className="text-lg text-muted-foreground"
                                >
                                    {player.name_ar}
                                </p>
                            )}
                            <div className="mt-1 flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                                <span>{player.club ?? 'No club'}</span>
                                {player.position && (
                                    <>
                                        <span>•</span>
                                        <span data-numeric>
                                            {player.position}
                                        </span>
                                    </>
                                )}
                                {player.age !== null && (
                                    <>
                                        <span>•</span>
                                        <span data-numeric>
                                            {player.age} yrs
                                        </span>
                                    </>
                                )}
                                <PlayerStatusBadge status={player.status} />
                            </div>
                        </div>
                    </div>

                    <div className="flex flex-wrap gap-2">
                        <Button size="sm" onClick={() => setUploadOpen(true)}>
                            <UploadCloud className="size-4" />
                            Upload document
                        </Button>
                        <Button variant="outline" size="sm" asChild>
                            <Link href={`/players/${player.id}/edit`}>
                                <Pencil className="size-4" />
                                Edit
                            </Link>
                        </Button>
                        {player.status === 'archived' ? (
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={restore}
                            >
                                <TimerReset className="size-4" />
                                Restore
                            </Button>
                        ) : (
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={archive}
                            >
                                <Archive className="size-4" />
                                Archive
                            </Button>
                        )}
                    </div>
                </section>

                <section className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <StatTile
                        label="Records"
                        value={counts.records.toString()}
                    />
                    <StatTile
                        label="Open alerts"
                        value={counts.open_alerts.toString()}
                        tone={counts.open_alerts > 0 ? 'warning' : 'default'}
                    />
                    <StatTile
                        label="Height"
                        value={
                            player.height_cm !== null
                                ? `${player.height_cm} cm`
                                : '—'
                        }
                    />
                    <StatTile
                        label="BMI"
                        value={
                            player.bmi !== null ? player.bmi.toFixed(1) : '—'
                        }
                    />
                </section>

                <section className="grid gap-4 lg:grid-cols-[240px_1fr]">
                    <Card>
                        <CardHeader className="pb-3">
                            <CardTitle className="text-sm font-medium text-muted-foreground">
                                Contact & identity
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3 text-sm">
                            <Detail
                                label="Player code"
                                value={player.player_code}
                                numeric
                            />
                            <Detail
                                label="Nationality"
                                value={player.nationality}
                            />
                            <Detail
                                label="Phone"
                                value={player.phone}
                                numeric
                            />
                            <Detail label="Email" value={player.email} />
                            <Detail
                                label="Date of birth"
                                value={player.date_of_birth}
                                numeric
                            />
                            <Detail
                                label="Weight"
                                value={
                                    player.weight_kg !== null
                                        ? `${player.weight_kg} kg`
                                        : null
                                }
                                numeric
                            />
                            <Detail
                                label="Preferred foot"
                                value={
                                    player.preferred_foot
                                        ? player.preferred_foot.toUpperCase()
                                        : null
                                }
                            />
                        </CardContent>
                    </Card>

                    <Card className="overflow-hidden">
                        <CardContent className="pt-6">
                            <Tabs defaultValue="timeline" className="gap-4">
                                <TabsList>
                                    <TabsTrigger value="timeline">
                                        <ListTodo className="size-4" />
                                        Timeline
                                    </TabsTrigger>
                                    <TabsTrigger value="metrics">
                                        <LineChart className="size-4" />
                                        Metrics
                                    </TabsTrigger>
                                    <TabsTrigger value="ai">
                                        <Sparkles className="size-4" />
                                        AI analysis
                                    </TabsTrigger>
                                    <TabsTrigger value="documents">
                                        <FileText className="size-4" />
                                        Documents
                                    </TabsTrigger>
                                    <TabsTrigger value="alerts">
                                        <AlertTriangle className="size-4" />
                                        Alerts
                                        {counts.open_alerts > 0 && (
                                            <span
                                                data-numeric
                                                className="ml-1.5 rounded bg-warning/15 px-1.5 text-xs font-medium text-warning tabular-nums"
                                            >
                                                {counts.open_alerts}
                                            </span>
                                        )}
                                    </TabsTrigger>
                                </TabsList>

                                <TabsContent value="timeline">
                                    <PlayerTimeline
                                        playerId={player.id}
                                        entries={timeline}
                                        inflight={inflight}
                                        initialNextCursor={timeline_next_cursor}
                                    />
                                </TabsContent>
                                <TabsContent value="metrics">
                                    <Suspense
                                        fallback={
                                            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                                                {Array.from({ length: 6 }).map(
                                                    (_, i) => (
                                                        <Skeleton
                                                            key={i}
                                                            className="h-44 w-full"
                                                        />
                                                    ),
                                                )}
                                            </div>
                                        }
                                    >
                                        <PlayerMetrics metrics={metrics} />
                                    </Suspense>
                                </TabsContent>
                                <TabsContent value="documents">
                                    <PlayerDocuments documents={documents} />
                                </TabsContent>
                                <TabsContent value="ai">
                                    <PlayerAiAnalysis
                                        playerId={player.id}
                                        analyses={analyses}
                                        eligibility={analysis_eligibility}
                                    />
                                </TabsContent>
                                <TabsContent value="alerts">
                                    {alerts.length === 0 ? (
                                        <Card>
                                            <CardContent className="flex flex-col items-center gap-3 py-12 text-center">
                                                <div className="grid size-10 place-items-center rounded-lg bg-success/15 text-success">
                                                    <AlertTriangle className="size-5" />
                                                </div>
                                                <h3 className="text-sm font-semibold">
                                                    No alerts on file
                                                </h3>
                                                <p className="max-w-sm text-sm text-muted-foreground">
                                                    The rule-based flagger and
                                                    Nutritionist Assistant
                                                    haven&apos;t raised anything
                                                    for this player.
                                                </p>
                                            </CardContent>
                                        </Card>
                                    ) : (
                                        <div className="space-y-3">
                                            {alerts.map((alert) => (
                                                <AlertCard
                                                    key={alert.id}
                                                    alert={alert}
                                                    showPlayer={false}
                                                />
                                            ))}
                                        </div>
                                    )}
                                </TabsContent>
                            </Tabs>
                        </CardContent>
                    </Card>
                </section>
            </div>
        </>
    );
}

function StatTile({
    label,
    value,
    tone = 'default',
}: {
    label: string;
    value: string;
    tone?: 'default' | 'warning';
}) {
    return (
        <Card>
            <CardContent className="pt-6">
                <div className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                    {label}
                </div>
                <div
                    data-numeric
                    className={
                        'mt-1 text-2xl leading-none font-semibold tabular-nums ' +
                        (tone === 'warning' ? 'text-warning' : '')
                    }
                >
                    {value}
                </div>
            </CardContent>
        </Card>
    );
}

function Detail({
    label,
    value,
    numeric,
}: {
    label: string;
    value: string | null;
    numeric?: boolean;
}) {
    return (
        <div className="flex items-center justify-between gap-4">
            <span className="text-xs text-muted-foreground">{label}</span>
            <span
                data-numeric={numeric || undefined}
                className="truncate text-right font-medium"
            >
                {value ?? '—'}
            </span>
        </div>
    );
}

PlayersShow.layout = ({ player }: { player: PlayerPayload }) => ({
    breadcrumbs: [
        { title: 'Dashboard', href: '/dashboard' },
        { title: 'Players', href: '/players' },
        { title: player.full_name, href: `/players/${player.id}` },
    ],
});
