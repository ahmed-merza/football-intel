<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Player;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePlayerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:255'],
            'name_ar' => ['nullable', 'string', 'max:255'],
            'club' => ['nullable', 'string', 'max:255'],
            'position' => ['nullable', 'string', 'max:50'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'nationality' => ['nullable', 'string', 'max:100'],
            'height_cm' => ['nullable', 'integer', 'between:100,230'],
            'weight_kg' => ['nullable', 'numeric', 'between:30,200'],
            'preferred_foot' => ['nullable', Rule::in(['right', 'left', 'both'])],
            'player_code' => [
                'nullable', 'string', 'max:50',
                Rule::unique('players', 'player_code')->whereNull('deleted_at'),
            ],
            'phone' => [
                'nullable', 'string', 'max:32',
                Rule::unique('players', 'phone')->whereNull('deleted_at'),
            ],
            'email' => [
                'nullable', 'email', 'max:255',
                Rule::unique('players', 'email')->whereNull('deleted_at'),
            ],
            'status' => ['nullable', Rule::in([
                Player::STATUS_ACTIVE, Player::STATUS_INACTIVE, Player::STATUS_ARCHIVED,
            ])],
            'avatar' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:2048'],
        ];
    }
}
