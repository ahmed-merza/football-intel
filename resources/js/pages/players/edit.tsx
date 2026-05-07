import { Head } from '@inertiajs/react';
import { PlayerForm } from '@/components/domain/player-form';
import type { PlayerFormValues } from '@/components/domain/player-form';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

type PlayerPayload = {
    id: number;
    full_name: string;
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
    photo_url: string | null;
    status: 'active' | 'inactive' | 'archived';
};

export default function PlayersEdit({ player }: { player: PlayerPayload }) {
    const defaults: PlayerFormValues = {
        full_name: player.full_name,
        name_ar: player.name_ar ?? '',
        club: player.club ?? '',
        position: player.position ?? '',
        date_of_birth: player.date_of_birth ?? '',
        nationality: player.nationality ?? '',
        height_cm: player.height_cm?.toString() ?? '',
        weight_kg: player.weight_kg?.toString() ?? '',
        preferred_foot: player.preferred_foot ?? '',
        player_code: player.player_code ?? '',
        phone: player.phone ?? '',
        email: player.email ?? '',
        status: player.status,
    };

    return (
        <>
            <Head title={`Edit ${player.full_name}`} />
            <div className="mx-auto flex w-full max-w-3xl flex-1 flex-col gap-4 p-4">
                <header>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Edit {player.full_name}
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Update roster details. Changes are audit-logged.
                    </p>
                </header>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">
                            Player details
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <PlayerForm
                            mode="edit"
                            playerId={player.id}
                            currentPhotoUrl={player.photo_url}
                            defaults={defaults}
                        />
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

PlayersEdit.layout = ({ player }: { player: PlayerPayload }) => ({
    breadcrumbs: [
        { title: 'Dashboard', href: '/dashboard' },
        { title: 'Players', href: '/players' },
        { title: player.full_name, href: `/players/${player.id}` },
        { title: 'Edit', href: `/players/${player.id}/edit` },
    ],
});
