<?php

namespace App\Http\Controllers;

use App\Content\Models\ContentBlock;
use App\Enums\BlockType;
use App\Models\ContactInquiry;
use App\Notifications\Cms\ContactFormSubmissionNotification;
use App\Rules\TurnstileToken;
use App\Services\Mail\TransactionalNotificationService;
use App\Settings\GeneralSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ContactFormController extends Controller
{
    /**
     * Handle contact form submission from block
     */
    public function submit(Request $request): RedirectResponse
    {
        $request->validate([
            'block_id' => ['required', 'uuid', 'exists:content_blocks,id'],
            'website' => ['nullable', 'max:0'], // Honeypot
            'cf-turnstile-response' => [new TurnstileToken],
        ]);

        /** @var ContentBlock $block */
        $block = ContentBlock::query()->findOrFail($request->string('block_id')->toString());

        if ($block->block_type !== BlockType::ContactForm) {
            throw ValidationException::withMessages([
                'block_id' => 'Invalid contact form block.',
            ]);
        }

        $content = is_array($block->content) ? $block->content : (json_decode((string) $block->content, true) ?? []);
        $fields = $content['fields'] ?? [];
        $rules = [];

        foreach ($fields as $index => $field) {
            $name = $field['name'] ?? ("field_{$index}");
            $type = $field['type'] ?? 'text';
            $required = (bool) ($field['required'] ?? false);

            $fieldRules = $required ? ['required'] : ['nullable'];
            $fieldRules[] = 'string';
            $fieldRules[] = 'max:2000';

            if ($type === 'email') {
                $fieldRules = array_values(array_filter([...$fieldRules, 'email']));
            }

            if ($type === 'phone') {
                $fieldRules[] = 'max:30';
            }

            if ($type === 'select') {
                $options = array_filter(array_map('trim', explode(',', (string) ($field['options'] ?? ''))));
                if ($options !== []) {
                    $fieldRules[] = 'in:'.implode(',', $options);
                }
            }

            $rules[$name] = $fieldRules;
        }

        $validated = $request->validate($rules);

        $payload = [
            'block_id' => $block->id,
            'page_id' => $block->blockable_id,
            'page_slug' => $block->page?->slug,
            'page_title' => $block->page?->title,
            'submitted_at' => now()->toIso8601String(),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'fields' => $validated,
            'field_labels' => collect($fields)->mapWithKeys(function (array $field, int $index): array {
                $name = $field['name'] ?? ("field_{$index}");
                $label = $field['label'] ?? $name;

                return [$name => $label];
            })->all(),
        ];

        ContactInquiry::create([
            'type' => 'contact',
            'name' => (string) ($validated['name'] ?? $validated['full_name'] ?? ''),
            'email' => $validated['email'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'subject' => $validated['subject'] ?? null,
            'message' => $validated['message'] ?? null,
            'meta' => [
                'block_id' => $payload['block_id'],
                'page_id' => $payload['page_id'],
                'page_slug' => $payload['page_slug'],
                'page_title' => $payload['page_title'],
                'fields' => $validated,
                'field_labels' => $payload['field_labels'],
            ],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        $generalSettings = app(GeneralSettings::class);
        $recipient = $generalSettings->support_email ?: config('mail.from.address');

        if (! is_string($recipient) || $recipient === '') {
            throw new RuntimeException('Contact form recipient email is not configured.');
        }

        app(TransactionalNotificationService::class)
            ->routeMail($recipient, new ContactFormSubmissionNotification($payload));

        $successMessage = trim((string) ($content['success_message'] ?? ''));

        return $successMessage !== ''
            ? back()->with('success', $successMessage)
            : back();
    }
}
