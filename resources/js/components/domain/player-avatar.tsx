import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { cn } from '@/lib/utils';

type PlayerAvatarProps = {
    name: string;
    photoUrl?: string | null;
    className?: string;
};

function initialsFor(name: string): string {
    const parts = name.trim().split(/\s+/).filter(Boolean);

    if (parts.length === 0) {
        return '?';
    }

    if (parts.length === 1) {
        return parts[0].slice(0, 2).toUpperCase();
    }

    return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
}

export function PlayerAvatar({ name, photoUrl, className }: PlayerAvatarProps) {
    return (
        <Avatar className={cn('bg-muted', className)}>
            {photoUrl && <AvatarImage src={photoUrl} alt={name} />}
            <AvatarFallback className="text-xs font-medium text-muted-foreground">
                {initialsFor(name)}
            </AvatarFallback>
        </Avatar>
    );
}
