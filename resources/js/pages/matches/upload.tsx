import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, FileText, UploadCloud } from 'lucide-react';
import { useRef, useState } from 'react';
import type { ChangeEvent, DragEvent, FormEvent } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { cn } from '@/lib/utils';

export default function MatchesUpload() {
    const fileInputRef = useRef<HTMLInputElement | null>(null);
    const [dragActive, setDragActive] = useState(false);

    const form = useForm<{ file: File | null }>({ file: null });
    const { data, setData, errors, processing, progress } = form;

    const onFiles = (files: FileList | null): void => {
        if (!files || files.length === 0) {
            return;
        }

        setData('file', files[0]);
    };

    const onDrop = (e: DragEvent<HTMLLabelElement>): void => {
        e.preventDefault();
        setDragActive(false);
        onFiles(e.dataTransfer.files);
    };

    const submit = (e: FormEvent<HTMLFormElement>): void => {
        e.preventDefault();

        if (!data.file) {
            return;
        }

        form.post('/matches/upload', { forceFormData: true });
    };

    return (
        <>
            <Head title="Upload match report" />
            <div className="mx-auto flex w-full max-w-3xl flex-1 flex-col gap-4 p-4">
                <header className="flex items-start justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Upload match report
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Drop in an AGCFF / Wyscout PDF. We&apos;ll extract
                            the meta + every player&apos;s stat line, then ask
                            you to resolve the Bahrain players onto your roster.
                        </p>
                    </div>
                    <Button asChild variant="ghost" size="sm">
                        <Link href="/matches">
                            <ArrowLeft className="size-4" />
                            Back to matches
                        </Link>
                    </Button>
                </header>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">
                            Choose your PDF
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={submit} className="flex flex-col gap-4">
                            <label
                                onDragOver={(e) => {
                                    e.preventDefault();
                                    setDragActive(true);
                                }}
                                onDragLeave={() => setDragActive(false)}
                                onDrop={onDrop}
                                className={cn(
                                    'flex cursor-pointer flex-col items-center justify-center gap-2 rounded-lg border-2 border-dashed bg-muted/30 px-6 py-12 text-center transition-colors',
                                    dragActive
                                        ? 'border-primary bg-primary/5'
                                        : 'border-border hover:bg-muted/60',
                                )}
                            >
                                {data.file ? (
                                    <>
                                        <FileText className="size-10 text-primary" />
                                        <div className="text-sm font-medium">
                                            {data.file.name}
                                        </div>
                                        <div className="text-xs text-muted-foreground">
                                            {(data.file.size / 1024 / 1024).toFixed(
                                                1,
                                            )}{' '}
                                            MB — click to choose a different file
                                        </div>
                                    </>
                                ) : (
                                    <>
                                        <UploadCloud className="size-10 text-muted-foreground" />
                                        <div className="text-sm font-medium">
                                            Drag a PDF here, or click to browse
                                        </div>
                                        <div className="text-xs text-muted-foreground">
                                            Up to 25 MB. PDFs only.
                                        </div>
                                    </>
                                )}
                                <input
                                    ref={fileInputRef}
                                    type="file"
                                    className="hidden"
                                    accept=".pdf,application/pdf"
                                    onChange={(
                                        e: ChangeEvent<HTMLInputElement>,
                                    ) => onFiles(e.target.files)}
                                />
                            </label>

                            <InputError message={errors.file} />

                            {progress && (
                                <div className="h-1.5 w-full overflow-hidden rounded-full bg-muted">
                                    <div
                                        className="h-full bg-primary transition-all"
                                        style={{
                                            width: `${progress.percentage}%`,
                                        }}
                                    />
                                </div>
                            )}

                            <div className="flex justify-end">
                                <Button
                                    type="submit"
                                    disabled={!data.file || processing}
                                >
                                    {processing
                                        ? 'Uploading…'
                                        : 'Upload and extract'}
                                </Button>
                            </div>
                        </form>

                        <div className="mt-6 rounded-md border bg-muted/30 p-4 text-xs text-muted-foreground">
                            <div className="mb-2 font-medium text-foreground">
                                What happens next
                            </div>
                            <ol className="ml-4 list-decimal space-y-1">
                                <li>
                                    We extract text from the PDF and parse it
                                    with the match-report AI agent.
                                </li>
                                <li>
                                    You review the proposed Bahrain players
                                    — confirm matches, fix anonymised
                                    &quot;Player N&quot; rows, or create new
                                    players inline.
                                </li>
                                <li>
                                    On apply, each resolved player gets a
                                    timeline entry + their match metrics show
                                    on the trend charts automatically.
                                </li>
                            </ol>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

MatchesUpload.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: '/dashboard' },
        { title: 'Matches', href: '/matches' },
        { title: 'Upload', href: '/matches/upload' },
    ],
};
