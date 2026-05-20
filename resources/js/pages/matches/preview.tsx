import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowLeft,
    CheckCircle2,
    Loader2,
    RefreshCw,
    UserPlus,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import type { FormEvent } from 'react';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectLabel,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { cn } from '@/lib/utils';

type MatchSummary = {
    id: number;
    status:
        | 'pending'
        | 'extracting'
        | 'awaiting_callback'
        | 'extracted'
        | 'applied'
        | 'failed';
    competition: string | null;
    stage: string | null;
    match_date: string | null;
    venue: string | null;
    home_team_name: string | null;
    away_team_name: string | null;
    home_score: number | null;
    away_score: number | null;
    bahrain_side: 'home' | 'away' | null;
    opponent_name: string | null;
    extraction_error: string | null;
    extracted_at: string | null;
};

type Suggestion = {
    player_id: number;
    player_name: string;
    confidence: 'high' | 'medium' | 'low';
    method: 'jersey_history' | 'name_fuzzy';
    score: number;
    auto_apply: boolean;
};

type Performance = {
    index: number;
    team_side: 'bahrain' | 'opponent';
    reported_name: string | null;
    jersey_number: number | null;
    match_position: string | null;
    appearance: 'starter' | 'sub' | 'unused';
    minutes_played: number | null;
    rating: number | null;
    goals: number;
    assists: number;
    suggestions: Suggestion[];
};

type ActivePlayer = {
    id: number;
    full_name: string;
    position: string | null;
    club: string | null;
};

type Resolution =
    | { type: 'existing'; player_id: number }
    | { type: 'new'; data: NewPlayerDraft }
    | { type: 'skip' };

type NewPlayerDraft = {
    full_name: string;
    name_ar?: string;
    position?: string;
    nationality?: string;
};

type PageProps = {
    match: MatchSummary;
    performances: Performance[];
    active_players: ActivePlayer[];
};

export default function MatchesPreview({
    match,
    performances,
    active_players,
}: PageProps) {
    return (
        <>
            <Head title={pageTitle(match)} />
            <div className="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-4 p-4">
                <PreviewHeader match={match} />
                <PreviewBody
                    match={match}
                    performances={performances}
                    activePlayers={active_players}
                />
            </div>
        </>
    );
}

function PreviewHeader({ match }: { match: MatchSummary }) {
    return (
        <header className="flex flex-col gap-2">
            <div className="flex items-start justify-between gap-3">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        {fixtureLabel(match)}
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        {[match.competition, match.stage, match.match_date]
                            .filter(Boolean)
                            .join(' • ') || 'Awaiting extraction…'}
                    </p>
                </div>
                <Button asChild variant="ghost" size="sm">
                    <Link href="/matches">
                        <ArrowLeft className="size-4" />
                        Back
                    </Link>
                </Button>
            </div>
        </header>
    );
}

function PreviewBody({
    match,
    performances,
    activePlayers,
}: {
    match: MatchSummary;
    performances: Performance[];
    activePlayers: ActivePlayer[];
}) {
    if (
        match.status === 'pending' ||
        match.status === 'extracting' ||
        match.status === 'awaiting_callback'
    ) {
        return <ProcessingPanel match={match} />;
    }

    if (match.status === 'failed') {
        return <FailedPanel match={match} />;
    }

    if (match.status === 'extracted') {
        return (
            <ResolutionForm
                match={match}
                performances={performances}
                activePlayers={activePlayers}
            />
        );
    }

    // Applied → controller redirects; this branch is just defensive.
    return (
        <Alert>
            <CheckCircle2 className="size-4" />
            <AlertTitle>This match is applied</AlertTitle>
            <AlertDescription>
                <Link
                    href={`/matches/${match.id}`}
                    className="underline underline-offset-2"
                >
                    Open the match view
                </Link>
            </AlertDescription>
        </Alert>
    );
}

function ProcessingPanel({ match }: { match: MatchSummary }) {
    // Light auto-poll: the extraction is async (and may go through the n8n
    // callback path on a sync timeout), so hot-reload the page every 5s
    // until status moves out of an in-flight state.
    useEffect(() => {
        const tick = setInterval(() => {
            router.reload({ only: ['match', 'performances', 'active_players'] });
        }, 5000);

        return () => clearInterval(tick);
    }, []);

    const label =
        match.status === 'awaiting_callback'
            ? 'Awaiting callback from extraction service'
            : 'Extracting the match report';

    return (
        <Card>
            <CardContent className="flex flex-col items-center gap-3 py-12">
                <Loader2 className="size-10 animate-spin text-primary" />
                <div className="text-sm font-medium">{label}</div>
                <p className="max-w-md text-center text-xs text-muted-foreground">
                    {match.status === 'awaiting_callback'
                        ? 'The sync HTTP call timed out, but the extractor is still working — the result will land here shortly. This page auto-refreshes.'
                        : "We're parsing the PDF and turning every player's stat line into structured data. This usually takes 30–90 seconds."}
                </p>
            </CardContent>
        </Card>
    );
}

function FailedPanel({ match }: { match: MatchSummary }) {
    const [submitting, setSubmitting] = useState(false);
    const retry = (): void => {
        setSubmitting(true);
        router.post(
            `/matches/${match.id}/retry`,
            {},
            {
                onFinish: () => setSubmitting(false),
            },
        );
    };

    return (
        <Alert variant="destructive">
            <AlertTriangle className="size-4" />
            <AlertTitle>Extraction failed</AlertTitle>
            <AlertDescription className="flex flex-col gap-3">
                <p>
                    {match.extraction_error ??
                        'The match-report extractor returned an error. Retry to send it through the pipeline again.'}
                </p>
                <div>
                    <Button onClick={retry} disabled={submitting} size="sm">
                        <RefreshCw className="size-4" />
                        {submitting ? 'Retrying…' : 'Retry extraction'}
                    </Button>
                </div>
            </AlertDescription>
        </Alert>
    );
}

function ResolutionForm({
    match,
    performances,
    activePlayers,
}: {
    match: MatchSummary;
    performances: Performance[];
    activePlayers: ActivePlayer[];
}) {
    // Seed each Bahrain performance with the auto-apply suggestion if one
    // exists; otherwise leave unresolved (admin must pick).
    const [resolutions, setResolutions] = useState<Record<number, Resolution>>(
        () => initialResolutions(performances),
    );

    const bahrainRows = performances.filter((p) => p.team_side === 'bahrain');
    const opponentRows = performances.filter((p) => p.team_side === 'opponent');

    const stats = useMemo(() => {
        let resolved = 0;
        let skipped = 0;
        let creating = 0;
        let unresolved = 0;
        for (const row of bahrainRows) {
            const r = resolutions[row.index];
            if (!r) {
                unresolved++;
            } else if (r.type === 'existing') {
                resolved++;
            } else if (r.type === 'skip') {
                skipped++;
            } else if (r.type === 'new') {
                if (r.data.full_name.trim() !== '') {
                    creating++;
                } else {
                    unresolved++;
                }
            }
        }

        return { resolved, skipped, creating, unresolved };
    }, [resolutions, bahrainRows]);

    const form = useForm<{ resolutions: Record<number, Resolution> }>({
        resolutions: {},
    });

    const submit = (e: FormEvent<HTMLFormElement>): void => {
        e.preventDefault();

        // Re-pull current state into the Inertia payload at submit time so
        // the latest resolution choices ship (Inertia's useForm snapshots
        // on init, not on submit, so we need transform()).
        form.transform(() => ({ resolutions: resolutions }));
        form.post(`/matches/${match.id}/apply`);
    };

    const canSubmit = stats.unresolved === 0 && !form.processing;

    return (
        <form onSubmit={submit} className="flex flex-col gap-4">
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <StatCard label="Bahrain rows" value={bahrainRows.length} />
                <StatCard
                    label="Auto-matched"
                    value={stats.resolved}
                    tone="success"
                />
                <StatCard
                    label="New players"
                    value={stats.creating}
                    tone={stats.creating > 0 ? 'info' : 'neutral'}
                />
                <StatCard
                    label="Skipped"
                    value={stats.skipped}
                    tone={stats.skipped > 0 ? 'info' : 'neutral'}
                />
            </div>

            {stats.unresolved > 0 && (
                <Alert className="border-amber-300 bg-amber-50 text-amber-900 dark:border-amber-900/50 dark:bg-amber-950/30 dark:text-amber-100 [&>svg]:text-amber-700 dark:[&>svg]:text-amber-300">
                    <AlertTriangle className="size-4" />
                    <AlertTitle>
                        {stats.unresolved}{' '}
                        {stats.unresolved === 1
                            ? 'Bahrain row needs'
                            : 'Bahrain rows need'}{' '}
                        a decision
                    </AlertTitle>
                    <AlertDescription>
                        Pick the matching player, create a new one inline, or
                        skip the row entirely.
                    </AlertDescription>
                </Alert>
            )}

            <BahrainRowsCard
                rows={bahrainRows}
                resolutions={resolutions}
                setResolutions={setResolutions}
                activePlayers={activePlayers}
            />

            {opponentRows.length > 0 && (
                <OpponentRowsCard
                    rows={opponentRows}
                    opponentName={match.opponent_name}
                />
            )}

            <div className="flex items-center justify-between gap-2">
                <div className="text-sm text-muted-foreground">
                    {stats.unresolved === 0
                        ? `Ready to apply ${stats.resolved + stats.creating} Bahrain ${
                              stats.resolved + stats.creating === 1
                                  ? 'row'
                                  : 'rows'
                          }${stats.skipped > 0 ? ` (${stats.skipped} skipped)` : ''}.`
                        : 'Resolve the remaining rows above to enable apply.'}
                </div>
                <div className="flex gap-2">
                    <Button asChild type="button" variant="outline">
                        <Link href="/matches">Cancel</Link>
                    </Button>
                    <Button type="submit" disabled={!canSubmit}>
                        {form.processing ? 'Applying…' : 'Save & apply'}
                    </Button>
                </div>
            </div>
        </form>
    );
}

function BahrainRowsCard({
    rows,
    resolutions,
    setResolutions,
    activePlayers,
}: {
    rows: Performance[];
    resolutions: Record<number, Resolution>;
    setResolutions: (
        next: (prev: Record<number, Resolution>) => Record<number, Resolution>,
    ) => void;
    activePlayers: ActivePlayer[];
}) {
    return (
        <Card className="overflow-hidden p-0">
            <CardHeader className="border-b py-3">
                <CardTitle className="text-sm">
                    Bahrain players — resolve each row
                </CardTitle>
            </CardHeader>
            <CardContent className="p-0">
                <div className="overflow-x-auto">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead className="w-14">#</TableHead>
                                <TableHead>Reported name</TableHead>
                                <TableHead className="w-20">Pos</TableHead>
                                <TableHead className="w-20">Mins</TableHead>
                                <TableHead className="w-20">Rating</TableHead>
                                <TableHead className="min-w-[260px]">
                                    Resolution
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {rows.map((row) => (
                                <BahrainRow
                                    key={row.index}
                                    row={row}
                                    resolution={resolutions[row.index]}
                                    activePlayers={activePlayers}
                                    setResolution={(r) =>
                                        setResolutions((prev) => ({
                                            ...prev,
                                            [row.index]: r,
                                        }))
                                    }
                                    clearResolution={() =>
                                        setResolutions((prev) => {
                                            const next = { ...prev };
                                            delete next[row.index];
                                            return next;
                                        })
                                    }
                                />
                            ))}
                        </TableBody>
                    </Table>
                </div>
            </CardContent>
        </Card>
    );
}

function BahrainRow({
    row,
    resolution,
    activePlayers,
    setResolution,
    clearResolution,
}: {
    row: Performance;
    resolution: Resolution | undefined;
    activePlayers: ActivePlayer[];
    setResolution: (r: Resolution) => void;
    clearResolution: () => void;
}) {
    const isAnonymised = (row.reported_name ?? '').match(/^player\s*\d*$/i);

    const handleSelect = (value: string): void => {
        if (value === '__new') {
            setResolution({
                type: 'new',
                data: {
                    full_name: row.reported_name ?? '',
                    position: positionFamily(row.match_position) ?? undefined,
                },
            });
            return;
        }
        if (value === '__skip') {
            setResolution({ type: 'skip' });
            return;
        }
        if (value === '__clear') {
            clearResolution();
            return;
        }
        const playerId = Number(value);
        if (Number.isFinite(playerId)) {
            setResolution({ type: 'existing', player_id: playerId });
        }
    };

    const currentValue: string =
        resolution?.type === 'existing'
            ? String(resolution.player_id)
            : resolution?.type === 'new'
              ? '__new'
              : resolution?.type === 'skip'
                ? '__skip'
                : '';

    return (
        <TableRow
            className={cn(
                isAnonymised && !resolution && 'bg-amber-50/50 dark:bg-amber-950/20',
            )}
        >
            <TableCell data-numeric className="text-xs text-muted-foreground">
                {row.jersey_number ?? '—'}
            </TableCell>
            <TableCell>
                <div className="font-medium">{row.reported_name ?? '—'}</div>
                {isAnonymised && (
                    <div className="text-[11px] text-amber-700 dark:text-amber-400">
                        Anonymised — pick a player or skip
                    </div>
                )}
            </TableCell>
            <TableCell className="text-xs text-muted-foreground">
                {row.match_position ?? '—'}
            </TableCell>
            <TableCell data-numeric className="text-xs text-muted-foreground">
                {row.minutes_played !== null
                    ? `${row.minutes_played}'`
                    : row.appearance === 'unused'
                      ? '—'
                      : '—'}
            </TableCell>
            <TableCell
                data-numeric
                className="text-xs font-medium tabular-nums"
            >
                {row.rating !== null ? row.rating.toFixed(1) : '—'}
            </TableCell>
            <TableCell>
                <div className="flex flex-col gap-2">
                    <Select value={currentValue} onValueChange={handleSelect}>
                        <SelectTrigger>
                            <SelectValue placeholder="Choose…" />
                        </SelectTrigger>
                        <SelectContent>
                            {row.suggestions.length > 0 && (
                                <SelectGroup>
                                    <SelectLabel>Suggested</SelectLabel>
                                    {row.suggestions.map((s) => (
                                        <SelectItem
                                            key={s.player_id}
                                            value={String(s.player_id)}
                                        >
                                            <span className="flex items-center gap-2">
                                                {s.player_name}
                                                <Badge
                                                    variant="outline"
                                                    className={cn(
                                                        'text-[10px]',
                                                        s.confidence === 'high' &&
                                                            'border-emerald-300 bg-emerald-50 text-emerald-700 dark:border-emerald-800 dark:bg-emerald-950/30 dark:text-emerald-300',
                                                    )}
                                                >
                                                    {suggestionLabel(s)}
                                                </Badge>
                                            </span>
                                        </SelectItem>
                                    ))}
                                </SelectGroup>
                            )}
                            <SelectGroup>
                                <SelectLabel>All players</SelectLabel>
                                {activePlayers
                                    .filter(
                                        (p) =>
                                            !row.suggestions.some(
                                                (s) => s.player_id === p.id,
                                            ),
                                    )
                                    .map((p) => (
                                        <SelectItem
                                            key={p.id}
                                            value={String(p.id)}
                                        >
                                            {p.full_name}
                                            {p.position && (
                                                <span className="ml-1 text-muted-foreground">
                                                    ({p.position})
                                                </span>
                                            )}
                                        </SelectItem>
                                    ))}
                            </SelectGroup>
                            <SelectGroup>
                                <SelectLabel>Actions</SelectLabel>
                                <SelectItem value="__new">
                                    <span className="flex items-center gap-2">
                                        <UserPlus className="size-3.5" />
                                        Create new player from this row
                                    </span>
                                </SelectItem>
                                <SelectItem value="__skip">
                                    Skip this row
                                </SelectItem>
                                {resolution && (
                                    <SelectItem value="__clear">
                                        Clear selection
                                    </SelectItem>
                                )}
                            </SelectGroup>
                        </SelectContent>
                    </Select>

                    {resolution?.type === 'new' && (
                        <InlineNewPlayerForm
                            draft={resolution.data}
                            setDraft={(draft) =>
                                setResolution({ type: 'new', data: draft })
                            }
                        />
                    )}
                </div>
            </TableCell>
        </TableRow>
    );
}

function InlineNewPlayerForm({
    draft,
    setDraft,
}: {
    draft: NewPlayerDraft;
    setDraft: (next: NewPlayerDraft) => void;
}) {
    return (
        <div className="grid grid-cols-1 gap-2 rounded-md border bg-muted/30 p-3 sm:grid-cols-2">
            <Field label="Full name">
                <Input
                    value={draft.full_name}
                    onChange={(e) =>
                        setDraft({ ...draft, full_name: e.target.value })
                    }
                    placeholder="Required"
                />
            </Field>
            <Field label="Arabic name">
                <Input
                    value={draft.name_ar ?? ''}
                    onChange={(e) =>
                        setDraft({ ...draft, name_ar: e.target.value })
                    }
                    dir="rtl"
                    lang="ar"
                />
            </Field>
            <Field label="Position">
                <Input
                    value={draft.position ?? ''}
                    onChange={(e) =>
                        setDraft({ ...draft, position: e.target.value })
                    }
                    placeholder="GK / DF / MF / FW"
                />
            </Field>
            <Field label="Nationality">
                <Input
                    value={draft.nationality ?? ''}
                    onChange={(e) =>
                        setDraft({ ...draft, nationality: e.target.value })
                    }
                    placeholder="Bahraini"
                />
            </Field>
        </div>
    );
}

function Field({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <div className="flex flex-col gap-1">
            <Label className="text-[11px] text-muted-foreground">{label}</Label>
            {children}
        </div>
    );
}

function OpponentRowsCard({
    rows,
    opponentName,
}: {
    rows: Performance[];
    opponentName: string | null;
}) {
    return (
        <Card className="overflow-hidden p-0">
            <CardHeader className="border-b py-3">
                <CardTitle className="text-sm">
                    {opponentName ?? 'Opponent'} — stored for context (not
                    resolved)
                </CardTitle>
            </CardHeader>
            <CardContent className="p-0">
                <div className="overflow-x-auto">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead className="w-14">#</TableHead>
                                <TableHead>Name</TableHead>
                                <TableHead className="w-20">Pos</TableHead>
                                <TableHead className="w-20">Mins</TableHead>
                                <TableHead className="w-20">Rating</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {rows.map((row) => (
                                <TableRow key={row.index}>
                                    <TableCell
                                        data-numeric
                                        className="text-xs text-muted-foreground"
                                    >
                                        {row.jersey_number ?? '—'}
                                    </TableCell>
                                    <TableCell className="text-sm">
                                        {row.reported_name ?? '—'}
                                    </TableCell>
                                    <TableCell className="text-xs text-muted-foreground">
                                        {row.match_position ?? '—'}
                                    </TableCell>
                                    <TableCell
                                        data-numeric
                                        className="text-xs text-muted-foreground"
                                    >
                                        {row.minutes_played !== null
                                            ? `${row.minutes_played}'`
                                            : '—'}
                                    </TableCell>
                                    <TableCell
                                        data-numeric
                                        className="text-xs tabular-nums"
                                    >
                                        {row.rating !== null
                                            ? row.rating.toFixed(1)
                                            : '—'}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
            </CardContent>
        </Card>
    );
}

function StatCard({
    label,
    value,
    tone = 'neutral',
}: {
    label: string;
    value: number;
    tone?: 'neutral' | 'success' | 'info' | 'warn';
}) {
    return (
        <Card className="p-4">
            <div className="text-xs text-muted-foreground">{label}</div>
            <div
                data-numeric
                className={cn(
                    'mt-1 text-2xl font-semibold tabular-nums',
                    tone === 'success' && 'text-emerald-600',
                    tone === 'info' && 'text-amber-600 dark:text-amber-400',
                    tone === 'warn' && 'text-destructive',
                )}
            >
                {value}
            </div>
        </Card>
    );
}

function initialResolutions(
    performances: Performance[],
): Record<number, Resolution> {
    const out: Record<number, Resolution> = {};
    for (const row of performances) {
        if (row.team_side !== 'bahrain') {
            continue;
        }
        const autoPick = row.suggestions.find((s) => s.auto_apply);
        if (autoPick) {
            out[row.index] = {
                type: 'existing',
                player_id: autoPick.player_id,
            };
        }
    }

    return out;
}

function fixtureLabel(m: MatchSummary): string {
    const home = m.home_team_name ?? '—';
    const away = m.away_team_name ?? '—';
    if (m.home_score !== null && m.away_score !== null) {
        return `${home} ${m.home_score} – ${m.away_score} ${away}`;
    }

    return `${home} vs ${away}`;
}

function pageTitle(m: MatchSummary): string {
    if (m.home_team_name && m.away_team_name) {
        return `${m.home_team_name} vs ${m.away_team_name}`;
    }
    return 'Match report';
}

function suggestionLabel(s: Suggestion): string {
    if (s.method === 'jersey_history') {
        return 'prior match';
    }

    return `${Math.round(s.score)}% name match`;
}

function positionFamily(matchPosition: string | null): string | null {
    if (!matchPosition) {
        return null;
    }
    const p = matchPosition.toUpperCase();
    if (p === 'GK') {
        return 'GK';
    }
    if (['CB', 'LB', 'RB', 'LWB', 'RWB', 'DF'].includes(p)) {
        return 'DF';
    }
    if (['CM', 'CDM', 'CAM', 'DM', 'AM', 'MF'].includes(p)) {
        return 'MF';
    }
    if (['CF', 'ST', 'LW', 'RW', 'LF', 'RF', 'FW'].includes(p)) {
        return 'FW';
    }

    return null;
}

MatchesPreview.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: '/dashboard' },
        { title: 'Matches', href: '/matches' },
        { title: 'Preview', href: '#' },
    ],
};
