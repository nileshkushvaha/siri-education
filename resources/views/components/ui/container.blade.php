{{--
    Page-section width/gutter wrapper — <x-ui.container>...</x-ui.container>
    Centralizes the "max-w-7xl mx-auto px-4 sm:px-6 lg:px-8" pattern
    repeated ad hoc across existing views, for new pages to reuse
    instead of retyping it. Pass `as="section"` etc. via the `tag` prop
    if a non-div wrapper is needed.
--}}
@props(['tag' => 'div'])

<{{ $tag }} {{ $attributes->merge(['class' => 'max-w-7xl mx-auto px-4 sm:px-6 lg:px-8']) }}>
    {{ $slot }}
</{{ $tag }}>
