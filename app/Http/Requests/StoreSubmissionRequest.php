<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\RecordCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSubmissionRequest extends FormRequest
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
        $categorySlugs = [
            RecordCategory::BLOOD_TEST,
            RecordCategory::INBODY,
            RecordCategory::GPS_WEARABLE,
            RecordCategory::NUTRITION_PLAN,
            RecordCategory::HYDRATION_SUPPLEMENT_PLAN,
            RecordCategory::COACH_FEEDBACK,
            RecordCategory::MATCH_ACTIVITY,
            RecordCategory::OTHER,
        ];

        return [
            'files' => ['required', 'array', 'min:1', 'max:10'],
            'files.*' => [
                'required', 'file',
                // 15 MB per file. Phone photos of lab reports and 30-page
                // PDFs both comfortably fit. Tighten if it becomes a problem.
                'max:15360',
                'mimes:pdf,jpg,jpeg,png,webp,heic,heif,docx,xlsx,csv',
            ],
            'category_hint' => ['nullable', Rule::in($categorySlugs)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
