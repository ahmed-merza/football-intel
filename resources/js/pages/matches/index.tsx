import { Head, Link } from '@inertiajs/react';
import { Trophy, Upload } from 'lucide-react';
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

type MatchReportStatus =
    | 'pending'
    | 'extracting'
    | 'awaiting_callback'
    | 'extracted'
    | 'applied'
    | 'failed';

type Report = {
    id: number;
    status: MatchReportStatus;
    competition: string | null;
    stage: string | null;
    match_date: string | null;
    home_team_name: string | null;
    away_team_name: string | null;
    home_score: number | null;
    away_score: number | null;
    bahrain_side: 'home' | 'away' | null;
    opponent_name: string | null;
    created_at: string | null;
    applied_at: string | null;
    extraction_error: string | null;
};

type PageProps = {
    reports: Report[];
};

const STATUS_LABEL: Record<MatchReportStatus, string> = {
    pending: 'Queued',
    extracting: 'Extracting…',
    awaiting_callback: 'Awaiting callback…',
    extracted: 'Awaiting review',
    applied: 'Applied',
    failed: 'Failed',
};

const STATUS_TONE: Record<MatchReportStatus, string> = {
    pending: 'bg-muted text-muted-foreground',
    extracting:
        'bg-blue-100 text-blue-900 dark:bg-blue-950/40 dark:text-blue-200',
    awaiting_callback:
        'bg-blue-100 text-blue-900 dark:bg-blue-950/40 dark:text-blue-200',
    extracted:
        'bg-amber-100 text-amber-900 dark:bg-amber-950/40 dark:text-amber-200',
    applied:
        'bg-emerald-100 text-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-200',
    failed: 'bg-destructive/10 text-destructive',
};

export default function MatchesIndex({ reports }: PageProps) {
    return (
        <>
            <Head title="Matches" />
            <div className="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-4 p-4">
                <header className="flex items-start justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Match reports
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Upload AGCFF / Wyscout-style PDFs to extract
                            per-player performance data onto each player&apos;s
                            timeline.
                        </p>
                    </div>
                    <Button asChild>
                        <Link href="/matches/upload">
                            <Upload className="size-4" />
                            Upload report
                        </Link>
                    </Button>
                </header>

                <Card className="overflow-hidden p-0">
                    <CardHeader className="border-b py-3">
                        <CardTitle className="text-sm">All reports</CardTitle>
                    </CardHeader>
                    <CardContent className="p-0">
                        {reports.length === 0 ? (
                            <EmptyState />
                        ) : (
                            <div className="overflow-x-auto">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Fixture</TableHead>
                                            <TableHead>Competition</TableHead>
                                            <TableHead>Date</TableHead>
                                            <TableHead>Status</TableHead>
                                            <TableHead className="text-right">
                                                {/* actions */}
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {reports.map((r) => (
                                            <ReportRow key={r.id} report={r} />
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

function ReportRow({ report }: { report: Report }) {
    const fixtureHref =
        report.status === 'applied'
            ? `/matches/${report.id}`
            : `/matches/${report.id}/preview`;

    return (
        <TableRow>
            <TableCell>
                <Link
                    href={fixtureHref}
                    className="font-medium underline-offset-2 hover:underline"
                >
                    {fixtureLabel(report)}
                </Link>
                {report.extraction_error && (
                    <div className="mt-0.5 text-[11px] text-destructive">
                        {report.extraction_error}
                    </div>
                )}
            </TableCell>
            <TableCell className="text-sm text-muted-foreground">
                {report.competition ? (
                    <>
                        {report.competition}
                        {report.stage && (
                            <span className="ml-1 text-xs">
                                — {report.stage}
                            </span>
                        )}
                    </>
                ) : (
                    <span>—</span>
                )}
            </TableCell>
            <TableCell data-numeric className="text-sm text-muted-foreground">
                {report.match_date ?? '—'}
            </TableCell>
            <TableCell>
                <Badge
                    variant="outline"
                    className={cn(
                        'border-transparent',
                        STATUS_TONE[report.status],
                    )}
                >
                    {STATUS_LABEL[report.status]}
                </Badge>
            </TableCell>
            <TableCell className="text-right">
                <Button asChild variant="ghost" size="sm">
                    <Link href={fixtureHref}>Open</Link>
                </Button>
            </TableCell>
        </TableRow>
    );
}

function fixtureLabel(r: Report): string {
    const home = r.home_team_name ?? '—';
    const away = r.away_team_name ?? '—';

    if (r.home_score !== null && r.away_score !== null) {
        return `${home} ${r.home_score} – ${r.away_score} ${away}`;
    }

    return `${home} vs ${away}`;
}

function EmptyState() {
    return (
        <div className="flex flex-col items-center justify-center gap-3 py-16 text-center">
            <Trophy className="size-10 text-muted-foreground" />
            <div className="text-sm font-medium">No match reports yet</div>
            <p className="max-w-md text-xs text-muted-foreground">
                Upload an AGCFF or Wyscout match-report PDF and we&apos;ll
                extract every player&apos;s performance, then let you resolve
                them onto your existing roster.
            </p>
            <Button asChild size="sm" className="mt-1">
                <Link href="/matches/upload">
                    <Upload className="size-4" />
                    Upload your first report
                </Link>
            </Button>
        </div>
    );
}

MatchesIndex.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: '/dashboard' },
        { title: 'Matches', href: '/matches' },
    ],
};
