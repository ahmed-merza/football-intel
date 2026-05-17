import { router } from '@inertiajs/react';
import { format, parseISO } from 'date-fns';
import {
    AlertTriangle,
    Beaker,
    CheckCircle2,
    Gauge,
    Loader2,
    RefreshCw,
    Sparkles,
    XCircle,
} from 'lucide-react';
import { useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { cn } from '@/lib/utils';

type Recommendation = {
    area:
        | 'nutrition'
        | 'supplement'
        | 'hydration'
        | 'monitoring'
        | 'training_load'
        | 'recovery';
    action: string;
};

type RiskFlag = {
    severity: 'info' | 'warn' | 'critical';
    kind: string;
    message: string;
};

type AnalysisPayload = {
    summary?: string;
    blood_analysis?: {
        key_findings?: string[];
        football_implications?: string[];
    };
    body_analysis?: {
        key_findings?: string[];
        football_implications?: string[];
    };
    combined_insight?: string;
    recommendations?: Recommendation[];
    risk_flags?: RiskFlag[];
};

export type Analysis = {
    id: number;
    status: 'pending' | 'completed' | 'failed';
    model_used: string | null;
    generated_at: string | null;
    generated_by: string | null;
    summary_text: string | null;
    error: string | null;
    payload: AnalysisPayload | null;
    sources: {
        blood_record_id: number | null;
        inbody_record_id: number | null;
    };
    created_at: string | null;
};

type Eligibility = {
    has_blood: boolean;
    has_inbody: boolean;
    can_run: boolean;
};

type PlayerAiAnalysisProps = {
    playerId: number;
    analyses: Analysis[];
    eligibility: Eligibility;
};

export function PlayerAiAnalysis({
    playerId,
    analyses,
    eligibility,
}: PlayerAiAnalysisProps) {
    const [running, setRunning] = useState(false);
    const [historyOpen, setHistoryOpen] = useState(false);

    const runAnalysis = (): void => {
        setRunning(true);
        router.post(
            `/players/${playerId}/nutritionist-analyses`,
            {},
            {
                preserveScroll: true,
                onFinish: () => setRunning(false),
            },
        );
    };

    const latest = analyses[0];
    const history = analyses.slice(1);

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 className="text-base font-semibold">
                        Nutritionist Assistant
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        Combines the latest blood test + body composition into
                        sport-specific recommendations.
                    </p>
                </div>
                <Button
                    onClick={runAnalysis}
                    disabled={running || !eligibility.can_run}
                    title={
                        eligibility.can_run
                            ? 'Run a fresh analysis on the latest data'
                            : 'Need at least one blood test and one body-composition record'
                    }
                >
                    <RefreshCw
                        className={'size-4 ' + (running ? 'animate-spin' : '')}
                    />
                    {running ? 'Queuing…' : 'Run analysis'}
                </Button>
            </div>

            {!eligibility.can_run && (
                <EligibilityHints eligibility={eligibility} />
            )}

            {latest === undefined ? (
                <EmptyState />
            ) : (
                <AnalysisCard analysis={latest} headline />
            )}

            {history.length > 0 && (
                <Card>
                    <CardHeader className="pb-2">
                        <button
                            type="button"
                            onClick={() => setHistoryOpen((v) => !v)}
                            className="flex w-full items-center justify-between text-sm font-medium text-muted-foreground hover:text-foreground"
                        >
                            <span>Previous runs ({history.length})</span>
                            <span className="text-xs">
                                {historyOpen ? 'Hide' : 'Show'}
                            </span>
                        </button>
                    </CardHeader>
                    {historyOpen && (
                        <CardContent className="space-y-3">
                            {history.map((a) => (
                                <AnalysisCard key={a.id} analysis={a} />
                            ))}
                        </CardContent>
                    )}
                </Card>
            )}
        </div>
    );
}

function EligibilityHints({ eligibility }: { eligibility: Eligibility }) {
    return (
        <Card className="border-dashed">
            <CardContent className="flex flex-col gap-2 py-4 text-sm">
                <p className="font-medium">
                    The assistant needs both kinds of records:
                </p>
                <ul className="space-y-1 text-muted-foreground">
                    <li className="flex items-center gap-2">
                        {eligibility.has_blood ? (
                            <CheckCircle2 className="size-4 text-success" />
                        ) : (
                            <XCircle className="size-4 text-warning" />
                        )}
                        At least one blood test on file
                    </li>
                    <li className="flex items-center gap-2">
                        {eligibility.has_inbody ? (
                            <CheckCircle2 className="size-4 text-success" />
                        ) : (
                            <XCircle className="size-4 text-warning" />
                        )}
                        At least one body-composition record
                    </li>
                </ul>
            </CardContent>
        </Card>
    );
}

function EmptyState() {
    return (
        <Card>
            <CardContent className="flex flex-col items-start gap-3 py-8">
                <div className="grid size-9 place-items-center rounded-md bg-muted text-muted-foreground">
                    <Sparkles className="size-4" />
                </div>
                <h3 className="text-sm font-semibold">No analyses yet</h3>
                <p className="max-w-prose text-sm text-muted-foreground">
                    Click <strong>Run analysis</strong> when you're ready — the
                    assistant will read the latest blood + body-comp records and
                    write up findings, implications, and recommendations
                    grounded in football performance.
                </p>
            </CardContent>
        </Card>
    );
}

function AnalysisCard({
    analysis,
    headline = false,
}: {
    analysis: Analysis;
    headline?: boolean;
}) {
    if (analysis.status === 'pending') {
        return (
            <Card className={cn(headline && 'border-primary/40 bg-primary/5')}>
                <CardContent className="flex items-center gap-3 py-4 text-sm">
                    <Loader2 className="size-4 animate-spin text-primary" />
                    <span>
                        Analysis queued — refresh in a few seconds, the
                        assistant is working on it.
                    </span>
                </CardContent>
            </Card>
        );
    }

    if (analysis.status === 'failed') {
        return <FailedAnalysisCard analysis={analysis} />;
    }

    const payload = analysis.payload ?? {};
    const stamp = analysis.generated_at
        ? format(parseISO(analysis.generated_at), 'd MMM yyyy · HH:mm')
        : null;

    return (
        <Card className={cn(headline && 'border-primary/40')}>
            <CardHeader className="pb-3">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="space-y-1">
                        <CardTitle className="text-base">
                            {payload.summary ??
                                analysis.summary_text ??
                                'Analysis'}
                        </CardTitle>
                        <div className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                            {stamp && <span>{stamp}</span>}
                            {analysis.generated_by && (
                                <span>· by {analysis.generated_by}</span>
                            )}
                            {analysis.model_used && (
                                <Badge variant="outline" className="text-xs">
                                    {analysis.model_used}
                                </Badge>
                            )}
                        </div>
                    </div>
                </div>
            </CardHeader>
            <CardContent className="space-y-5">
                {payload.combined_insight && (
                    <p className="text-sm leading-relaxed">
                        {payload.combined_insight}
                    </p>
                )}

                <div className="grid gap-4 sm:grid-cols-2">
                    <FindingsBlock
                        title="Blood"
                        icon={Beaker}
                        block={payload.blood_analysis}
                    />
                    <FindingsBlock
                        title="Body composition"
                        icon={Gauge}
                        block={payload.body_analysis}
                    />
                </div>

                {payload.risk_flags && payload.risk_flags.length > 0 && (
                    <RisksList risks={payload.risk_flags} />
                )}

                {payload.recommendations &&
                    payload.recommendations.length > 0 && (
                        <RecommendationsList recs={payload.recommendations} />
                    )}
            </CardContent>
        </Card>
    );
}

function FailedAnalysisCard({ analysis }: { analysis: Analysis }) {
    const [retrying, setRetrying] = useState(false);

    const retry = (): void => {
        setRetrying(true);
        router.post(
            `/nutritionist-analyses/${analysis.id}/retry`,
            {},
            {
                preserveScroll: true,
                onFinish: () => setRetrying(false),
            },
        );
    };

    return (
        <Card className="border-destructive/40 bg-destructive/5">
            <CardContent className="flex flex-col gap-3 py-4 text-sm">
                <div className="flex items-center gap-2 font-medium text-destructive">
                    <XCircle className="size-4" />
                    Analysis failed
                </div>
                {analysis.error && (
                    <p className="text-muted-foreground">{analysis.error}</p>
                )}
                <div>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={retry}
                        disabled={retrying}
                    >
                        <RefreshCw
                            className={
                                'size-3.5 ' + (retrying ? 'animate-spin' : '')
                            }
                        />
                        {retrying ? 'Retrying…' : 'Retry'}
                    </Button>
                </div>
            </CardContent>
        </Card>
    );
}

function FindingsBlock({
    title,
    icon: Icon,
    block,
}: {
    title: string;
    icon: typeof Beaker;
    block?: { key_findings?: string[]; football_implications?: string[] };
}) {
    if (!block) {
        return null;
    }

    const findings = block.key_findings ?? [];
    const implications = block.football_implications ?? [];

    if (findings.length === 0 && implications.length === 0) {
        return null;
    }

    return (
        <div className="space-y-2">
            <h4 className="flex items-center gap-1.5 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                <Icon className="size-3.5" />
                {title}
            </h4>
            {findings.length > 0 && (
                <ul className="list-disc space-y-1 pl-4 text-sm">
                    {findings.map((f, i) => (
                        <li key={i}>{f}</li>
                    ))}
                </ul>
            )}
            {implications.length > 0 && (
                <div className="space-y-0.5 rounded-md border-l-2 border-primary/40 bg-primary/5 px-3 py-2 text-xs">
                    <span className="font-medium text-primary">
                        Implications for football
                    </span>
                    <ul className="list-disc space-y-0.5 pl-4 text-foreground">
                        {implications.map((f, i) => (
                            <li key={i}>{f}</li>
                        ))}
                    </ul>
                </div>
            )}
        </div>
    );
}

function RisksList({ risks }: { risks: RiskFlag[] }) {
    const tone: Record<string, string> = {
        critical: 'border-destructive/40 bg-destructive/5 text-destructive',
        warn: 'border-warning/40 bg-warning/5 text-warning',
        info: 'border-border bg-muted text-muted-foreground',
    };

    return (
        <div className="space-y-2">
            <h4 className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                Risk flags
            </h4>
            <ul className="space-y-1.5">
                {risks.map((risk, i) => (
                    <li
                        key={i}
                        className={cn(
                            'flex items-start gap-2 rounded-md border px-3 py-2 text-sm',
                            tone[risk.severity] ?? tone.info,
                        )}
                    >
                        <AlertTriangle className="mt-0.5 size-4 shrink-0" />
                        <div>
                            <span className="font-medium capitalize">
                                {risk.kind.replace(/_/g, ' ')}
                            </span>
                            <p className="text-foreground">{risk.message}</p>
                        </div>
                    </li>
                ))}
            </ul>
        </div>
    );
}

function RecommendationsList({ recs }: { recs: Recommendation[] }) {
    return (
        <div className="space-y-2">
            <h4 className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                Recommendations
            </h4>
            <ul className="space-y-2">
                {recs.map((rec, i) => (
                    <li
                        key={i}
                        className="flex items-start gap-3 rounded-md border bg-card px-3 py-2 text-sm"
                    >
                        <Badge variant="outline" className="capitalize">
                            {rec.area.replace(/_/g, ' ')}
                        </Badge>
                        <span className="flex-1">{rec.action}</span>
                    </li>
                ))}
            </ul>
        </div>
    );
}
