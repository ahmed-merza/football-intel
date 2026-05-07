import { useForm } from '@inertiajs/react';
import { Trash2, UploadCloud } from 'lucide-react';
import { useRef } from 'react';
import type { FormEvent } from 'react';
import { PlayerAvatar } from '@/components/domain/player-avatar';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';

/**
 * Shape of the player row passed into `edit` mode. Matches PlayerData on the
 * server — keeping both in sync is the type-safety contract for the form.
 */
export type PlayerFormValues = {
    full_name: string;
    name_ar: string;
    club: string;
    position: string;
    date_of_birth: string;
    nationality: string;
    height_cm: string;
    weight_kg: string;
    preferred_foot: string;
    player_code: string;
    phone: string;
    email: string;
    status: 'active' | 'inactive' | 'archived';
};

type Mode =
    | { mode: 'create' }
    | { mode: 'edit'; playerId: number; currentPhotoUrl?: string | null };

type PlayerFormProps = {
    defaults: PlayerFormValues;
} & Mode;

const POSITIONS = [
    'GK',
    'CB',
    'RB',
    'LB',
    'CDM',
    'CM',
    'CAM',
    'LM',
    'RM',
    'LW',
    'RW',
    'ST',
] as const;

export function PlayerForm(props: PlayerFormProps) {
    const isEdit = props.mode === 'edit';
    const fileInput = useRef<HTMLInputElement | null>(null);

    type FormPayload = PlayerFormValues & {
        avatar: File | null;
        remove_avatar: boolean;
        _method?: 'put';
    };

    const form = useForm<FormPayload>({
        ...props.defaults,
        avatar: null,
        remove_avatar: false,
        ...(isEdit ? { _method: 'put' as const } : {}),
    });

    const { data, setData, errors, processing } = form;

    const submit = (event: FormEvent<HTMLFormElement>): void => {
        event.preventDefault();
        const url = isEdit ? `/players/${props.playerId}` : '/players';
        form.post(url, { forceFormData: true });
    };

    const onFileChange = (event: React.ChangeEvent<HTMLInputElement>): void => {
        const file = event.target.files?.[0] ?? null;
        setData('avatar', file);

        if (file) {
            setData('remove_avatar', false);
        }
    };

    const previewUrl = data.avatar
        ? URL.createObjectURL(data.avatar)
        : data.remove_avatar
          ? null
          : ((isEdit ? props.currentPhotoUrl : null) ?? null);

    return (
        <form onSubmit={submit} className="space-y-8" noValidate>
            <section className="flex flex-col gap-4 sm:flex-row sm:items-start">
                <PlayerAvatar
                    name={data.full_name || 'Player'}
                    photoUrl={previewUrl}
                    className="size-20"
                />
                <div className="space-y-2">
                    <Label>Photo</Label>
                    <p className="text-sm text-muted-foreground">
                        JPEG, PNG, or WebP. 2 MB max.
                    </p>
                    <div className="flex flex-wrap gap-2">
                        <input
                            ref={fileInput}
                            type="file"
                            accept="image/jpeg,image/png,image/webp"
                            hidden
                            onChange={onFileChange}
                        />
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() => fileInput.current?.click()}
                        >
                            <UploadCloud className="size-4" />
                            {data.avatar ? 'Replace' : 'Upload'}
                        </Button>
                        {isEdit && (props.currentPhotoUrl || data.avatar) && (
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                onClick={() => {
                                    setData('avatar', null);
                                    setData('remove_avatar', true);

                                    if (fileInput.current) {
                                        fileInput.current.value = '';
                                    }
                                }}
                            >
                                <Trash2 className="size-4" />
                                Remove
                            </Button>
                        )}
                    </div>
                    <InputError message={errors.avatar as string | undefined} />
                </div>
            </section>

            <section className="grid gap-4 sm:grid-cols-2">
                <Field label="Full name" required error={errors.full_name}>
                    <Input
                        name="full_name"
                        value={data.full_name}
                        onChange={(e) => setData('full_name', e.target.value)}
                        autoComplete="off"
                        required
                    />
                </Field>

                <Field label="Arabic name" error={errors.name_ar}>
                    <Input
                        name="name_ar"
                        lang="ar"
                        dir="rtl"
                        className="font-arabic"
                        value={data.name_ar}
                        onChange={(e) => setData('name_ar', e.target.value)}
                    />
                </Field>

                <Field label="Club" error={errors.club}>
                    <Input
                        name="club"
                        value={data.club}
                        onChange={(e) => setData('club', e.target.value)}
                    />
                </Field>

                <Field label="Position" error={errors.position}>
                    <NativeSelect
                        value={data.position}
                        onChange={(e) => setData('position', e.target.value)}
                        name="position"
                    >
                        <option value="">—</option>
                        {POSITIONS.map((p) => (
                            <option key={p} value={p}>
                                {p}
                            </option>
                        ))}
                    </NativeSelect>
                </Field>

                <Field label="Date of birth" error={errors.date_of_birth}>
                    <Input
                        name="date_of_birth"
                        type="date"
                        value={data.date_of_birth}
                        onChange={(e) =>
                            setData('date_of_birth', e.target.value)
                        }
                    />
                </Field>

                <Field label="Nationality" error={errors.nationality}>
                    <Input
                        name="nationality"
                        value={data.nationality}
                        onChange={(e) => setData('nationality', e.target.value)}
                    />
                </Field>

                <Field label="Height (cm)" error={errors.height_cm}>
                    <Input
                        name="height_cm"
                        type="number"
                        min={100}
                        max={230}
                        value={data.height_cm}
                        onChange={(e) => setData('height_cm', e.target.value)}
                        data-numeric
                    />
                </Field>

                <Field label="Weight (kg)" error={errors.weight_kg}>
                    <Input
                        name="weight_kg"
                        type="number"
                        step="0.1"
                        min={30}
                        max={200}
                        value={data.weight_kg}
                        onChange={(e) => setData('weight_kg', e.target.value)}
                        data-numeric
                    />
                </Field>

                <Field label="Preferred foot" error={errors.preferred_foot}>
                    <NativeSelect
                        value={data.preferred_foot}
                        onChange={(e) =>
                            setData('preferred_foot', e.target.value)
                        }
                        name="preferred_foot"
                    >
                        <option value="">—</option>
                        <option value="right">Right</option>
                        <option value="left">Left</option>
                        <option value="both">Both</option>
                    </NativeSelect>
                </Field>

                <Field label="Player code" error={errors.player_code}>
                    <Input
                        name="player_code"
                        value={data.player_code}
                        onChange={(e) => setData('player_code', e.target.value)}
                        data-numeric
                    />
                </Field>

                <Field label="Phone" error={errors.phone}>
                    <Input
                        name="phone"
                        type="tel"
                        inputMode="tel"
                        value={data.phone}
                        onChange={(e) => setData('phone', e.target.value)}
                        data-numeric
                    />
                </Field>

                <Field label="Email" error={errors.email}>
                    <Input
                        name="email"
                        type="email"
                        autoComplete="off"
                        value={data.email}
                        onChange={(e) => setData('email', e.target.value)}
                    />
                </Field>

                <Field label="Status" error={errors.status}>
                    <NativeSelect
                        value={data.status}
                        onChange={(e) =>
                            setData(
                                'status',
                                e.target.value as PlayerFormValues['status'],
                            )
                        }
                        name="status"
                    >
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                        <option value="archived">Archived</option>
                    </NativeSelect>
                </Field>
            </section>

            <div className="flex items-center justify-end gap-3 border-t pt-6">
                <Button type="submit" disabled={processing}>
                    {processing
                        ? 'Saving…'
                        : isEdit
                          ? 'Save changes'
                          : 'Add player'}
                </Button>
            </div>
        </form>
    );
}

function Field({
    label,
    required,
    error,
    children,
}: {
    label: string;
    required?: boolean;
    error?: string;
    children: React.ReactNode;
}) {
    return (
        <div className="grid gap-1.5">
            <Label className="text-xs font-medium text-muted-foreground">
                {label}
                {required && <span className="ml-0.5 text-destructive">*</span>}
            </Label>
            {children}
            <InputError message={error} />
        </div>
    );
}

function NativeSelect({
    className,
    ...props
}: React.SelectHTMLAttributes<HTMLSelectElement>) {
    return (
        <select
            {...props}
            className={cn(
                'flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-xs ring-offset-background transition-colors focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50',
                className,
            )}
        />
    );
}
