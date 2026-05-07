import { Link, router } from '@inertiajs/react';
import { format, formatDistanceToNow, parseISO } from 'date-fns';
import {
    AlertTriangle,
    CheckCircle2,
    Info,
    Undo2,
    UserRound,
} from 'lucide-react';
import { useState } from 'react';
import { PlayerAvatar } from '@/components/domain/player-avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { cn } from '@/lib/utils';

export type AlertRow = {
    id: number;
    severity: 'critical' | 'warn' | 'info' | string;
    kind: string;
    message: string;
    created_at: string | null;
    acknowledged_at: string | null;
    acknowledged_by: string | null;
    player: {
        id: number;
        full_name: string;
        name_ar: string | null;
        photo_url: string | null;
    };
};

type AlertCardProps = {
    alert: AlertRow;
    /**
     * On the player profile we already show the player at the page level,
     * so the avatar + name on every card would be visual noise. Hide them
     * with `showPlayer={false}`.
     */
    showPlayer?: boolean;
};

const TONE: Record<string, string> = {
    critical: 'border-destructive/40 bg-destructive/5',
    warn: 'border-warning/40 bg-warning/5',
    info: 'border-border',
};

const ICON_TONE: Record<string, string> = {
    critical: 'text-destructive',
    warn: 'text-warning',
    info: 'text-muted-foreground',
};

export function AlertCard({ alert, showPlayer = true }: AlertCardProps) {
    const [pending, setPending] = useState(false);

    const acknowledge = (): void => {
        setPending(true);
        router.post(
            `/alerts/${alert.id}/acknowledge`,
            {},
            {
                preserveScroll: true,
                onFinish: () => setPending(false),
            },
        );
    };

    const unacknowledge = (): void => {
        setPending(true);
        router.delete(`/alerts/${alert.id}/acknowledge`, {
            preserveScroll: true,
            onFinish: () => setPending(false),
        });
    };

    const Icon = alert.severity === 'info' ? Info : AlertTriangle;
    const isAck = alert.acknowledged_at !== null;
    const when = alert.created_at
        ? formatDistanceToNow(parseISO(alert.created_at), { addSuffix: true })
        : '';

    return (
        <Card
            className={cn(
                'transition-opacity',
                TONE[alert.severity] ?? TONE.info,
                isAck && 'opacity-60',
            )}
        >
            <CardContent className="flex flex-col gap-3 py-4 sm:flex-row sm:items-start">
                <div className="flex shrink-0 items-start gap-3">
                    {showPlayer && (
                        <PlayerAvatar
                            name={alert.player.full_name}
                            photoUrl={alert.player.photo_url}
                            className="size-10"
                        />
                    )}
                    <Icon
                        className={cn(
                            'mt-1 size-4 shrink-0',
                            ICON_TONE[alert.severity] ?? ICON_TONE.info,
                        )}
                    />
                </div>

                <div className="min-w-0 flex-1 space-y-1.5">
                    <div className="flex flex-wrap items-center gap-2">
                        {showPlayer && (
                            <Link
                                href={`/players/${alert.player.id}`}
                                className="inline-flex items-center gap-1.5 font-medium hover:underline"
                            >
                                <UserRound className="size-3.5 text-muted-foreground" />
                                {alert.player.full_name}
                            </Link>
                        )}
                        <Badge variant="outline" className="capitalize">
                            {alert.kind.replace(/_/g, ' ')}
                        </Badge>
                        <Badge
                            variant="outline"
                            className={cn(
                                'capitalize',
                                alert.severity === 'critical' &&
                                    'border-destructive/40 text-destructive',
                                alert.severity === 'warn' &&
                                    'border-warning/40 text-warning',
                            )}
                        >
                            {alert.severity}
                        </Badge>
                        <span className="text-xs text-muted-foreground">
                            {when}
                        </span>
                    </div>
                    <p className="text-sm">{alert.message}</p>
                    {isAck && (
                        <p className="text-xs text-muted-foreground">
                            <CheckCircle2 className="mr-1 inline size-3 text-success" />
                            Acknowledged{' '}
                            {alert.acknowledged_by &&
                                `by ${alert.acknowledged_by}`}
                            {alert.acknowledged_at &&
                                ` · ${format(parseISO(alert.acknowledged_at), 'd MMM yyyy')}`}
                        </p>
                    )}
                </div>

                <div className="shrink-0">
                    {isAck ? (
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={unacknowledge}
                            disabled={pending}
                        >
                            <Undo2 className="size-4" />
                            {pending ? 'Saving…' : 'Unacknowledge'}
                        </Button>
                    ) : (
                        <Button
                            size="sm"
                            onClick={acknowledge}
                            disabled={pending}
                        >
                            <CheckCircle2 className="size-4" />
                            {pending ? 'Saving…' : 'Acknowledge'}
                        </Button>
                    )}
                </div>
            </CardContent>
        </Card>
    );
}
