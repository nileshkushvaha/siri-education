{{-- Newsletter Block --}}
<livewire:frontend.cms.newsletter
    :eyebrow="$eyebrow ?? ''"
    :title="$title ?? ''"
    :description="$description ?? ''"
    :name-label="$name_label ?? ''"
    :name-placeholder="$name_placeholder ?? ''"
    :email-label="$email_label ?? ''"
    :email-placeholder="$email_placeholder ?? ''"
    :button-text="$button_text ?? ''"
    :consent-text="$consent_text ?? ''"
    :success-message="$success_message ?? ''"
    wire:key="newsletter-{{ $block_id ?? md5(($title ?? '').($button_text ?? '')) }}"
/>
