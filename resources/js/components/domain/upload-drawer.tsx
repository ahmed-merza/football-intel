import { useForm } from '@inertiajs/react';
import { FileText, UploadCloud, X } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import type { ChangeEvent, DragEvent, FormEvent } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';

type CategorySlug =
    | 'blood_test'
    | 'inbody'
    | 'gps_wearable'
    | 'nutrition_plan'
    | 'hydration_supplement_plan'
    | 'coach_feedback'
    | 'match_activity'
    | 'other';

type UploadDrawerProps = {
    playerId: number;
    playerName: string;
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

type UploadPayload = {
    files: File[];
    category_hint: CategorySlug | '';
    notes: string;
};

const CATEGORY_OPTIONS: { value: CategorySlug; label: string }[] = [
    { value: 'blood_test', label: 'Blood test' },
    { value: 'inbody', label: 'Body composition (InBody)' },
    { value: 'gps_wearable', label: 'GPS / wearable session' },
    { value: 'nutrition_plan', label: 'Nutrition plan' },
    { value: 'hydration_supplement_plan', label: 'Hydration / supplements' },
    { value: 'coach_feedback', label: 'Coach feedback' },
    { value: 'match_activity', label: 'Match activity' },
    { value: 'other', label: 'Other' },
];

const MAX_FILES = 10;
const MAX_SIZE_MB = 15;
const ACCEPTED_MIME =
    'application/pdf,image/jpeg,image/png,image/webp,image/heic,image/heif,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv';

export function UploadDrawer({
    playerId,
    playerName,
    open,
    onOpenChange,
}: UploadDrawerProps) {
    const fileInputRef = useRef<HTMLInputElement | null>(null);
    const [dragActive, setDragActive] = useState(false);

    const form = useForm<UploadPayload>({
        files: [],
        category_hint: '',
        notes: '',
    });

    const { data, setData, errors, processing, progress, reset, clearErrors } =
        form;

    // Wipe state every time the drawer closes so the next open starts fresh.

    useEffect(() => {
        if (!open) {
            reset();
            clearErrors();

            if (fileInputRef.current) {
                fileInputRef.current.value = '';
            }
        }
    }, [open, reset, clearErrors]);

    const addFiles = useCallback(
        (incoming: FileList | File[]): void => {
            const current = data.files;
            const combined = [...current, ...Array.from(incoming)];

            // Deduplicate by name + size so dragging the same file twice
            // doesn't stack it. Real dedupe happens server-side via sha256.
            const seen = new Set<string>();
            const deduped = combined.filter((file) => {
                const key = `${file.name}:${file.size}`;

                if (seen.has(key)) {
                    return false;
                }

                seen.add(key);

                return true;
            });

            setData('files', deduped.slice(0, MAX_FILES));
        },
        [data.files, setData],
    );

    const onFileInput = (event: ChangeEvent<HTMLInputElement>): void => {
        if (event.target.files) {
            addFiles(event.target.files);
        }
    };

    const onDrop = (event: DragEvent<HTMLDivElement>): void => {
        event.preventDefault();
        setDragActive(false);

        if (event.dataTransfer.files) {
            addFiles(event.dataTransfer.files);
        }
    };

    const onDragOver = (event: DragEvent<HTMLDivElement>): void => {
        event.preventDefault();

        if (!dragActive) {
            setDragActive(true);
        }
    };

    const onDragLeave = (event: DragEvent<HTMLDivElement>): void => {
        event.preventDefault();
        setDragActive(false);
    };

    const removeFile = (index: number): void => {
        setData(
            'files',
            data.files.filter((_, i) => i !== index),
        );
    };

    const submit = (event: FormEvent<HTMLFormElement>): void => {
        event.preventDefault();
        form.post(`/players/${playerId}/submissions`, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                onOpenChange(false);
            },
        });
    };

    const totalBytes = data.files.reduce((sum, f) => sum + f.size, 0);

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent
                side="right"
                className="flex w-full flex-col gap-0 p-0 sm:max-w-lg"
            >
                <SheetHeader className="border-b">
                    <SheetTitle>Upload documents</SheetTitle>
                    <SheetDescription>
                        Adding records for {playerName}. Files go through
                        extraction → classification → structured data in the
                        background. You'll see them on the timeline once
                        processed.
                    </SheetDescription>
                </SheetHeader>

                <form
                    onSubmit={submit}
                    className="flex flex-1 flex-col overflow-hidden"
                >
                    <div className="flex-1 space-y-5 overflow-y-auto p-6">
                        <DropZone
                            dragActive={dragActive}
                            onDrop={onDrop}
                            onDragOver={onDragOver}
                            onDragLeave={onDragLeave}
                            onBrowse={() => fileInputRef.current?.click()}
                        />
                        <input
                            ref={fileInputRef}
                            type="file"
                            multiple
                            hidden
                            accept={ACCEPTED_MIME}
                            onChange={onFileInput}
                        />
                        <InputError
                            message={errors.files as string | undefined}
                        />

                        {data.files.length > 0 && (
                            <FileList
                                files={data.files}
                                totalBytes={totalBytes}
                                onRemove={removeFile}
                                processing={processing}
                            />
                        )}

                        <div className="grid gap-2">
                            <Label htmlFor="category_hint">
                                Category hint
                                <span className="ml-1 text-xs font-normal text-muted-foreground">
                                    (optional — AI will confirm)
                                </span>
                            </Label>
                            <select
                                id="category_hint"
                                value={data.category_hint}
                                onChange={(e) =>
                                    setData(
                                        'category_hint',
                                        e.target.value as CategorySlug | '',
                                    )
                                }
                                className="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-xs ring-offset-background transition-colors focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                <option value="">Let the AI decide</option>
                                {CATEGORY_OPTIONS.map((o) => (
                                    <option key={o.value} value={o.value}>
                                        {o.label}
                                    </option>
                                ))}
                            </select>
                            <InputError
                                message={
                                    errors.category_hint as string | undefined
                                }
                            />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="upload-notes">
                                Notes (optional)
                            </Label>
                            <Textarea
                                id="upload-notes"
                                placeholder="Anything the AI should know — context, date clarifications, concerns…"
                                value={data.notes}
                                onChange={(e) =>
                                    setData('notes', e.target.value)
                                }
                                className="min-h-[80px]"
                            />
                            <InputError
                                message={errors.notes as string | undefined}
                            />
                        </div>
                    </div>

                    <SheetFooter className="border-t bg-background p-4">
                        {progress !== null && processing && (
                            <div className="mb-3 w-full">
                                <div className="mb-1 flex items-center justify-between text-xs text-muted-foreground">
                                    <span>Uploading…</span>
                                    <span data-numeric className="tabular-nums">
                                        {progress.percentage}%
                                    </span>
                                </div>
                                <div className="h-1.5 overflow-hidden rounded-full bg-muted">
                                    <div
                                        className="h-full bg-primary transition-all duration-150"
                                        style={{
                                            width: `${progress.percentage}%`,
                                        }}
                                    />
                                </div>
                            </div>
                        )}
                        <div className="flex w-full items-center justify-end gap-2">
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={() => onOpenChange(false)}
                                disabled={processing}
                            >
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                disabled={processing || data.files.length === 0}
                            >
                                <UploadCloud className="size-4" />
                                {processing
                                    ? 'Uploading…'
                                    : `Upload ${data.files.length || ''}`}
                            </Button>
                        </div>
                    </SheetFooter>
                </form>
            </SheetContent>
        </Sheet>
    );
}

function DropZone({
    dragActive,
    onDrop,
    onDragOver,
    onDragLeave,
    onBrowse,
}: {
    dragActive: boolean;
    onDrop: (e: DragEvent<HTMLDivElement>) => void;
    onDragOver: (e: DragEvent<HTMLDivElement>) => void;
    onDragLeave: (e: DragEvent<HTMLDivElement>) => void;
    onBrowse: () => void;
}) {
    return (
        <div
            onDrop={onDrop}
            onDragOver={onDragOver}
            onDragLeave={onDragLeave}
            className={cn(
                'flex flex-col items-center gap-3 rounded-xl border-2 border-dashed p-8 text-center transition-colors duration-150',
                dragActive
                    ? 'border-primary bg-primary/5'
                    : 'border-border bg-card/40 hover:border-primary/40',
            )}
        >
            <div className="grid size-10 place-items-center rounded-lg bg-muted text-muted-foreground">
                <UploadCloud className="size-5" />
            </div>
            <div>
                <p className="text-sm font-medium">Drag and drop files here</p>
                <p className="mt-1 text-xs text-muted-foreground">
                    PDF · JPG · PNG · WebP · HEIC · DOCX · XLSX · {MAX_SIZE_MB}{' '}
                    MB each · up to {MAX_FILES} files
                </p>
            </div>
            <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={onBrowse}
            >
                Browse files
            </Button>
        </div>
    );
}

function FileList({
    files,
    totalBytes,
    onRemove,
    processing,
}: {
    files: File[];
    totalBytes: number;
    onRemove: (index: number) => void;
    processing: boolean;
}) {
    return (
        <div className="rounded-lg border">
            <div className="flex items-center justify-between border-b px-3 py-2 text-xs text-muted-foreground">
                <span>
                    {files.length} file{files.length !== 1 && 's'} selected
                </span>
                <span data-numeric className="tabular-nums">
                    {formatBytes(totalBytes)}
                </span>
            </div>
            <ul className="divide-y">
                {files.map((file, i) => (
                    <li
                        key={`${file.name}-${file.size}-${i}`}
                        className="flex items-center gap-3 px-3 py-2 text-sm"
                    >
                        <FileText className="size-4 shrink-0 text-muted-foreground" />
                        <div className="min-w-0 flex-1">
                            <div className="truncate font-medium">
                                {file.name}
                            </div>
                            <div
                                data-numeric
                                className="text-xs text-muted-foreground tabular-nums"
                            >
                                {formatBytes(file.size)}
                            </div>
                        </div>
                        <button
                            type="button"
                            onClick={() => onRemove(i)}
                            disabled={processing}
                            aria-label={`Remove ${file.name}`}
                            className="rounded-md p-1 text-muted-foreground transition-colors duration-150 hover:bg-muted hover:text-foreground disabled:opacity-50"
                        >
                            <X className="size-4" />
                        </button>
                    </li>
                ))}
            </ul>
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
