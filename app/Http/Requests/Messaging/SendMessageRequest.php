<?php

declare(strict_types=1);

namespace App\Http\Requests\Messaging;

use Illuminate\Foundation\Http\FormRequest;

/** SRS §17.34: PDF/image attachments only, small size cap. */
class SendMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:2000'],
            'attachment' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:5120'],
            // Set by the "Send anyway" button after the contact-sharing
            // warning has been shown. Never trusted for anything but
            // suppressing a repeat of that same warning — it can only
            // ever cause a message to send, which the user could do
            // regardless, so it grants nothing.
            'acknowledged_safety_warning' => ['nullable', 'boolean'],
        ];
    }

    public function acknowledgedSafetyWarning(): bool
    {
        return $this->boolean('acknowledged_safety_warning');
    }
}
