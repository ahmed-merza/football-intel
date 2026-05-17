import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowLeft,
    CheckCircle2,
    FileSpreadsheet,
    UploadCloud,
} from 'lucide-react';
import { useMemo, useRef, useState } from 'react';
import type { ChangeEvent, DragEvent, FormEvent } from 'react';
import InputError from '@/components/input-error';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
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

type NormalizedRow = {
    full_name: string | null;
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
    status: string;
};

type PreviewRow = {
    row_number: number;
    normalized: NormalizedRow;
    errors: Record<string, string[]>;
    duplicate_of_row: number | null;
};

type Preview = {
    filename: string;
    headers: string[];
    mapping: Record<keyof NormalizedRow | string, number | null>;
    rows: PreviewRow[];
    summary: { total: number; valid: number; invalid: number };
};

type PageProps = {
    preview: Preview | null;
};

const DISPLAY_FIELDS: { key: keyof NormalizedRow; label: string }[] = [
    { key: 'full_name', label: 'Name' },
    { key: 'name_ar', label: 'Arabic name' },
    { key: 'club', label: 'Club' },
    { key: 'position', label: 'Position' },
    { key: 'date_of_birth', label: 'DOB' },
    { key: 'height_cm', label: 'Height' },
    { key: 'weight_kg', label: 'Weight' },
    { key: 'player_code', label: 'Code' },
];

export default function PlayersImport({ preview }: PageProps) {
    return (
        <>
            <Head title="Import players" />
            <div className="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-4 p-4">
                <header className="flex items-start justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Import players
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Upload a CSV or Excel file and we&apos;ll create
                            every player in one go. We auto-detect English and
                            Arabic column headers.
                        </p>
                    </div>
                    <Button asChild variant="ghost" size="sm">
                        <Link href="/players">
                            <ArrowLeft className="size-4" />
                            Back to roster
                        </Link>
                    </Button>
                </header>

                {preview === null ? (
                    <UploadStep />
                ) : (
                    <PreviewStep preview={preview} />
                )}
            </div>
        </>
    );
}

function UploadStep() {
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

        form.post('/players/import/preview', { forceFormData: true });
    };

    return (
        <Card>
            <CardHeader>
                <CardTitle className="text-base">
                    Step 1 — Upload your file
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
                                <FileSpreadsheet className="size-10 text-primary" />
                                <div className="text-sm font-medium">
                                    {data.file.name}
                                </div>
                                <div className="text-xs text-muted-foreground">
                                    {(data.file.size / 1024).toFixed(1)} KB —
                                    click to choose a different file
                                </div>
                            </>
                        ) : (
                            <>
                                <UploadCloud className="size-10 text-muted-foreground" />
                                <div className="text-sm font-medium">
                                    Drag a CSV or Excel file here, or click to
                                    browse
                                </div>
                                <div className="text-xs text-muted-foreground">
                                    Up to 5 MB. Headers can be English or
                                    Arabic.
                                </div>
                            </>
                        )}
                        <input
                            ref={fileInputRef}
                            type="file"
                            className="hidden"
                            accept=".csv,.txt,.xlsx,.xls,.ods"
                            onChange={(e: ChangeEvent<HTMLInputElement>) =>
                                onFiles(e.target.files)
                            }
                        />
                    </label>

                    <InputError message={errors.file} />

                    {progress && (
                        <div className="h-1.5 w-full overflow-hidden rounded-full bg-muted">
                            <div
                                className="h-full bg-primary transition-all"
                                style={{ width: `${progress.percentage}%` }}
                            />
                        </div>
                    )}

                    <div className="flex justify-end">
                        <Button
                            type="submit"
                            disabled={!data.file || processing}
                        >
                            {processing ? 'Reading file…' : 'Continue'}
                        </Button>
                    </div>
                </form>

                <div className="mt-6 rounded-md border bg-muted/30 p-4 text-xs text-muted-foreground">
                    <div className="mb-2 font-medium text-foreground">
                        Recognised columns
                    </div>
                    <div className="grid grid-cols-2 gap-x-4 gap-y-1 sm:grid-cols-3">
                        <span>• Name / الاسم</span>
                        <span>• Arabic name</span>
                        <span>• Club / النادي</span>
                        <span>• Position / المركز</span>
                        <span>• DOB or birth year / مواليد</span>
                        <span>• Nationality / الجنسية</span>
                        <span>• Height (cm) / الطول</span>
                        <span>• Weight (kg) / الوزن</span>
                        <span>• National ID / الرقم</span>
                        <span>• Phone / الهاتف</span>
                        <span>• Email / البريد</span>
                        <span>• Preferred foot</span>
                    </div>
                </div>
            </CardContent>
        </Card>
    );
}

function PreviewStep({ preview }: { preview: Preview }) {
    const [rows, setRows] = useState<PreviewRow[]>(preview.rows);

    const { validRows, invalidRows } = useMemo(() => {
        const valid: PreviewRow[] = [];
        const invalid: PreviewRow[] = [];

        for (const row of rows) {
            if (Object.keys(row.errors).length === 0) {
                valid.push(row);
            } else {
                invalid.push(row);
            }
        }

        return { validRows: valid, invalidRows: invalid };
    }, [rows]);

    // useForm starts empty — `transform` rebuilds the payload from the
    // current `rows` state at submit time so post-Skip state is always
    // reflected. Initialising with `validRows` would freeze the first
    // render's value.
    const form = useForm<{ rows: PreviewRow[] }>({ rows: [] });

    const submit = (e: FormEvent<HTMLFormElement>): void => {
        e.preventDefault();
        form.transform(() => ({
            rows: rows.filter((r) => Object.keys(r.errors).length === 0),
        }));
        form.post('/players/import');
    };

    const removeRow = (rowNumber: number): void => {
        setRows((prev) => prev.filter((r) => r.row_number !== rowNumber));
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-4">
            <div className="grid gap-3 sm:grid-cols-3">
                <SummaryStat
                    label="Total rows"
                    value={preview.summary.total}
                    tone="neutral"
                />
                <SummaryStat
                    label="Ready to import"
                    value={validRows.length}
                    tone="success"
                />
                <SummaryStat
                    label="Need attention"
                    value={invalidRows.length}
                    tone={invalidRows.length > 0 ? 'warn' : 'neutral'}
                />
            </div>

            <Card className="overflow-hidden p-0">
                <CardHeader className="flex flex-row items-center justify-between gap-2 border-b py-3">
                    <CardTitle className="text-sm">
                        Preview —{' '}
                        <span className="font-mono">{preview.filename}</span>
                    </CardTitle>
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={() => router.get('/players/import')}
                    >
                        Upload a different file
                    </Button>
                </CardHeader>
                <CardContent className="p-0">
                    {rows.length === 0 ? (
                        <div className="px-6 py-12 text-center text-sm text-muted-foreground">
                            No data rows detected in this file.
                        </div>
                    ) : (
                        <div className="overflow-x-auto">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead className="w-12 pl-4">
                                            Row
                                        </TableHead>
                                        <TableHead className="w-8">
                                            <span className="sr-only">
                                                Status
                                            </span>
                                        </TableHead>
                                        {DISPLAY_FIELDS.map((f) => (
                                            <TableHead key={f.key}>
                                                {f.label}
                                            </TableHead>
                                        ))}
                                        <TableHead className="w-20" />
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {rows.map((row) => {
                                        const isInvalid =
                                            Object.keys(row.errors).length > 0;

                                        return (
                                            <TableRow
                                                key={row.row_number}
                                                className={cn(
                                                    isInvalid
                                                        ? 'bg-destructive/5 hover:bg-destructive/10'
                                                        : '',
                                                )}
                                            >
                                                <TableCell
                                                    data-numeric
                                                    className="pl-4 text-xs text-muted-foreground"
                                                >
                                                    {row.row_number}
                                                </TableCell>
                                                <TableCell>
                                                    {isInvalid ? (
                                                        <AlertTriangle className="size-4 text-destructive" />
                                                    ) : (
                                                        <CheckCircle2 className="size-4 text-emerald-600" />
                                                    )}
                                                </TableCell>
                                                {DISPLAY_FIELDS.map((f) => {
                                                    const value =
                                                        row.normalized[f.key];
                                                    const fieldErrors =
                                                        row.errors[f.key];

                                                    return (
                                                        <TableCell
                                                            key={f.key}
                                                            className={cn(
                                                                fieldErrors
                                                                    ? 'text-destructive'
                                                                    : '',
                                                                f.key ===
                                                                    'name_ar'
                                                                    ? 'text-right'
                                                                    : '',
                                                            )}
                                                            {...(f.key ===
                                                            'name_ar'
                                                                ? {
                                                                      lang: 'ar',
                                                                      dir: 'rtl',
                                                                  }
                                                                : {})}
                                                        >
                                                            {value ?? (
                                                                <span className="text-muted-foreground">
                                                                    —
                                                                </span>
                                                            )}
                                                            {fieldErrors && (
                                                                <div className="mt-0.5 text-[11px] font-normal">
                                                                    {
                                                                        fieldErrors[0]
                                                                    }
                                                                </div>
                                                            )}
                                                        </TableCell>
                                                    );
                                                })}
                                                <TableCell>
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() =>
                                                            removeRow(
                                                                row.row_number,
                                                            )
                                                        }
                                                    >
                                                        Skip
                                                    </Button>
                                                </TableCell>
                                            </TableRow>
                                        );
                                    })}
                                </TableBody>
                            </Table>
                        </div>
                    )}
                </CardContent>
            </Card>

            {invalidRows.length > 0 && (
                <Alert variant="destructive">
                    <AlertTriangle className="size-4" />
                    <AlertTitle>
                        {invalidRows.length}{' '}
                        {invalidRows.length === 1 ? 'row' : 'rows'} will be
                        skipped
                    </AlertTitle>
                    <AlertDescription>
                        Rows with errors won&apos;t be imported. Fix them in the
                        source file and re-upload, or hit &quot;Skip&quot; and
                        continue with the valid rows.
                    </AlertDescription>
                </Alert>
            )}

            <div className="flex items-center justify-between gap-2">
                <div className="text-sm text-muted-foreground">
                    {validRows.length === 0
                        ? 'No valid rows to import.'
                        : `Importing ${validRows.length} ${
                              validRows.length === 1 ? 'player' : 'players'
                          }.`}
                </div>
                <div className="flex gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => router.get('/players')}
                    >
                        Cancel
                    </Button>
                    <Button
                        type="submit"
                        disabled={validRows.length === 0 || form.processing}
                    >
                        {form.processing
                            ? 'Importing…'
                            : `Import ${validRows.length} players`}
                    </Button>
                </div>
            </div>
        </form>
    );
}

function SummaryStat({
    label,
    value,
    tone,
}: {
    label: string;
    value: number;
    tone: 'neutral' | 'success' | 'warn';
}) {
    return (
        <Card className="p-4">
            <div className="text-xs text-muted-foreground">{label}</div>
            <div
                data-numeric
                className={cn(
                    'mt-1 text-2xl font-semibold tabular-nums',
                    tone === 'success' && 'text-emerald-600',
                    tone === 'warn' && 'text-destructive',
                )}
            >
                {value}
            </div>
        </Card>
    );
}

PlayersImport.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: '/dashboard' },
        { title: 'Players', href: '/players' },
        { title: 'Import', href: '/players/import' },
    ],
};
