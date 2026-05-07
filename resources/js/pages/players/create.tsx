import { Head } from '@inertiajs/react';
import { PlayerForm } from '@/components/domain/player-form';
import type { PlayerFormValues } from '@/components/domain/player-form';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

const defaults: PlayerFormValues = {
    full_name: '',
    name_ar: '',
    club: '',
    position: '',
    date_of_birth: '',
    nationality: 'Bahraini',
    height_cm: '',
    weight_kg: '',
    preferred_foot: '',
    player_code: '',
    phone: '',
    email: '',
    status: 'active',
};

export default function PlayersCreate() {
    return (
        <>
            <Head title="Add player" />
            <div className="mx-auto flex w-full max-w-3xl flex-1 flex-col gap-4 p-4">
                <header>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Add player
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Create a roster entry. Medical records, plans, and
                        sessions get attached after the player exists.
                    </p>
                </header>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">
                            Player details
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <PlayerForm mode="create" defaults={defaults} />
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

PlayersCreate.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: '/dashboard' },
        { title: 'Players', href: '/players' },
        { title: 'Add', href: '/players/create' },
    ],
};
