<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for the match-report PDF upload endpoint.
 *
 * Tighter mime list than {@see StoreSubmissionRequest} on purpose — match
 * reports are always PDFs in practice, and limiting the mime types here
 * stops an admin from accidentally pointing the match-report extractor at
 * a JPG/DOCX (which the agent isn't tuned for).
 *
 * 25 MB cap fits the ~3-4 MB AGCFF PDFs comfortably with plenty of head-room
 * for higher-resolution versions of the same template.
 */
class UploadMatchReportRequest extends FormRequest
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
            'file' => [
                'required',
                'file',
                'max:25600',        // 25 MB in kilobytes
                'mimes:pdf',
            ],
        ];
    }
}
