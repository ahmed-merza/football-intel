import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, ExternalLink, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
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
    status: string;
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
    applied_at: string | null;
};

type Performance = {
    id: number;
    team_side: 'bahrain' | 'opponent';
    reported_name: string | null;
    jersey_number: number | null;
    match_position: string | null;
    appearance: string;
    minutes_played: number | null;
    rating: number | null;
    goals: number;
    assists: number;
    pass_accuracy_pct: number | null;
    player: { id: number; full_name: string } | null;
};

type PageProps = {
    match: MatchSummary;
    performances: Performance[];
};

export default function MatchesShow({ match, performances }: PageProps) {
    const [deleting, setDeleting] = useState(false);
    const bahrainRows = performances.filter((p) => p.team_side === 'bahrain');
    const opponentRows = performances.filter((p) => p.team_side === 'opponent');

    const destroy = (): void => {
        if (
            !window.confirm(
                'Remove this match report? The applied per-player records on the timeline will stay.',
            )
        ) {
            return;
        }
        setDeleting(true);
        router.delete(`/matches/${match.id}`, {
            onFinish: () => setDeleting(false),
        });
    };

    return (
        <>
            <Head title={pageTitle(match)} />
            <div className="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-4 p-4">
                <header className="flex items-start justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            {fixtureLabel(match)}
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {[match.competition, match.stage, match.match_date]
                                .filter(Boolean)
                                .join(' • ')}
                        </p>
                    </div>
                    <div className="flex gap-2">
                        <Button asChild variant="ghost" size="sm">
                            <Link href="/matches">
                                <ArrowLeft className="size-4" />
                                Back
                            </Link>
                        </Button>
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={destroy}
                            disabled={deleting}
                        >
                            <Trash2 className="size-4" />
                            {deleting ? 'Removing…' : 'Remove'}
                        </Button>
                    </div>
                </header>

                <PerformancesCard
                    title="Bahrain"
                    rows={bahrainRows}
                    showPlayerLink
                />
                {opponentRows.length > 0 && (
                    <PerformancesCard
                        title={match.opponent_name ?? 'Opponent'}
                        rows={opponentRows}
                        showPlayerLink={false}
                    />
                )}
            </div>
        </>
    );
}

function PerformancesCard({
    title,
    rows,
    showPlayerLink,
}: {
    title: string;
    rows: Performance[];
    showPlayerLink: boolean;
}) {
    return (
        <Card className="overflow-hidden p-0">
            <CardHeader className="border-b py-3">
                <CardTitle className="text-sm">{title}</CardTitle>
            </CardHeader>
            <CardContent className="p-0">
                <div className="overflow-x-auto">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead className="w-14">#</TableHead>
                                <TableHead>Player</TableHead>
                                <TableHead className="w-20">Pos</TableHead>
                                <TableHead className="w-20">Mins</TableHead>
                                <TableHead className="w-20">Rating</TableHead>
                                <TableHead className="w-16">G</TableHead>
                                <TableHead className="w-16">A</TableHead>
                                <TableHead className="w-24">Pass %</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {rows.map((row) => (
                                <TableRow key={row.id}>
                                    <TableCell
                                        data-numeric
                                        className="text-xs text-muted-foreground"
                                    >
                                        {row.jersey_number ?? '—'}
                                    </TableCell>
                                    <TableCell>
                                        {showPlayerLink && row.player ? (
                                            <Link
                                                href={`/players/${row.player.id}`}
                                                className="flex items-center gap-1 underline-offset-2 hover:underline"
                                            >
                                                {row.player.full_name}
                                                <ExternalLink className="size-3 text-muted-foreground" />
                                            </Link>
                                        ) : (
                                            <span
                                                className={cn(
                                                    !row.player &&
                                                        'text-muted-foreground',
                                                )}
                                            >
                                                {row.reported_name ?? '—'}
                                                {!row.player &&
                                                    showPlayerLink && (
                                                        <Badge
                                                            variant="outline"
                                                            className="ml-2 text-[10px]"
                                                        >
                                                            unresolved
                                                        </Badge>
                                                    )}
                                            </span>
                                        )}
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
                                        className="text-xs font-medium tabular-nums"
                                    >
                                        {row.rating !== null
                                            ? row.rating.toFixed(1)
                                            : '—'}
                                    </TableCell>
                                    <TableCell
                                        data-numeric
                                        className="text-xs tabular-nums"
                                    >
                                        {row.goals}
                                    </TableCell>
                                    <TableCell
                                        data-numeric
                                        className="text-xs tabular-nums"
                                    >
                                        {row.assists}
                                    </TableCell>
                                    <TableCell
                                        data-numeric
                                        className="text-xs tabular-nums"
                                    >
                                        {row.pass_accuracy_pct !== null
                                            ? `${row.pass_accuracy_pct.toFixed(1)}%`
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

MatchesShow.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: '/dashboard' },
        { title: 'Matches', href: '/matches' },
        { title: 'Detail', href: '#' },
    ],
};
