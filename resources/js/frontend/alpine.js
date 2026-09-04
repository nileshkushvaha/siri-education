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

    /**
     * Student recording player (resources/views/student/recordings/watch).
     *
     * Owns two things the native <video> cannot: a viewer watermark that
     * moves on a timer (a redistribution deterrent — it identifies the
     * account a capture came from; it is not DRM and is not presented as
     * such), and fullscreen on the WRAPPER rather than the video element,
     * so the watermark stays on screen in fullscreen. Where element
     * fullscreen is unavailable (iPhone Safari) the button is hidden and
     * playback stays inline with the watermark intact.
     */
    Alpine.data('recordingPlayer', ({ platform, reference, moveSeconds = 12 }) => ({
        platform,
        reference,
        moveSeconds: Number(moveSeconds) || 0,
        clock: '',
        ready: false,
        playing: false,
        fullscreen: false,
        canFullscreen: false,
        position: 0,
        positions: [
            { top: '6%', left: '4%' },
            { top: '6%', right: '14%' },
            { bottom: '16%', right: '4%' },
            { bottom: '16%', left: '4%' },
            { top: '42%', left: '38%' },
        ],
        timers: [],

        init() {
            this.canFullscreen = Boolean(
                document.fullscreenEnabled
                || document.webkitFullscreenEnabled
                || this.$refs.wrapper.webkitRequestFullscreen,
            );

            this.tick();
            this.timers.push(setInterval(() => this.tick(), 30 * 1000));
            if (this.moveSeconds > 0) {
                this.timers.push(setInterval(() => this.move(), this.moveSeconds * 1000));
            }
        },

        destroy() {
            this.timers.forEach((t) => clearInterval(t));
        },

        tick() {
            const now = new Date();
            const pad = (n) => String(n).padStart(2, '0');
            this.clock = `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())} ${pad(now.getHours())}:${pad(now.getMinutes())}`;
        },

        move() {
            let next = Math.floor(Math.random() * this.positions.length);
            if (next === this.position) {
                next = (next + 1) % this.positions.length;
            }
            this.position = next;
        },

        watermarkStyle() {
            const p = this.positions[this.position];
            return Object.entries(p).map(([k, v]) => `${k}:${v}`).join(';');
        },

        syncFullscreen() {
            const el = document.fullscreenElement || document.webkitFullscreenElement;
            this.fullscreen = el === this.$refs.wrapper;
        },

        toggleFullscreen() {
            const wrapper = this.$refs.wrapper;

            if (this.fullscreen) {
                (document.exitFullscreen || document.webkitExitFullscreen)?.call(document);
                return;
            }

            (wrapper.requestFullscreen || wrapper.webkitRequestFullscreen)?.call(wrapper);
        },
    }));
});
