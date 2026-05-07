import { Head, Link, router } from '@inertiajs/react';
import { ArrowUpDown, Plus, Search, Users } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import { PlayerAvatar } from '@/components/domain/player-avatar';
import { PlayerStatusBadge } from '@/components/domain/player-status-badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { cn } from '@/lib/utils';

type PlayerRow = {
    id: number;
    full_name: string;
    name_ar: string | null;
    club: string | null;
    position: string | null;
    status: 'active' | 'inactive' | 'archived';
    player_code: string | null;
    photo_url: string | null;
    age: number | null;
};

type PageProps = {
    players: {
        data: PlayerRow[];
        meta: {
            current_page: number;
            last_page: number;
            per_page: number;
            total: number;
        };
        links: { prev: string | null; next: string | null };
    };
    filters: {
        search: string | null;
        status: 'active' | 'inactive' | 'archived' | 'all';
        sort: 'full_name' | 'club' | 'position' | 'created_at';
        direction: 'asc' | 'desc';
    };
    status_counts: { active: number; inactive: number; archived: number };
};

const STATUS_TABS: { label: string; value: PageProps['filters']['status'] }[] =
    [
        { label: 'Active', value: 'active' },
        { label: 'Inactive', value: 'inactive' },
        { label: 'Archived', value: 'archived' },
        { label: 'All', value: 'all' },
    ];

export default function PlayersIndex({
    players,
    filters,
    status_counts,
}: PageProps) {
    const [search, setSearch] = useState(filters.search ?? '');

    // Debounce search input: push to URL 250ms after the last keystroke.
    useEffect(() => {
        const current = filters.search ?? '';

        if (search === current) {
            return;
        }

        const t = window.setTimeout(() => {
            router.get(
                '/players',
                { ...filters, search: search || undefined },
                {
                    preserveState: true,
                    preserveScroll: true,
                    replace: true,
                },
            );
        }, 250);

        return () => window.clearTimeout(t);
    }, [search, filters]);

    const onSubmitSearch = (e: FormEvent<HTMLFormElement>): void => {
        e.preventDefault();
    };

    const toggleSort = (column: PageProps['filters']['sort']): void => {
        const direction =
            filters.sort === column && filters.direction === 'asc'
                ? 'desc'
                : 'asc';
        router.get(
            '/players',
            { ...filters, sort: column, direction },
            { preserveState: true, preserveScroll: true },
        );
    };

    return (
        <>
            <Head title="Players" />
            <div className="flex flex-1 flex-col gap-4 p-4">
                <header className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Players
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {players.meta.total === 0
                                ? 'Your roster is empty — add the first player to start tracking.'
                                : `${players.meta.total} ${
                                      players.meta.total === 1
                                          ? 'player'
                                          : 'players'
                                  } across the federation.`}
                        </p>
                    </div>
                    <Button asChild>
                        <Link href="/players/create">
                            <Plus className="size-4" />
                            Add player
                        </Link>
                    </Button>
                </header>

                <div className="flex flex-wrap items-center gap-2">
                    <div className="flex items-center gap-1 rounded-md border bg-card p-1 text-sm">
                        {STATUS_TABS.map((tab) => {
                            const count =
                                tab.value === 'all'
                                    ? status_counts.active +
                                      status_counts.inactive +
                                      status_counts.archived
                                    : status_counts[
                                          tab.value as keyof typeof status_counts
                                      ];
                            const active = filters.status === tab.value;

                            return (
                                <button
                                    key={tab.value}
                                    onClick={() =>
                                        router.get(
                                            '/players',
                                            {
                                                ...filters,
                                                status: tab.value,
                                            },
                                            {
                                                preserveState: true,
                                                preserveScroll: true,
                                            },
                                        )
                                    }
                                    className={cn(
                                        'flex items-center gap-2 rounded px-3 py-1.5 font-medium transition-colors duration-150',
                                        active
                                            ? 'bg-primary text-primary-foreground shadow-sm'
                                            : 'text-muted-foreground hover:bg-muted hover:text-foreground',
                                    )}
                                    type="button"
                                >
                                    {tab.label}
                                    <span
                                        data-numeric
                                        className={cn(
                                            'rounded px-1.5 text-xs tabular-nums',
                                            active
                                                ? 'bg-primary-foreground/15 text-primary-foreground'
                                                : 'bg-muted text-muted-foreground',
                                        )}
                                    >
                                        {count}
                                    </span>
                                </button>
                            );
                        })}
                    </div>

                    <form
                        onSubmit={onSubmitSearch}
                        className="relative ml-auto w-full max-w-xs"
                    >
                        <Search className="absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="Search name, club, code…"
                            className="pl-9"
                        />
                    </form>
                </div>

                <Card className="p-0">
                    {players.data.length === 0 ? (
                        <EmptyRoster hasFilters={filters.search !== null} />
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead className="pl-6">
                                        <SortButton
                                            active={
                                                filters.sort === 'full_name'
                                            }
                                            direction={filters.direction}
                                            onClick={() =>
                                                toggleSort('full_name')
                                            }
                                        >
                                            Player
                                        </SortButton>
                                    </TableHead>
                                    <TableHead>
                                        <SortButton
                                            active={filters.sort === 'club'}
                                            direction={filters.direction}
                                            onClick={() => toggleSort('club')}
                                        >
                                            Club
                                        </SortButton>
                                    </TableHead>
                                    <TableHead>
                                        <SortButton
                                            active={filters.sort === 'position'}
                                            direction={filters.direction}
                                            onClick={() =>
                                                toggleSort('position')
                                            }
                                        >
                                            Position
                                        </SortButton>
                                    </TableHead>
                                    <TableHead>Age</TableHead>
                                    <TableHead>Code</TableHead>
                                    <TableHead>Status</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {players.data.map((player) => (
                                    <TableRow
                                        key={player.id}
                                        className="cursor-pointer"
                                        onClick={() =>
                                            router.get(`/players/${player.id}`)
                                        }
                                    >
                                        <TableCell className="pl-6">
                                            <div className="flex items-center gap-3">
                                                <PlayerAvatar
                                                    name={player.full_name}
                                                    photoUrl={player.photo_url}
                                                    className="size-8"
                                                />
                                                <div>
                                                    <div className="font-medium">
                                                        {player.full_name}
                                                    </div>
                                                    {player.name_ar && (
                                                        <div
                                                            lang="ar"
                                                            dir="rtl"
                                                            className="text-xs text-muted-foreground"
                                                        >
                                                            {player.name_ar}
                                                        </div>
                                                    )}
                                                </div>
                                            </div>
                                        </TableCell>
                                        <TableCell className="text-muted-foreground">
                                            {player.club ?? '—'}
                                        </TableCell>
                                        <TableCell
                                            data-numeric
                                            className="text-muted-foreground"
                                        >
                                            {player.position ?? '—'}
                                        </TableCell>
                                        <TableCell
                                            data-numeric
                                            className="text-muted-foreground"
                                        >
                                            {player.age ?? '—'}
                                        </TableCell>
                                        <TableCell
                                            data-numeric
                                            className="text-muted-foreground"
                                        >
                                            {player.player_code ?? '—'}
                                        </TableCell>
                                        <TableCell>
                                            <PlayerStatusBadge
                                                status={player.status}
                                            />
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </Card>

                {players.meta.last_page > 1 && (
                    <div className="flex items-center justify-between text-sm">
                        <span className="text-muted-foreground">
                            Page {players.meta.current_page} of{' '}
                            {players.meta.last_page}
                        </span>
                        <div className="flex gap-2">
                            <Button
                                variant="outline"
                                size="sm"
                                disabled={!players.links.prev}
                                onClick={() =>
                                    players.links.prev &&
                                    router.get(players.links.prev)
                                }
                            >
                                Previous
                            </Button>
                            <Button
                                variant="outline"
                                size="sm"
                                disabled={!players.links.next}
                                onClick={() =>
                                    players.links.next &&
                                    router.get(players.links.next)
                                }
                            >
                                Next
                            </Button>
                        </div>
                    </div>
                )}
            </div>
        </>
    );
}

function SortButton({
    active,
    direction,
    children,
    onClick,
}: {
    active: boolean;
    direction: 'asc' | 'desc';
    children: React.ReactNode;
    onClick: () => void;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={cn(
                'inline-flex items-center gap-1 transition-colors duration-150',
                active ? 'text-foreground' : 'hover:text-foreground',
            )}
        >
            {children}
            {active && (
                <ArrowUpDown
                    className={cn(
                        'size-3',
                        direction === 'desc' && 'rotate-180',
                    )}
                />
            )}
        </button>
    );
}

function EmptyRoster({ hasFilters }: { hasFilters: boolean }) {
    return (
        <div className="flex flex-col items-center gap-3 px-6 py-16 text-center">
            <div className="grid size-10 place-items-center rounded-lg bg-muted">
                <Users className="size-5 text-muted-foreground" />
            </div>
            <h3 className="text-sm font-semibold">
                {hasFilters ? 'No matches' : 'No players yet'}
            </h3>
            <p className="max-w-sm text-sm text-muted-foreground">
                {hasFilters
                    ? 'Try a different search or switch status tabs.'
                    : 'Add the first player to start uploading records and generating analyses.'}
            </p>
            {!hasFilters && (
                <Button asChild className="mt-2">
                    <Link href="/players/create">
                        <Plus className="size-4" />
                        Add player
                    </Link>
                </Button>
            )}
        </div>
    );
}

PlayersIndex.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: '/dashboard' },
        { title: 'Players', href: '/players' },
    ],
};
