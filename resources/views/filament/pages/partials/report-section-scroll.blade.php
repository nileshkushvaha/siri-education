{{--
    Scrolls to the section a dashboard card linked to.

    Runs after Livewire has rendered (`$nextTick`) because the property
    is resolved server-side, and honours the visitor's reduced-motion
    preference rather than always animating.

    A missing element is a no-op: `activeSection()` has already been
    validated against the page's allow-list, so this only ever targets a
    section the page really renders.
--}}
@if ($this->activeSection() !== null)
    <div
        wire:key="section-scroll-{{ $this->activeSection() }}"
        x-data
        x-init="$nextTick(() => {
            const target = document.getElementById('section-' + @js($this->activeSection()));

            if (! target) {
                return;
            }

            target.scrollIntoView({
                behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
                block: 'start',
            });
        })"
    ></div>
@endif
