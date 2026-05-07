import { router } from '@inertiajs/react';
import { format, parseISO } from 'date-fns';
import {
    CheckCircle2,
    ExternalLink,
    FileText,
    Loader2,
    Undo2,
    XCircle,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { cn } from '@/lib/utils';

type Lab = {
    name: string;
    key?: string | null;
    value: number | string;
    unit?: string | null;
    ref_low?: number | string | null;
    ref_high?: number | string | null;
    flag?: 'low' | 'normal' | 'high' | 'critical' | string | null;
};

export type DocumentEntry = {
    id: number;
    record_date: string;
    category: { slug: string; label: string };
    source_lab: string | null;
    summary_text: string | null;
    attachment: {
        id: number;
        filename: string;
        mime_type: string;
        size_bytes: number;
        page_count: number | null;
        download_url: string;
    } | null;
    labs: Lab[];
    reviewed: {
        is_reviewed: boolean;
        reviewed_at: string | null;
        reviewer_name: string | null;
    };
    extraction_pending: boolean;
    extraction_failed: boolean;
    extraction_partial?: boolean;
    chunks_completed?: number | null;
    chunks_total?: number | null;
    awaiting_callback?: boolean;
};

export function PlayerDocuments({ documents }: { documents: DocumentEntry[] }) {
    if (documents.length === 0) {
        return (
            <Card>
                <CardContent className="flex flex-col items-start gap-3 py-8">
                    <div className="grid size-9 place-items-center rounded-md bg-muted text-muted-foreground">
                        <FileText className="size-4" />
                    </div>
                    <h3 className="text-sm font-semibold">No documents yet</h3>
                    <p className="max-w-prose text-sm text-muted-foreground">
                        Uploaded files appear here once they're classified. Open
                        the source PDF, verify the extracted lab values, and
                        sign off so the record enters the audit trail.
                    </p>
                </CardContent>
            </Card>
        );
    }

    return (
        <div className="space-y-3">
            {documents.map((doc) => (
                <DocumentCard key={doc.id} doc={doc} />
            ))}
        </div>
    );
}

function DocumentCard({ doc }: { doc: DocumentEntry }) {
    const [marking, setMarking] = useState(false);
    const [unmarking, setUnmarking] = useState(false);
    const [expanded, setExpanded] = useState(false);

    const markReviewed = (): void => {
        setMarking(true);
        router.post(
            `/records/${doc.id}/review`,
            {},
            {
                preserveScroll: true,
                onFinish: () => setMarking(false),
            },
        );
    };

    const undoReview = (): void => {
        setUnmarking(true);
        router.delete(`/records/${doc.id}/review`, {
            preserveScroll: true,
            onFinish: () => setUnmarking(false),
        });
    };

    const isBloodTest = doc.category.slug === 'blood_test';
    const labCount = doc.labs.length;

    return (
        <Card>
            <CardContent className="flex flex-col gap-3 py-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="min-w-0 space-y-1.5">
                        <div className="flex flex-wrap items-center gap-2">
                            <Badge variant="outline" className="capitalize">
                                {doc.category.label}
                            </Badge>
                            <span
                                data-numeric
                                className="text-xs text-muted-foreground tabular-nums"
                            >
                                {doc.record_date}
                            </span>
                            {doc.source_lab && (
                                <span className="text-xs text-muted-foreground">
                                    · {doc.source_lab}
                                </span>
                            )}
                            <ExtractionBadge
                                pending={doc.extraction_pending}
                                failed={doc.extraction_failed}
                                partial={doc.extraction_partial === true}
                                chunksCompleted={doc.chunks_completed ?? null}
                                chunksTotal={doc.chunks_total ?? null}
                                awaiting={doc.awaiting_callback === true}
                            />
                        </div>
                        {doc.attachment && (
                            <div className="flex items-center gap-2 text-sm">
                                <FileText className="size-3.5 shrink-0 text-muted-foreground" />
                                <span className="truncate font-medium">
                                    {doc.attachment.filename}
                                </span>
                                <span className="text-xs text-muted-foreground">
                                    {formatBytes(doc.attachment.size_bytes)}
                                    {doc.attachment.page_count !== null && (
                                        <> · {doc.attachment.page_count}p</>
                                    )}
                                </span>
                            </div>
                        )}
                        {doc.summary_text && (
                            <p className="text-sm text-muted-foreground">
                                {doc.summary_text}
                            </p>
                        )}
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        {doc.attachment && (
                            <Button size="sm" variant="outline" asChild>
                                <a
                                    href={doc.attachment.download_url}
                                    target="_blank"
                                    rel="noreferrer noopener"
                                >
                                    <ExternalLink className="size-4" />
                                    Open
                                </a>
                            </Button>
                        )}
                        {doc.reviewed.is_reviewed ? (
                            <div className="flex items-center gap-1.5">
                                <ReviewedBadge
                                    name={doc.reviewed.reviewer_name}
                                    at={doc.reviewed.reviewed_at}
                                />
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    onClick={undoReview}
                                    disabled={unmarking}
                                    title="Undo review"
                                    className="h-7 w-7 p-0 text-muted-foreground hover:text-foreground"
                                >
                                    <Undo2 className="size-3.5" />
                                    <span className="sr-only">Undo review</span>
                                </Button>
                            </div>
                        ) : (
                            <Button
                                size="sm"
                                onClick={markReviewed}
                                disabled={marking}
                            >
                                <CheckCircle2 className="size-4" />
                                {marking ? 'Saving…' : 'Mark reviewed'}
                            </Button>
                        )}
                    </div>
                </div>

                {isBloodTest && labCount > 0 && (
                    <div className="border-t pt-3">
                        <button
                            type="button"
                            onClick={() => setExpanded((v) => !v)}
                            className="text-xs font-medium text-muted-foreground hover:text-foreground"
                        >
                            {expanded ? 'Hide' : 'Show'} {labCount} extracted
                            analyte{labCount !== 1 && 's'}
                        </button>
                        {expanded && <LabsTable labs={doc.labs} />}
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

function ExtractionBadge({
    pending,
    failed,
    partial,
    chunksCompleted,
    chunksTotal,
    awaiting,
}: {
    pending: boolean;
    failed: boolean;
    partial: boolean;
    chunksCompleted: number | null;
    chunksTotal: number | null;
    awaiting: boolean;
}) {
    if (failed) {
        return (
            <span className="inline-flex items-center gap-1 text-xs text-destructive">
                <XCircle className="size-3" />
                extraction failed
            </span>
        );
    }

    if (partial) {
        return (
            <span
                data-numeric
                className="inline-flex items-center gap-1 text-xs text-warning tabular-nums"
            >
                <XCircle className="size-3" />
                partial · {chunksCompleted ?? '?'}/{chunksTotal ?? '?'} sections
            </span>
        );
    }

    if (awaiting) {
        return (
            <span
                className="inline-flex items-center gap-1 text-xs text-primary"
                title="Sync call timed out at the proxy; n8n will deliver the result via callback."
            >
                <Loader2 className="size-3 animate-spin" />
                awaiting callback
            </span>
        );
    }

    if (pending) {
        return (
            <span className="inline-flex items-center gap-1 text-xs text-muted-foreground">
                <Loader2 className="size-3" />
                extraction pending
            </span>
        );
    }

    return null;
}

function ReviewedBadge({
    name,
    at,
}: {
    name: string | null;
    at: string | null;
}) {
    const when = at ? format(parseISO(at), 'd MMM yyyy') : null;
    const tooltip = name && when ? `${name} · ${when}` : (name ?? when ?? '');

    return (
        <span
            className="inline-flex items-center gap-1.5 rounded-md border border-success/30 bg-success/10 px-2.5 py-1 text-xs font-medium text-success"
            title={tooltip}
        >
            <CheckCircle2 className="size-3.5" />
            Reviewed {when && `· ${when}`}
        </span>
    );
}

function LabsTable({ labs }: { labs: Lab[] }) {
    const flagTone = useMemo<Record<string, string>>(
        () => ({
            low: 'text-warning',
            high: 'text-warning',
            critical: 'text-destructive font-semibold',
            normal: 'text-muted-foreground',
        }),
        [],
    );

    return (
        <div className="mt-3 overflow-x-auto">
            <table className="w-full text-sm">
                <thead className="text-xs tracking-wide text-muted-foreground uppercase">
                    <tr className="border-b">
                        <th className="py-1.5 pr-4 text-left font-medium">
                            Analyte
                        </th>
                        <th className="py-1.5 pr-4 text-right font-medium">
                            Value
                        </th>
                        <th className="py-1.5 pr-4 text-left font-medium">
                            Unit
                        </th>
                        <th className="py-1.5 pr-4 text-right font-medium">
                            Reference
                        </th>
                        <th className="py-1.5 text-left font-medium">Flag</th>
                    </tr>
                </thead>
                <tbody className="divide-y divide-border/60">
                    {labs.map((lab, i) => {
                        const flag = (lab.flag ?? 'normal') as string;

                        return (
                            <tr key={i} className="text-sm">
                                <td className="py-1.5 pr-4 font-medium">
                                    {lab.name}
                                </td>
                                <td
                                    data-numeric
                                    className={cn(
                                        'py-1.5 pr-4 text-right tabular-nums',
                                        flagTone[flag],
                                    )}
                                >
                                    {lab.value}
                                </td>
                                <td className="py-1.5 pr-4 text-muted-foreground">
                                    {lab.unit ?? '—'}
                                </td>
                                <td
                                    data-numeric
                                    className="py-1.5 pr-4 text-right text-muted-foreground tabular-nums"
                                >
                                    {lab.ref_low ?? '—'}–{lab.ref_high ?? '—'}
                                </td>
                                <td className={cn('py-1.5', flagTone[flag])}>
                                    {flag}
                                </td>
                            </tr>
                        );
                    })}
                </tbody>
            </table>
        </div>
    );
}

function formatBytes(bytes: number): string {
    if (bytes < 1024) {
        return `${bytes} B`;
    }

    if (bytes < 1024 * 1024) {
        return `${(bytes / 1024).toFixed(1)} KB`;
    }

    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}
