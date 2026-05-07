import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';

type PlayerStatusBadgeProps = {
    status: 'active' | 'inactive' | 'archived' | string;
    className?: string;
};

export function PlayerStatusBadge({
    status,
    className,
}: PlayerStatusBadgeProps) {
    const tone: Record<string, string> = {
        active: 'bg-success/15 text-success border-success/30',
        inactive: 'bg-muted text-muted-foreground border-border',
        archived: 'bg-muted/60 text-muted-foreground border-dashed',
    };

    return (
        <Badge
            variant="outline"
            className={cn(
                'capitalize tabular-nums',
                tone[status] ?? tone.inactive,
                className,
            )}
        >
            {status}
        </Badge>
    );
}
