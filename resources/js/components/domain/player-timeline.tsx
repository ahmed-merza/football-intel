import { router } from '@inertiajs/react';
import { format, formatDistanceToNow, parseISO } from 'date-fns';
import {
    Activity,
    Beaker,
    Droplets,
    FileText,
    Gauge,
    Goal,
    Loader2,
    MessageSquare,
    RefreshCw,
    Salad,
    Trash2,
    XCircle,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';

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
    extraction_partial?: boolean;
    chunks_completed?: number | null;
    chunks_total?: number | null;
    awaiting_callback?: boolean;
};

export type InflightSubmission = {
    id: number;
    received_at: string;
    status: 'processing' | 'failed';
    attachments: {
        filename: string;
        mime_type: string;
        stage: string;
    }[];
};

const CATEGORY_ICON: Record<string, LucideIcon> = {
    blood_test: Beaker,
    inbody: Gauge,
    gps_wearable: Activity,
    nutrition_plan: Salad,
    hydration_supplement_plan: Droplets,
    coach_feedback: MessageSquare,
    match_activity: Goal,
    other: FileText,
};

export type TimelineCursor = { date: string; id: number };

type PlayerTimelineProps = {
    playerId: number;
    entries: TimelineEntry[];
    inflight?: InflightSubmission[];
    initialNextCursor: TimelineCursor | null;
};

export function PlayerTimeline({
    playerId,
    entries,
    inflight = [],
    initialNextCursor,
}: PlayerTimelineProps) {
    // Local accumulator for pages fetched via infinite scroll. Server-
    // side rendered batch lives in `entries`; everything below it lives
    // in `loaded`. We keep them separate so a server prop refresh
    // (e.g. polling, re-extract) doesn't wipe pages the user already
    // scrolled into view.
    const [loaded, setLoaded] = useState<TimelineEntry[]>([]);
    const [cursor, setCursor] = useState<TimelineCursor | null>(
        initialNextCursor,
    );
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const sentinelRef = useRef<HTMLDivElement | null>(null);

    // Reset when the underlying server response changes (e.g. switching
    // to a different player's profile via Inertia SPA nav). The
    // set-state-in-effect rule doesn't have a derive-state alternative
    // here — playerId genuinely changes at runtime, we have to react.
    /* eslint-disable react-hooks/set-state-in-effect */
    useEffect(() => {
        setLoaded([]);
        setCursor(initialNextCursor);
        setError(null);
    }, [playerId, initialNextCursor]);
    /* eslint-enable react-hooks/set-state-in-effect */

    const all = useMemo(() => [...entries, ...loaded], [entries, loaded]);

    const fetchNext = useCallback(async (): Promise<void> => {
        if (cursor === null || loading) {
            return;
        }

        setLoading(true);
        setError(null);

        try {
            const params = new URLSearchParams({
                cursor: String(cursor.id),
                cursor_date: cursor.date,
            });
            const res = await fetch(
                `/players/${playerId}/timeline?${params.toString()}`,
                { headers: { Accept: 'application/json' } },
            );

            if (!res.ok) {
                throw new Error(`HTTP ${res.status}`);
            }

            const data = (await res.json()) as {
                entries: TimelineEntry[];
                next_cursor: TimelineCursor | null;
            };
            setLoaded((prev) => [...prev, ...data.entries]);
            setCursor(data.next_cursor);
        } catch (err) {
            setError(
                err instanceof Error
                    ? err.message
                    : 'Failed to load more records.',
            );
        } finally {
            setLoading(false);
        }
    }, [cursor, loading, playerId]);

    // IntersectionObserver — when the sentinel scrolls into view,
    // pull the next page. Disconnects when there's nothing more.
    useEffect(() => {
        if (cursor === null) {
            return;
        }

        const node = sentinelRef.current;

        if (node === null) {
            return;
        }

        const observer = new IntersectionObserver(
            (entries) => {
                if (entries[0]?.isIntersecting) {
                    void fetchNext();
                }
            },
            { rootMargin: '200px' },
        );
        observer.observe(node);

        return () => observer.disconnect();
    }, [cursor, fetchNext]);

    // Group by "YYYY-MM" so each month becomes its own section.
    const grouped = useMemo(() => {
        const map = new Map<string, TimelineEntry[]>();

        for (const entry of all) {
            const key = entry.record_date.slice(0, 7);
            const bucket = map.get(key);

            if (bucket) {
                bucket.push(entry);
            } else {
                map.set(key, [entry]);
            }
        }

        return [...map.entries()];
    }, [all]);

    const hasInflight = inflight.length > 0;

    if (all.length === 0 && !hasInflight) {
        return (
            <Card>
                <CardContent className="flex flex-col items-start gap-3 py-8">
                    <div className="grid size-9 place-items-center rounded-md bg-muted text-muted-foreground">
                        <FileText className="size-4" />
                    </div>
                    <h3 className="text-sm font-semibold">No records yet</h3>
                    <p className="max-w-prose text-sm text-muted-foreground">
                        Upload a blood test, body-composition report, GPS
                        session, or nutrition plan to seed the timeline.
                    </p>
                </CardContent>
            </Card>
        );
    }

    return (
        <div className="space-y-6">
            {hasInflight && (
                <section className="space-y-2">
                    <h3 className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                        Just uploaded
                    </h3>
                    <ul className="relative ml-1 space-y-3 border-l border-primary/40 pl-6">
                        {inflight.map((sub) => (
                            <InflightRow key={sub.id} submission={sub} />
                        ))}
                    </ul>
                </section>
            )}

            {grouped.map(([monthKey, items]) => (
                <section key={monthKey} className="space-y-2">
                    <h3
                        data-numeric
                        className="text-xs font-semibold tracking-wide text-muted-foreground uppercase tabular-nums"
                    >
                        {format(parseISO(`${monthKey}-01`), 'MMMM yyyy')}
                    </h3>
                    <ul className="relative ml-1 space-y-3 border-l border-border pl-6">
                        {items.map((entry) => (
                            <TimelineRow key={entry.id} entry={entry} />
                        ))}
                    </ul>
                </section>
            ))}

            {/* Infinite-scroll sentinel + status. The IntersectionObserver
                trips when this comes into view + cursor !== null. */}
            {cursor !== null && (
                <div
                    ref={sentinelRef}
                    className="flex items-center justify-center py-6 text-sm text-muted-foreground"
                >
                    {error !== null ? (
                        <button
                            type="button"
                            onClick={() => void fetchNext()}
                            className="rounded-md border px-3 py-1.5 text-xs hover:bg-muted"
                        >
                            Retry — {error}
                        </button>
                    ) : (
                        <span className="inline-flex items-center gap-2">
                            <Loader2 className="size-3.5 animate-spin" />
                            {loading ? 'Loading more…' : 'Scroll for more'}
                        </span>
                    )}
                </div>
            )}
        </div>
    );
}

function InflightRow({ submission }: { submission: InflightSubmission }) {
    const isFailed = submission.status === 'failed';
    const [retrying, setRetrying] = useState(false);
    const [discarding, setDiscarding] = useState(false);

    const retry = (): void => {
        setRetrying(true);
        router.post(
            `/submissions/${submission.id}/retry`,
            {},
            {
                preserveScroll: true,
                onFinish: () => setRetrying(false),
            },
        );
    };

    const discard = (): void => {
        const confirmed = window.confirm(
            'Discard this failed upload and its files permanently?',
        );

        if (!confirmed) {
            return;
        }

        setDiscarding(true);
        router.delete(`/submissions/${submission.id}`, {
            preserveScroll: true,
            onFinish: () => setDiscarding(false),
        });
    };

    const Icon = isFailed ? XCircle : Loader2;
    const iconClass = isFailed
        ? 'size-3.5 text-destructive'
        : 'size-3.5 animate-spin text-primary';
    const badgeClass = isFailed
        ? 'bg-destructive/15 text-destructive border-destructive/30'
        : 'bg-primary/10 text-primary border-primary/30';

    return (
        <li className="relative">
            <span className="absolute top-0.5 -left-[35px] grid size-6 place-items-center rounded-full border bg-card">
                <Icon className={iconClass} />
            </span>

            <Card>
                <CardContent className="py-3">
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <div className="min-w-0 flex-1 space-y-1.5">
                            <div className="flex flex-wrap items-center gap-2">
                                <Badge variant="outline" className={badgeClass}>
                                    {isFailed ? 'Failed' : 'Processing'}
                                </Badge>
                                <span className="text-xs text-muted-foreground">
                                    {formatDistanceToNow(
                                        parseISO(submission.received_at),
                                        { addSuffix: true },
                                    )}
                                </span>
                            </div>
                            <ul className="space-y-1">
                                {submission.attachments.map((att, i) => (
                                    <li
                                        key={i}
                                        className="flex items-center gap-2 text-sm"
                                    >
                                        <FileText className="size-3.5 shrink-0 text-muted-foreground" />
                                        <span className="truncate">
                                            {att.filename}
                                        </span>
                                        {!isFailed && (
                                            <span className="text-xs text-muted-foreground">
                                                · {att.stage}
                                            </span>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        </div>
                        {isFailed && (
                            <div className="flex gap-2">
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={retry}
                                    disabled={retrying || discarding}
                                >
                                    <RefreshCw
                                        className={
                                            'size-4 ' +
                                            (retrying ? 'animate-spin' : '')
                                        }
                                    />
                                    {retrying ? 'Retrying…' : 'Retry'}
                                </Button>
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    onClick={discard}
                                    disabled={retrying || discarding}
                                    className="text-destructive hover:bg-destructive/10 hover:text-destructive"
                                >
                                    <Trash2 className="size-4" />
                                    {discarding ? 'Discarding…' : 'Discard'}
                                </Button>
                            </div>
                        )}
                    </div>
                </CardContent>
            </Card>
        </li>
    );
}

function TimelineRow({ entry }: { entry: TimelineEntry }) {
    const Icon = CATEGORY_ICON[entry.category.slug] ?? FileText;
    const [reExtracting, setReExtracting] = useState(false);

    const reExtract = (): void => {
        setReExtracting(true);
        router.post(
            `/records/${entry.id}/re-extract`,
            {},
            {
                preserveScroll: true,
                onFinish: () => setReExtracting(false),
            },
        );
    };

    // Failed = explicit retry. Pending = either fresh-and-queued OR
    // legacy/stuck (e.g. records classified before the structured-
    // extractor existed). Partial = some chunks succeeded, some 504'd
    // — fill the gap. Awaiting-callback = sync 504'd, n8n is still
    // working, callback will close the loop — no retry button (would
    // double-spend tokens). The other three offer the re-extract
    // button with labels that make the intent obvious.
    const isAwaiting = entry.awaiting_callback === true;
    const showReExtract =
        !isAwaiting &&
        (entry.extraction_failed ||
            entry.extraction_pending ||
            entry.extraction_partial === true);
    const reExtractLabel = entry.extraction_failed
        ? 'Re-extract'
        : entry.extraction_partial
          ? 'Fill gap'
          : 'Run extraction';

    return (
        <li className="relative">
            <span className="absolute top-0.5 -left-[35px] grid size-6 place-items-center rounded-full border bg-card">
                <Icon className="size-3.5 text-muted-foreground" />
            </span>

            <Card>
                <CardContent className="py-3">
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <div className="min-w-0 flex-1 space-y-1.5">
                            <div className="flex flex-wrap items-center gap-2">
                                <Badge variant="outline" className="capitalize">
                                    {entry.category.label}
                                </Badge>
                                <span
                                    data-numeric
                                    className="text-xs text-muted-foreground tabular-nums"
                                >
                                    {entry.record_date}
                                </span>
                                {entry.source_lab && (
                                    <span className="text-xs text-muted-foreground">
                                        · {entry.source_lab}
                                    </span>
                                )}
                                {/* "Unreviewed" is no longer surfaced on
                                    Timeline — review sign-off lives on the
                                    Documents tab where the admin actually
                                    inspects the file + extracted data. */}
                                {entry.extraction_failed && (
                                    <span
                                        className="inline-flex items-center gap-1 text-xs text-destructive"
                                        title={
                                            entry.extraction_failure_reason ??
                                            undefined
                                        }
                                    >
                                        <XCircle className="size-3" />
                                        extraction failed
                                    </span>
                                )}
                                {!entry.extraction_failed &&
                                    entry.extraction_partial && (
                                        <span
                                            data-numeric
                                            className="inline-flex items-center gap-1 text-xs text-warning tabular-nums"
                                            title={
                                                entry.extraction_failure_reason ??
                                                undefined
                                            }
                                        >
                                            <XCircle className="size-3" />
                                            partial · {entry.chunks_completed}/
                                            {entry.chunks_total} sections
                                        </span>
                                    )}
                                {isAwaiting && (
                                    <span
                                        className="inline-flex items-center gap-1 text-xs text-primary"
                                        title="Sync call timed out at the proxy; n8n will deliver the result via callback."
                                    >
                                        <Loader2 className="size-3 animate-spin" />
                                        awaiting callback
                                    </span>
                                )}
                                {entry.extraction_pending &&
                                    !entry.extraction_failed &&
                                    !entry.extraction_partial &&
                                    !isAwaiting && (
                                        <span className="inline-flex items-center gap-1 text-xs text-muted-foreground">
                                            <Loader2 className="size-3" />
                                            extraction pending
                                        </span>
                                    )}
                            </div>
                            {entry.summary_text && (
                                <p className="text-sm text-muted-foreground">
                                    {entry.summary_text}
                                </p>
                            )}
                            {entry.attachment_filename && (
                                <p className="inline-flex items-center gap-1 text-xs text-muted-foreground">
                                    <FileText className="size-3" />
                                    {entry.attachment_filename}
                                </p>
                            )}
                        </div>
                        {showReExtract && (
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={reExtract}
                                disabled={reExtracting}
                            >
                                <RefreshCw
                                    className={
                                        'size-4 ' +
                                        (reExtracting ? 'animate-spin' : '')
                                    }
                                />
                                {reExtracting ? 'Queuing…' : reExtractLabel}
                            </Button>
                        )}
                    </div>
                </CardContent>
            </Card>
        </li>
    );
}
