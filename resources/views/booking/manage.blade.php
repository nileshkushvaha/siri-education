@extends('layouts.frontend')

@section('title', 'Manage Booking')

@push('head')
    <style>[x-cloak] { display: none !important; }</style>
@endpush

@section('content')
<div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8 py-10 sm:py-14" x-data="manageBooking(@js($reference))">

    <header class="text-center mb-8">
        <h1 class="text-3xl font-extrabold tracking-tight text-slate-900">Manage Booking</h1>
        <p class="mt-2 text-slate-400" x-text="'Reference ' + reference"></p>
    </header>

    <div class="rounded-3xl bg-white p-5 shadow-xl ring-1 ring-slate-100 sm:p-8">

        <x-booking.alert />
        <p class="sr-only" aria-live="polite" x-text="announce"></p>

        <x-booking.spinner x-show="loading.booking" label="Loading your booking…" />

        {{-- Invalid or expired link --}}
        <div x-show="invalid" x-cloak class="py-10 text-center">
            <p class="text-lg font-bold text-slate-900">We couldn't find that booking.</p>
            <p class="mt-1 text-sm text-slate-400">The link may be incomplete or the manage code is wrong. Check the link from your confirmation screen.</p>
        </div>

        {{-- Booking details --}}
        <div x-show="booking && !loading.booking" x-cloak>
            <dl class="grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
                <div><dt class="text-[11px] font-bold uppercase tracking-wide text-slate-400">Status</dt>
                    <dd><span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-bold"
                              :class="{
                                  'bg-emerald-100 text-emerald-700': booking?.status === 'confirmed',
                                  'bg-amber-100 text-amber-700': booking?.status === 'pending',
                                  'bg-red-100 text-red-700': booking?.status === 'cancelled',
                                  'bg-slate-200 text-slate-400': ['completed','no_show'].includes(booking?.status),
                              }"
                              x-text="booking?.status_label"></span></dd></div>
                <div><dt class="text-[11px] font-bold uppercase tracking-wide text-slate-400">Session</dt>
                    <dd class="font-semibold text-slate-800" x-text="booking?.type?.name"></dd></div>
                <div><dt class="text-[11px] font-bold uppercase tracking-wide text-slate-400">When</dt>
                    <dd class="font-semibold text-slate-800" x-text="prettyDateTime(booking?.starts_at)"></dd></div>
                <div><dt class="text-[11px] font-bold uppercase tracking-wide text-slate-400">Timezone</dt>
                    <dd class="font-semibold text-slate-800" x-text="booking?.timezone"></dd></div>
                <div x-show="booking?.subject"><dt class="text-[11px] font-bold uppercase tracking-wide text-slate-400">Subject</dt>
                    <dd class="font-semibold capitalize text-slate-800"><span x-text="(booking?.subject || '').replaceAll('_',' ')"></span> · Grade <span x-text="booking?.grade"></span></dd></div>
                <div x-show="booking?.meeting_url"><dt class="text-[11px] font-bold uppercase tracking-wide text-slate-400">Meeting</dt>
                    <dd><a :href="booking?.meeting_url" class="font-semibold text-indigo-600 underline underline-offset-2">Join link</a></dd></div>
            </dl>

            <p x-show="booking?.cancellation" x-cloak class="mt-4 rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700">
                Cancelled<span x-show="booking?.cancellation?.reason"> — <span x-text="booking?.cancellation?.reason"></span></span>
            </p>

            {{-- Actions (only while the booking is still active) --}}
            <div x-show="isActive()" x-cloak class="mt-6 flex flex-wrap gap-3 border-t border-slate-100 pt-5">
                <button type="button" @click="panel = panel === 'reschedule' ? null : 'reschedule'"
                        class="rounded-2xl bg-indigo-600 px-5 py-2.5 text-sm font-bold text-white transition hover:bg-indigo-700 focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-300"
                        :aria-expanded="panel === 'reschedule' ? 'true' : 'false'">
                    Reschedule
                </button>
                <button type="button" @click="panel = panel === 'cancel' ? null : 'cancel'"
                        class="rounded-2xl border-2 border-red-200 px-5 py-2.5 text-sm font-bold text-red-600 transition hover:bg-red-50 focus:outline-none focus-visible:ring-4 focus-visible:ring-red-200"
                        :aria-expanded="panel === 'cancel' ? 'true' : 'false'">
                    Cancel booking
                </button>
            </div>

            {{-- Reschedule panel --}}
            <section x-show="panel === 'reschedule'" x-cloak class="mt-5 rounded-2xl bg-slate-50 p-4" aria-label="Reschedule booking">
                <label for="reschedule-date" class="block text-sm font-semibold text-slate-700">New date</label>
                <input id="reschedule-date" type="date" x-model="newDate" @change="loadSlots()"
                       :min="minDate()"
                       class="mt-1.5 rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 text-sm shadow-sm focus:border-indigo-500 focus:ring-4 focus:ring-indigo-100 focus:outline-none">

                <x-booking.spinner x-show="loading.slots" label="Loading times…" />
                <div x-show="!loading.slots && slots.length" class="mt-3 grid grid-cols-3 gap-2 sm:grid-cols-4" role="group" aria-label="Choose a new time">
                    <template x-for="s in slots" :key="s.starts_at">
                        <button type="button" @click="newSlot = s"
                                :aria-pressed="newSlot && newSlot.starts_at === s.starts_at ? 'true' : 'false'"
                                class="rounded-xl border-2 p-2 text-sm font-semibold transition focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-200"
                                :class="newSlot && newSlot.starts_at === s.starts_at ? 'border-indigo-600 bg-indigo-50 text-indigo-700' : 'border-slate-200 bg-white text-slate-700 hover:border-indigo-300'"
                                x-text="slotTime(s.starts_at)"></button>
                    </template>
                </div>
                <p x-show="!loading.slots && newDate && !slots.length" x-cloak class="mt-3 text-sm text-slate-400">No open times on that date — try another.</p>

                <button type="button" @click="reschedule()" :disabled="!newSlot || loading.action"
                        class="mt-4 rounded-2xl bg-indigo-600 px-5 py-2.5 text-sm font-bold text-white transition hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-300"
                        :aria-busy="loading.action ? 'true' : 'false'"
                        x-text="loading.action ? 'Rescheduling…' : 'Confirm new time'"></button>
            </section>

            {{-- Cancel panel --}}
            <section x-show="panel === 'cancel'" x-cloak class="mt-5 rounded-2xl bg-red-50 p-4" aria-label="Cancel booking">
                <label for="cancel-reason" class="block text-sm font-semibold text-slate-700">Reason (optional)</label>
                <textarea id="cancel-reason" rows="2" x-model.trim="cancelReason" maxlength="500"
                          class="mt-1.5 block w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 text-sm shadow-sm focus:border-red-400 focus:ring-4 focus:ring-red-100 focus:outline-none"></textarea>
                <button type="button" @click="cancel()" :disabled="loading.action"
                        class="mt-3 rounded-2xl bg-red-600 px-5 py-2.5 text-sm font-bold text-white transition hover:bg-red-700 disabled:opacity-50 focus:outline-none focus-visible:ring-4 focus-visible:ring-red-300"
                        :aria-busy="loading.action ? 'true' : 'false'"
                        x-text="loading.action ? 'Cancelling…' : 'Yes, cancel this booking'"></button>
            </section>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
function manageBooking(reference) {
    return {
        API: '/api/v1/guest',
        reference,
        token: new URLSearchParams(window.location.search).get('token') || '',
        booking: null,
        invalid: false,
        panel: null,
        banner: '',
        announce: '',
        newDate: '',
        newSlot: null,
        slots: [],
        cancelReason: '',
        loading: { booking: false, slots: false, action: false },

        init() { this.load(); },

        async api(url, options = {}) {
            const res = await fetch(url, {
                headers: { 'Accept': 'application/json', 'Content-Type': 'application/json' },
                ...options,
            });
            let json = null;
            try { json = await res.json(); } catch (e) { /* empty */ }
            return { ok: res.ok, status: res.status, json };
        },

        async load() {
            this.loading.booking = true;
            try {
                const r = await this.api(`${this.API}/bookings/${encodeURIComponent(this.reference)}?token=${encodeURIComponent(this.token)}`);
                if (r.ok) { this.booking = r.json.data; this.invalid = false; }
                else this.invalid = true;
            } catch (e) {
                this.banner = 'Could not reach the server — check your connection.';
            } finally {
                this.loading.booking = false;
            }
        },

        isActive() {
            return ['pending', 'confirmed'].includes(this.booking?.status);
        },

        minDate() {
            const d = new Date(Date.now() + 86400000);
            return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
        },

        async loadSlots() {
            if (!this.newDate || !this.booking) return;
            this.newSlot = null;
            this.loading.slots = true;
            this.banner = '';
            try {
                const q = new URLSearchParams({
                    type: this.booking.type.key,
                    subject: this.booking.subject || '',
                    grade: this.booking.grade || '',
                    date: this.newDate,
                    timezone: this.booking.timezone,
                });
                const r = await this.api(`${this.API}/availability/slots?${q}`);
                this.slots = r.ok ? r.json.data : [];
                if (!r.ok) this.banner = (r.json && r.json.message) || 'Could not load times for that date.';
            } finally {
                this.loading.slots = false;
            }
        },

        slotTime(iso) {
            return new Date(iso).toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });
        },

        prettyDateTime(iso) {
            if (!iso) return '';
            return new Date(iso).toLocaleString(undefined, { weekday: 'short', month: 'long', day: 'numeric', hour: '2-digit', minute: '2-digit' });
        },

        async reschedule() {
            if (!this.newSlot || this.loading.action) return;
            await this.act(`/bookings/${encodeURIComponent(this.reference)}/reschedule`, {
                token: this.token,
                starts_at: this.newSlot.starts_at,
                timezone: this.booking.timezone,
            }, 'Booking rescheduled.');
        },

        async cancel() {
            if (this.loading.action) return;
            await this.act(`/bookings/${encodeURIComponent(this.reference)}/cancel`, {
                token: this.token,
                reason: this.cancelReason || null,
            }, 'Booking cancelled.');
        },

        async act(path, payload, successMessage) {
            this.loading.action = true;
            this.banner = '';
            try {
                const r = await this.api(`${this.API}${path}`, { method: 'POST', body: JSON.stringify(payload) });
                if (r.ok) {
                    this.booking = r.json.data;
                    this.panel = null;
                    this.announce = successMessage;
                } else if (r.status === 429) {
                    this.banner = 'Too many requests — please wait a minute and try again.';
                } else {
                    this.banner = (r.json && r.json.message) || 'That did not work — please try again.';
                }
            } catch (e) {
                this.banner = 'Could not reach the server — check your connection.';
            } finally {
                this.loading.action = false;
            }
        },
    };
}
</script>
@endpush
