<?php

declare(strict_types=1);

namespace App\Data;

use App\Models\Player;
use Illuminate\Support\Facades\Storage;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

/**
 * Inertia-facing player payload. Shapes the row that every player page, the
 * roster table, and the command palette all consume — one DTO so a frontend
 * typecheck catches shape drift on day one.
 */
class PlayerData extends Data
{
    public function __construct(
        public int $id,
        public string $full_name,
        public ?string $name_ar,
        public ?string $club,
        public ?string $position,
        public ?string $date_of_birth,
        public ?string $nationality,
        public ?int $height_cm,
        public ?float $weight_kg,
        public ?string $preferred_foot,
        public ?string $player_code,
        public ?string $phone,
        public ?string $email,
        public ?string $photo_url,
        public string $status,
        public ?int $age,
        public ?float $bmi,
        public Optional|string $created_at,
        public Optional|string $updated_at,
    ) {}

    public static function fromModel(Player $player): self
    {
        return new self(
            id: $player->id,
            full_name: $player->full_name,
            name_ar: $player->name_ar,
            club: $player->club,
            position: $player->position,
            date_of_birth: $player->date_of_birth?->toDateString(),
            nationality: $player->nationality,
            height_cm: $player->height_cm,
            weight_kg: $player->weight_kg !== null ? (float) $player->weight_kg : null,
            preferred_foot: $player->preferred_foot,
            player_code: $player->player_code,
            phone: $player->phone,
            email: $player->email,
            photo_url: $player->photo_path !== null
                ? Storage::disk('public')->url($player->photo_path)
                : null,
            status: $player->status,
            age: $player->age,
            bmi: $player->bmi,
            created_at: $player->created_at?->toIso8601String() ?? new Optional,
            updated_at: $player->updated_at?->toIso8601String() ?? new Optional,
        );
    }
}
