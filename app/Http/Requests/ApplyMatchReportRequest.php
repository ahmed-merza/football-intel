<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for the match-report apply step — the admin has reviewed the
 * extracted performances and submits a resolution choice for each Bahrain
 * row (existing player / create new / skip).
 *
 * `resolutions` is a map keyed by performance index (0-based, matching the
 * order in $matchReport->raw_extracted['performances']). Missing keys are
 * treated as 'skip' by the applier — defensive on the server, and lets the
 * client omit opposition rows entirely.
 *
 * Each entry's shape is conditional on type:
 *   - existing → player_id required (integer, exists in players)
 *   - new      → data.full_name required (and other optional fields)
 *   - skip     → no other fields needed
 */
class ApplyMatchReportRequest extends FormRequest
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
            'resolutions' => ['required', 'array'],
            'resolutions.*' => ['array'],
            'resolutions.*.type' => ['required', Rule::in(['existing', 'new', 'skip'])],

            // existing → player_id
            'resolutions.*.player_id' => ['nullable', 'required_if:resolutions.*.type,existing', 'integer', 'exists:players,id'],

            // new → data.{full_name + optional rest}
            'resolutions.*.data' => ['nullable', 'required_if:resolutions.*.type,new', 'array'],
            'resolutions.*.data.full_name' => ['nullable', 'required_if:resolutions.*.type,new', 'string', 'max:255'],
            'resolutions.*.data.name_ar' => ['nullable', 'string', 'max:255'],
            'resolutions.*.data.position' => ['nullable', 'string', 'max:50'],
            'resolutions.*.data.nationality' => ['nullable', 'string', 'max:100'],
        ];
    }
}
