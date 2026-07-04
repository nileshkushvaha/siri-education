/**
 * Extension point for the Public Frontend's Alpine data/directives/plugins.
 *
 * Alpine itself is NOT loaded here (and must never be — only one
 * instance may run per page). Livewire 4 bundles and auto-starts its
 * own Alpine (@livewireStyles/@livewireScripts in layouts/frontend.blade.php);
 * a second, separately-loaded Alpine causes "Detected multiple
 * instances of Alpine running" and breaks Livewire internals ("Alpine.
 * transaction is not a function"). This file only ever *registers
 * against* the Alpine instance Livewire already started, via the
 * `alpine:init` event, which Alpine dispatches right before it starts
 * — safe regardless of script load order.
 *
 * Add reusable Alpine.data(...)/Alpine.directive(...) registrations
 * here as new frontend components need them — do not duplicate
 * behaviour that already exists as an inline x-data on a Blade view.
 */
import collapse from '@alpinejs/collapse';

document.addEventListener('alpine:init', () => {
    Alpine.plugin(collapse);

    // Alpine.data('example', () => ({ ... }));
});
