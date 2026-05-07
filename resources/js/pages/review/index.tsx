import { Head, Link, router } from '@inertiajs/react';
import { CheckCircle2, FileText, Sparkles, UserRound } from 'lucide-react';
import { useState } from 'react';
import { PlayerAvatar } from '@/components/domain/player-avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { cn } from '@/lib/utils';

type Category = { id: number; slug: string; label: string };

type ReviewRecord = {
    id: number;
    created_at: string | null;
    record_date: string;
    summary_text: string | null;
    classifier: {
        category: string | null;
        confidence: number | null;
        reasoning: string | null;
    };
    admin_hint: string | null;
    category: Category;
    player: {
        id: number;
        full_name: string;
        name_ar: string | null;
        photo_url: string | null;
    };
    attachment: {
        id: number;
        filename: string;
        page_count: number | null;
    } | null;
};

type PageProps = {
    records: ReviewRecord[];
    categories: Category[];
    count: number;
};

export default function ReviewIndex({ records, categories, count }: PageProps) {
    return (
        <>
            <Head title="Review queue" />
            <div className="flex flex-1 flex-col gap-4 p-4">
                <header>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Review queue
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        {count === 0
                            ? 'Nothing to review — every upload has been classified confidently.'
                            : `${count} ${count === 1 ? 'record' : 'records'} flagged for review. Confirm or correct the category.`}
                    </p>
                </header>

                {records.length === 0 ? (
                    <Card>
                        <CardContent className="flex flex-col items-center gap-3 py-16 text-center">
                            <div className="grid size-10 place-items-center rounded-lg bg-success/15 text-success">
                                <CheckCircle2 className="size-5" />
                            </div>
                            <h3 className="text-sm font-semibold">
                                Inbox zero
                            </h3>
                            <p className="max-w-sm text-sm text-muted-foreground">
                                The AI classifier is confident on every recent
                                upload. Items land here when confidence drops
                                below 0.7 or the classifier picks "other".
                            </p>
                        </CardContent>
                    </Card>
                ) : (
                    <div className="grid gap-3">
                        {records.map((record) => (
                            <ReviewRow
                                key={record.id}
                                record={record}
                                categories={categories}
                            />
                        ))}
                    </div>
                )}
            </div>
        </>
    );
}

function ReviewRow({
    record,
    categories,
}: {
    record: ReviewRecord;
    categories: Category[];
}) {
    const [slug, setSlug] = useState(record.category.slug);
    const [processing, setProcessing] = useState(false);

    const confirm = (): void => {
        setProcessing(true);
        router.post(
            `/review/${record.id}/confirm`,
            slug !== record.category.slug ? { category_slug: slug } : {},
            {
                preserveScroll: true,
                onFinish: () => setProcessing(false),
            },
        );
    };

    const categoryChanged = slug !== record.category.slug;
    const confidence = record.classifier.confidence;

    return (
        <Card>
            <CardContent className="flex flex-col gap-4 py-4 sm:flex-row sm:items-start">
                <PlayerAvatar
                    name={record.player.full_name}
                    photoUrl={record.player.photo_url}
                    className="size-10"
                />

                <div className="min-w-0 flex-1 space-y-2">
                    <div className="flex flex-wrap items-start justify-between gap-2">
                        <div className="min-w-0">
                            <Link
                                href={`/players/${record.player.id}`}
                                className="flex items-center gap-2 font-medium hover:underline"
                            >
                                <UserRound className="size-3.5 shrink-0 text-muted-foreground" />
                                {record.player.full_name}
                            </Link>
                            {record.player.name_ar && (
                                <span
                                    lang="ar"
                                    dir="rtl"
                                    className="text-xs text-muted-foreground"
                                >
                                    {record.player.name_ar}
                                </span>
                            )}
                        </div>
                        <div className="flex items-center gap-2 text-xs text-muted-foreground">
                            <span data-numeric>{record.record_date}</span>
                        </div>
                    </div>

                    <div className="flex flex-wrap items-center gap-2 text-xs">
                        {record.attachment && (
                            <span className="inline-flex items-center gap-1 text-muted-foreground">
                                <FileText className="size-3.5" />
                                {record.attachment.filename}
                                {record.attachment.page_count !== null && (
                                    <span data-numeric>
                                        · {record.attachment.page_count}p
                                    </span>
                                )}
                            </span>
                        )}
                        <ClassifierBadge
                            category={record.classifier.category}
                            confidence={confidence}
                        />
                        {record.admin_hint && (
                            <Badge variant="outline" className="capitalize">
                                hint: {record.admin_hint.replace(/_/g, ' ')}
                            </Badge>
                        )}
                    </div>

                    {record.classifier.reasoning && (
                        <p className="text-sm text-muted-foreground">
                            <span className="mr-1 inline-flex items-center gap-1 text-primary">
                                <Sparkles className="size-3.5" />
                            </span>
                            {record.classifier.reasoning}
                        </p>
                    )}

                    <div className="flex flex-wrap items-center gap-2 pt-1">
                        <label className="text-xs text-muted-foreground">
                            Category
                        </label>
                        <select
                            value={slug}
                            onChange={(e) => setSlug(e.target.value)}
                            className="flex h-8 rounded-md border border-input bg-background px-2 text-sm shadow-xs transition-colors focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-none"
                        >
                            {categories.map((c) => (
                                <option key={c.id} value={c.slug}>
                                    {c.label}
                                </option>
                            ))}
                        </select>
                        {categoryChanged && (
                            <span className="text-xs font-medium text-warning">
                                change pending
                            </span>
                        )}
                        <Button
                            size="sm"
                            onClick={confirm}
                            disabled={processing}
                            className="ml-auto"
                        >
                            <CheckCircle2 className="size-4" />
                            {categoryChanged ? 'Save & re-extract' : 'Confirm'}
                        </Button>
                    </div>
                </div>
            </CardContent>
        </Card>
    );
}

function ClassifierBadge({
    category,
    confidence,
}: {
    category: string | null;
    confidence: number | null;
}) {
    if (category === null) {
        return null;
    }

    const tone =
        confidence !== null && confidence < 0.7
            ? 'bg-warning/15 text-warning border-warning/30'
            : 'bg-muted text-muted-foreground';

    return (
        <Badge
            variant="outline"
            className={cn('capitalize', tone)}
            title={confidence !== null ? `Confidence ${confidence}` : undefined}
        >
            {category.replace(/_/g, ' ')}
            {confidence !== null && (
                <span data-numeric className="ml-1 tabular-nums opacity-80">
                    {(confidence * 100).toFixed(0)}%
                </span>
            )}
        </Badge>
    );
}

ReviewIndex.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: '/dashboard' },
        { title: 'Review queue', href: '/review' },
    ],
};
