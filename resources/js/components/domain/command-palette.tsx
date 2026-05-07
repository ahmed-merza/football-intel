import { router } from '@inertiajs/react';
import {
    BookOpenText,
    FileBarChart2,
    LayoutDashboard,
    ListChecks,
    Plus,
    Settings,
    UploadCloud,
    UserPlus,
    Users,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { PlayerAvatar } from '@/components/domain/player-avatar';
import {
    CommandDialog,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
    CommandSeparator,
    CommandShortcut,
} from '@/components/ui/command';

type PlayerMatch = {
    id: number;
    full_name: string;
    name_ar: string | null;
    club: string | null;
    position: string | null;
    photo_url: string | null;
};

type CommandPaletteProps = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

export function CommandPalette({ open, onOpenChange }: CommandPaletteProps) {
    const [query, setQuery] = useState('');
    const [players, setPlayers] = useState<PlayerMatch[]>([]);
    const [loading, setLoading] = useState(false);

    // Reset input when the dialog closes so the next open starts fresh.
    /* eslint-disable react-hooks/set-state-in-effect */
    useEffect(() => {
        if (!open) {
            setQuery('');
        }
    }, [open]);
    /* eslint-enable react-hooks/set-state-in-effect */

    /*
     * Debounced server-side search so the palette stays responsive even
     * when the roster grows. 150ms is short enough to feel instant.
     * The `set-state-in-effect` rule is disabled across this effect because
     * debounced remote state genuinely can't be derived synchronously.
     */
    /* eslint-disable react-hooks/set-state-in-effect */
    useEffect(() => {
        if (!open) {
            return;
        }

        if (query.trim() === '') {
            setPlayers([]);

            return;
        }

        const controller = new AbortController();
        const timer = window.setTimeout(async () => {
            setLoading(true);

            try {
                const res = await fetch(
                    `/players/search?q=${encodeURIComponent(query)}`,
                    {
                        signal: controller.signal,
                        headers: { Accept: 'application/json' },
                    },
                );

                if (!res.ok) {
                    return;
                }

                const json = (await res.json()) as { players: PlayerMatch[] };
                setPlayers(json.players);
            } catch (err) {
                if ((err as Error).name !== 'AbortError') {
                    console.error(err);
                }
            } finally {
                setLoading(false);
            }
        }, 150);

        return () => {
            controller.abort();
            window.clearTimeout(timer);
        };
    }, [query, open]);
    /* eslint-enable react-hooks/set-state-in-effect */

    const go = (url: string): void => {
        onOpenChange(false);
        router.visit(url);
    };

    return (
        <CommandDialog
            open={open}
            onOpenChange={onOpenChange}
            title="Command palette"
            description="Search for a player or jump to a page."
        >
            <CommandInput
                placeholder="Search players, jump to a page…"
                value={query}
                onValueChange={setQuery}
            />
            <CommandList>
                <CommandEmpty>
                    {loading ? 'Searching…' : 'No matches.'}
                </CommandEmpty>

                {players.length > 0 && (
                    <>
                        <CommandGroup heading="Players">
                            {players.map((player) => (
                                <CommandItem
                                    key={player.id}
                                    value={`player-${player.id}-${player.full_name}`}
                                    onSelect={() => go(`/players/${player.id}`)}
                                >
                                    <PlayerAvatar
                                        name={player.full_name}
                                        photoUrl={player.photo_url}
                                        className="size-6"
                                    />
                                    <div className="flex flex-col leading-tight">
                                        <span>{player.full_name}</span>
                                        <span className="text-xs text-muted-foreground">
                                            {[player.club, player.position]
                                                .filter(Boolean)
                                                .join(' · ') || '—'}
                                        </span>
                                    </div>
                                </CommandItem>
                            ))}
                        </CommandGroup>
                        <CommandSeparator />
                    </>
                )}

                <CommandGroup heading="Navigate">
                    <CommandItem onSelect={() => go('/dashboard')}>
                        <LayoutDashboard />
                        Dashboard
                        <CommandShortcut>G D</CommandShortcut>
                    </CommandItem>
                    <CommandItem onSelect={() => go('/players')}>
                        <Users />
                        Players
                        <CommandShortcut>G P</CommandShortcut>
                    </CommandItem>
                    <CommandItem onSelect={() => go('/review')}>
                        <ListChecks />
                        Review queue
                    </CommandItem>
                    <CommandItem onSelect={() => go('/knowledge')}>
                        <BookOpenText />
                        Knowledge base
                    </CommandItem>
                    <CommandItem onSelect={() => go('/reports')}>
                        <FileBarChart2 />
                        Reports
                    </CommandItem>
                    <CommandItem onSelect={() => go('/settings/profile')}>
                        <Settings />
                        Settings
                    </CommandItem>
                </CommandGroup>

                <CommandSeparator />

                <CommandGroup heading="Actions">
                    <CommandItem onSelect={() => go('/players/create')}>
                        <UserPlus />
                        Add player
                        <CommandShortcut>N P</CommandShortcut>
                    </CommandItem>
                    <CommandItem disabled>
                        <UploadCloud />
                        Upload document
                        <CommandShortcut className="text-muted-foreground/60">
                            soon
                        </CommandShortcut>
                    </CommandItem>
                    <CommandItem disabled>
                        <Plus />
                        Generate report
                        <CommandShortcut className="text-muted-foreground/60">
                            soon
                        </CommandShortcut>
                    </CommandItem>
                </CommandGroup>
            </CommandList>
        </CommandDialog>
    );
}
