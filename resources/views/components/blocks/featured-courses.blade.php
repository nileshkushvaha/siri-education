{{-- Featured Courses Block --}}
<livewire:frontend.cms.featured-courses
    :eyebrow="$eyebrow ?? ''"
    :title="$title ?? ''"
    :description="$description ?? ''"
    :courses="$courses ?? []"
    :columns="(int) ($columns ?? 3)"
    :link-label="$link_label ?? ''"
    :link-url="$link_url ?? ''"
    wire:key="featured-courses-{{ $block_id ?? md5(json_encode($courses ?? [])) }}"
/>
