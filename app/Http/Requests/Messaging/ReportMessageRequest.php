<?php

declare(strict_types=1);

namespace App\Http\Requests\Messaging;

use App\Messaging\Enums\MessageReportReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class ReportMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', new Enum(MessageReportReason::class)],
            'details' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
