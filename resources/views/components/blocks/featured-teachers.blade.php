{{-- Featured Teachers Block --}}
<livewire:frontend.cms.featured-teachers
    :eyebrow="$eyebrow ?? ''"
    :title="$title ?? ''"
    :description="$description ?? ''"
    :limit="(int) ($limit ?? 4)"
    :columns="(int) ($columns ?? 4)"
    :link-label="$link_label ?? ''"
    :link-url="$link_url ?? ''"
    :section="$section ?? 'featured'"
    wire:key="featured-teachers-{{ $block_id ?? md5(($title ?? '').($limit ?? 4)) }}"
/>
