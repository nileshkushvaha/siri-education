@extends('layouts.frontend')

@section('title', 'Book a Session')
@section('meta_description', 'Book a free demo, 1-to-1 session, counselling, parent meeting or webinar — no account needed.')

@push('head')
    <style>[x-cloak] { display: none !important; }</style>
    @if($turnstile['enabled'])
        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit" async defer></script>
    @endif
@endpush

@section('content')
<div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-10 sm:py-14" x-data="bookingWizard({{ Illuminate\Support\Js::from($turnstile) }})">

    <header class="text-center mb-8">
        <h1 class="text-3xl sm:text-4xl font-extrabold tracking-tight text-slate-900">Book a Session</h1>
        <p class="mt-2 text-slate-500">Seven quick steps — no account needed. We'll match you with the best teacher.</p>
    </header>

    <x-booking.progress />

    {{-- Screen-reader step announcements --}}
    <p class="sr-only" aria-live="polite" x-text="announce"></p>

    <div class="mt-8 rounded-3xl bg-white p-5 shadow-xl ring-1 ring-slate-100 sm:p-8">

        <x-booking.alert retry="retry()" />

        {{-- ── Step 1: booking type ─────────────────────────────── --}}
        <x-booking.step :step="1" title="What would you like to book?" subtitle="Choose the kind of session that fits your needs.">
            <x-booking.spinner x-show="loading.types" label="Loading session types…" />
            <div x-show="!loading.types" class="grid gap-3 sm:grid-cols-2">
                <template x-for="t in types" :key="t.key">
                    <x-booking.option-card @click="chooseType(t)" ::aria-pressed="sel.type && sel.type.key === t.key ? 'true' : 'false'">
                        <span class="flex items-start justify-between gap-2">
                            <span class="font-semibold text-slate-900" x-text="t.name"></span>
                            <span class="shrink-0 rounded-full px-2.5 py-0.5 text-[11px] font-bold"
                                  :class="t.is_paid ? 'bg-amber-100 text-amber-700' : 'bg-emerald-100 text-emerald-700'"
                                  x-text="t.is_paid ? (t.currency ? t.currency + ' ' + t.price : t.price) : 'Free'"></span>
                        </span>
                        <span class="mt-1 block text-xs text-slate-500">
                            <span x-text="t.duration_minutes"></span> minutes
                            <template x-if="t.requires_approval"><span> · needs approval</span></template>
                        </span>
                        <span class="mt-1.5 block text-sm text-slate-600" x-show="t.description" x-text="t.description"></span>
                    </x-booking.option-card>
                </template>
            </div>
            <p x-show="!loading.types && !banner && types.length === 0" x-cloak class="py-10 text-center text-sm text-slate-500">
                No session types are open for booking right now — please check back soon.
            </p>
        </x-booking.step>

        {{-- ── Step 2: subject ──────────────────────────────────── --}}
        <x-booking.step :step="2" title="Choose a subject" subtitle="What would you like help with?">
            <x-booking.spinner x-show="loading.subjects" label="Loading subjects…" />
            <div x-show="!loading.subjects" class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                <template x-for="s in subjects" :key="s">
                    <x-booking.option-card @click="chooseSubject(s)" ::aria-pressed="sel.subject === s ? 'true' : 'false'" class="text-center">
                        <span class="font-semibold capitalize text-slate-900" x-text="s.replaceAll('_', ' ')"></span>
                    </x-booking.option-card>
                </template>
            </div>
            <p x-show="!loading.subjects && !banner && subjects.length === 0" x-cloak class="py-10 text-center text-sm text-slate-500">
                No subjects are available yet — please check back soon.
            </p>
        </x-booking.step>

        {{-- ── Step 3: grade ────────────────────────────────────── --}}
        <x-booking.step :step="3" title="Choose a grade" subtitle="Which grade is the student in?">
            <div class="grid grid-cols-3 gap-3 sm:grid-cols-4 md:grid-cols-6" role="group" aria-label="Grade">
                <template x-for="g in grades" :key="g">
                    <x-booking.option-card @click="chooseGrade(g)" ::aria-pressed="sel.grade === g ? 'true' : 'false'" class="text-center">
                        <span class="text-xs text-slate-400">Grade</span>
                        <span class="block text-lg font-bold text-slate-900" x-text="g"></span>
                    </x-booking.option-card>
                </template>
            </div>
        </x-booking.step>

        {{-- ── Step 4: calendar ─────────────────────────────────── --}}
        <x-booking.step :step="4" title="Pick a date" subtitle="Days with open slots are highlighted.">
            <div class="flex items-center justify-between">
                <button type="button" @click="prevMonth()" :disabled="!canPrev() || loading.dates" aria-label="Previous month"
                        class="rounded-xl border border-slate-200 p-2 text-slate-600 transition hover:bg-slate-50 disabled:opacity-40 disabled:cursor-not-allowed focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-200">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5"/></svg>
                </button>
                <p class="text-sm font-bold text-slate-900" aria-live="polite" x-text="monthLabel()"></p>
                <button type="button" @click="nextMonth()" :disabled="!canNext() || loading.dates" aria-label="Next month"
                        class="rounded-xl border border-slate-200 p-2 text-slate-600 transition hover:bg-slate-50 disabled:opacity-40 disabled:cursor-not-allowed focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-200">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5"/></svg>
                </button>
            </div>

            <x-booking.spinner x-show="loading.dates" label="Checking availability…" />

            <div x-show="!loading.dates" class="mt-4">
                <div class="grid grid-cols-7 text-center text-[11px] font-bold uppercase tracking-wide text-slate-400" aria-hidden="true">
                    <template x-for="d in ['Sun','Mon','Tue','Wed','Thu','Fri','Sat']" :key="d"><span class="py-1" x-text="d"></span></template>
                </div>
                <div class="mt-1 grid grid-cols-7 gap-1" role="group" aria-label="Choose a date">
                    <template x-for="(cell, i) in calDays()" :key="i">
                        <div class="aspect-square">
                            <button type="button" x-show="cell !== null"
                                    @click="chooseDate(cell.iso)"
                                    :disabled="!cell.available"
                                    :aria-label="cell ? cell.label + (cell.available ? ', available' : ', unavailable') : ''"
                                    :aria-pressed="cell && sel.date === cell.iso ? 'true' : 'false'"
                                    class="h-full w-full rounded-xl text-sm font-semibold transition focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-200"
                                    :class="cell && cell.available
                                        ? (sel.date === cell.iso ? 'bg-indigo-600 text-white' : 'bg-indigo-50 text-indigo-700 hover:bg-indigo-100')
                                        : 'text-slate-300 cursor-not-allowed'"
                                    x-text="cell ? cell.d : ''"></button>
                        </div>
                    </template>
                </div>
                <p x-show="dates.length === 0 && !banner" x-cloak class="mt-4 text-center text-sm text-slate-500">
                    No availability this month — try the next one.
                </p>
            </div>
        </x-booking.step>

        {{-- ── Step 5: slots ────────────────────────────────────── --}}
        <x-booking.step :step="5" title="Pick a time" subtitle="">
            <p class="text-sm text-slate-500 -mt-4 mb-5">
                <span class="font-semibold text-slate-700" x-text="prettyDate(sel.date)"></span>
                — times shown in <span class="font-medium" x-text="tz"></span>
            </p>
            <x-booking.spinner x-show="loading.slots" label="Loading time slots…" />
            <div x-show="!loading.slots" class="grid grid-cols-3 gap-3 sm:grid-cols-4" role="group" aria-label="Choose a time">
                <template x-for="s in slots" :key="s.starts_at">
                    <x-booking.option-card @click="chooseSlot(s)"
                                           ::aria-pressed="sel.slot && sel.slot.starts_at === s.starts_at ? 'true' : 'false'"
                                           class="text-center !p-3">
                        <span class="font-bold text-slate-900" x-text="slotTime(s.starts_at)"></span>
                        <span class="mt-0.5 block text-[11px] text-slate-400"
                              x-show="s.remaining_capacity !== null && s.remaining_capacity > 1"
                              x-text="s.remaining_capacity + ' seats left'"></span>
                    </x-booking.option-card>
                </template>
            </div>
            <div x-show="!loading.slots && slots.length === 0 && !banner" x-cloak class="py-8 text-center">
                <p class="text-sm text-slate-500">All slots for this date were just taken.</p>
                <button type="button" @click="goTo(4)" class="mt-2 text-sm font-semibold text-indigo-600 underline underline-offset-2 hover:text-indigo-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-300 rounded">
                    Pick another date
                </button>
            </div>
        </x-booking.step>

        {{-- ── Step 6: guest details ────────────────────────────── --}}
        <x-booking.step :step="6" title="Your details" subtitle="We'll email your confirmation and joining link.">
            {{-- Selection summary --}}
            <dl class="mb-6 grid grid-cols-2 gap-x-4 gap-y-2 rounded-2xl bg-slate-50 p-4 text-sm sm:grid-cols-4">
                <div><dt class="text-[11px] font-bold uppercase tracking-wide text-slate-400">Session</dt><dd class="font-semibold text-slate-800" x-text="sel.type?.name"></dd></div>
                <div><dt class="text-[11px] font-bold uppercase tracking-wide text-slate-400">Subject</dt><dd class="font-semibold capitalize text-slate-800" x-text="sel.subject?.replaceAll('_',' ')"></dd></div>
                <div><dt class="text-[11px] font-bold uppercase tracking-wide text-slate-400">Grade</dt><dd class="font-semibold text-slate-800" x-text="sel.grade"></dd></div>
                <div><dt class="text-[11px] font-bold uppercase tracking-wide text-slate-400">When</dt><dd class="font-semibold text-slate-800"><span x-text="prettyDate(sel.date)"></span> · <span x-text="sel.slot ? slotTime(sel.slot.starts_at) : ''"></span></dd></div>
            </dl>

            <form @submit.prevent="submit" novalidate class="space-y-4">
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-booking.field name="name" label="Full name" model="guest.name" required autocomplete="name" placeholder="Jane Doe" />
                    <x-booking.field name="email" label="Email" type="email" model="guest.email" required autocomplete="email" placeholder="jane@example.com" />
                </div>
                <x-booking.field name="phone" label="Phone (optional)" type="tel" model="guest.phone" autocomplete="tel" placeholder="+1 555 000 1111" />
                <x-booking.field name="notes" label="Anything we should know? (optional)" type="textarea" model="guest.notes" />

                {{-- Honeypot — hidden from real users, bots fill it --}}
                <div class="absolute -left-[9999px] top-auto h-px w-px overflow-hidden" aria-hidden="true">
                    <label for="guest-website">Website</label>
                    <input id="guest-website" type="text" name="website" x-model="guest.website" tabindex="-1" autocomplete="off">
                </div>

                {{-- Cloudflare Turnstile (rendered only when enabled in settings) --}}
                <div x-show="turnstile.enabled" x-cloak>
                    <div x-ref="turnstile"></div>
                    <p x-show="errors.captcha" x-cloak role="alert" class="mt-1 text-xs font-medium text-red-600" x-text="errors.captcha"></p>
                </div>

                <button type="submit" :disabled="loading.submit" :aria-busy="loading.submit ? 'true' : 'false'"
                        class="mt-2 inline-flex w-full items-center justify-center gap-2 rounded-2xl bg-indigo-600 px-6 py-3.5 text-sm font-bold text-white shadow-lg shadow-indigo-200 transition hover:bg-indigo-700 disabled:opacity-60 disabled:cursor-not-allowed focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-300 sm:w-auto">
                    <svg x-show="loading.submit" x-cloak class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/>
                    </svg>
                    <span x-text="loading.submit ? 'Booking…' : 'Confirm booking'"></span>
                </button>
            </form>
        </x-booking.step>

        {{-- ── Step 7: confirmation ─────────────────────────────── --}}
        <x-booking.step :step="7" title="" x-ref="confirmation">
            <div class="text-center" x-show="result">
                <span class="mx-auto flex h-16 w-16 items-center justify-center rounded-full"
                      :class="result?.status === 'confirmed' ? 'bg-emerald-100' : 'bg-amber-100'">
                    <svg x-show="result?.status === 'confirmed'" class="h-8 w-8 text-emerald-600" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>
                    <svg x-show="result?.status !== 'confirmed'" x-cloak class="h-8 w-8 text-amber-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
                </span>
                <h2 class="mt-4 text-2xl font-extrabold text-slate-900" tabindex="-1" id="step-title-7"
                    x-text="result?.status === 'confirmed' ? 'Booking confirmed!' : 'Booking requested!'"></h2>
                <p class="mt-1 text-sm text-slate-500"
                   x-text="result?.status === 'confirmed'
                        ? 'A confirmation email is on its way to ' + guest.email + '.'
                        : 'Your teacher needs to approve this booking — we\'ll email ' + guest.email + ' as soon as they do.'"></p>

                <dl class="mx-auto mt-6 max-w-md space-y-2 rounded-2xl bg-slate-50 p-5 text-left text-sm">
                    <div class="flex justify-between gap-4"><dt class="text-slate-500">Reference</dt><dd class="font-mono font-bold text-slate-900" x-text="result?.reference"></dd></div>
                    <div class="flex justify-between gap-4"><dt class="text-slate-500">Session</dt><dd class="font-semibold text-slate-800" x-text="result?.type?.name"></dd></div>
                    <div class="flex justify-between gap-4"><dt class="text-slate-500">When</dt><dd class="font-semibold text-slate-800"><span x-text="prettyDateTime(result?.starts_at)"></span></dd></div>
                    <div class="flex justify-between gap-4"><dt class="text-slate-500">Timezone</dt><dd class="font-semibold text-slate-800" x-text="result?.timezone"></dd></div>
                </dl>

                <div class="mx-auto mt-4 max-w-md rounded-2xl border border-amber-200 bg-amber-50 p-4 text-left">
                    <p class="text-xs font-bold uppercase tracking-wide text-amber-700">Manage code — save it now</p>
                    <p class="mt-1 text-xs text-amber-700">You'll need this code to view, cancel or reschedule. It is shown only once.</p>
                    <div class="mt-2 flex items-center gap-2">
                        <code class="block flex-1 truncate rounded-lg bg-white px-3 py-2 font-mono text-xs text-slate-700 ring-1 ring-amber-200" x-text="result?.manage_token"></code>
                        <button type="button" @click="copyToken()"
                                class="shrink-0 rounded-lg bg-amber-600 px-3 py-2 text-xs font-bold text-white transition hover:bg-amber-700 focus:outline-none focus-visible:ring-4 focus-visible:ring-amber-300"
                                x-text="copied ? 'Copied!' : 'Copy'"></button>
                    </div>
                </div>

                <a x-show="result?.manage_url" :href="result?.manage_url"
                   class="mt-6 inline-flex items-center gap-1.5 rounded-2xl bg-slate-900 px-5 py-2.5 text-sm font-bold text-white transition hover:bg-slate-700 focus:outline-none focus-visible:ring-4 focus-visible:ring-slate-300">
                    Manage this booking
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3"/></svg>
                </a>
                <p class="mt-2 text-xs text-slate-400">Bookmark the manage link — it contains your code.</p>

                <button type="button" @click="restart()"
                        class="mt-6 block mx-auto text-sm font-semibold text-indigo-600 underline underline-offset-2 hover:text-indigo-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-300 rounded">
                    Book another session
                </button>
            </div>
        </x-booking.step>

        {{-- ── Back navigation ──────────────────────────────────── --}}
        <div class="mt-8 border-t border-slate-100 pt-5" x-show="step > 1 && step < 7" x-cloak>
            <button type="button" @click="back()"
                    class="inline-flex items-center gap-1.5 text-sm font-semibold text-slate-500 transition hover:text-slate-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-300 rounded">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18"/></svg>
                Back
            </button>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
function bookingWizard(turnstile = { enabled: false, siteKey: null }) {
    return {
        API: '/api/v1/guest',
        turnstile,
        cfToken: '',
        turnstileWidgetId: null,
        steps: ['Type', 'Subject', 'Grade', 'Date', 'Time', 'Details', 'Done'],
        step: 1,
        tz: Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC',
        types: [],
        subjects: [],
        grades: Array.from({ length: 12 }, (_, i) => i + 1),
        dates: [],
        slots: [],
        cal: { year: 0, month: 0 },
        maxDate: new Date(Date.now() + 90 * 86400000),
        sel: { type: null, subject: null, grade: null, date: null, slot: null },
        guest: { name: '', email: '', phone: '', notes: '', website: '' },
        errors: {},
        banner: '',
        announce: '',
        copied: false,
        result: null,
        loading: { types: false, subjects: false, dates: false, slots: false, submit: false },
        lastAction: null,

        init() {
            const now = new Date();
            this.cal = { year: now.getFullYear(), month: now.getMonth() };
            this.fetchTypes();
        },

        // ── HTTP ────────────────────────────────────────────────
        async api(url, options = {}) {
            const res = await fetch(url, {
                headers: { 'Accept': 'application/json', 'Content-Type': 'application/json' },
                ...options,
            });
            let json = null;
            try { json = await res.json(); } catch (e) { /* empty body */ }
            return { ok: res.ok, status: res.status, json };
        },

        fail(r, fallback) {
            this.banner = r.status === 429
                ? 'Too many requests — please wait a minute and try again.'
                : ((r.json && r.json.message) || fallback);
        },

        async load(kind, url, assign) {
            this.lastAction = () => this.load(kind, url, assign);
            this.loading[kind] = true;
            this.banner = '';
            try {
                const r = await this.api(url);
                r.ok ? assign(r.json.data) : this.fail(r, 'Something went wrong loading data.');
            } catch (e) {
                this.banner = 'Could not reach the server — check your connection.';
            } finally {
                this.loading[kind] = false;
            }
        },

        retry() { this.banner = ''; if (this.lastAction) this.lastAction(); },

        // ── Step chain ──────────────────────────────────────────
        fetchTypes() { this.load('types', `${this.API}/booking-types`, d => this.types = d); },

        chooseType(t) {
            this.sel = { type: t, subject: null, grade: null, date: null, slot: null };
            this.goTo(2);
            this.load('subjects', `${this.API}/subjects?type=${encodeURIComponent(t.key)}`, d => this.subjects = d);
        },

        chooseSubject(s) { this.sel.subject = s; this.sel.grade = null; this.goTo(3); },

        chooseGrade(g) {
            this.sel.grade = g;
            this.sel.date = null;
            const now = new Date();
            this.cal = { year: now.getFullYear(), month: now.getMonth() };
            this.goTo(4);
            this.fetchDates();
        },

        chooseDate(iso) { this.sel.date = iso; this.sel.slot = null; this.goTo(5); this.fetchSlots(); },

        chooseSlot(s) { this.sel.slot = s; this.goTo(6); },

        // ── Calendar ────────────────────────────────────────────
        iso(d) {
            return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
        },

        monthLabel() {
            return new Date(this.cal.year, this.cal.month, 1)
                .toLocaleDateString(undefined, { month: 'long', year: 'numeric' });
        },

        calDays() {
            const first = new Date(this.cal.year, this.cal.month, 1);
            const count = new Date(this.cal.year, this.cal.month + 1, 0).getDate();
            const cells = Array(first.getDay()).fill(null);
            for (let d = 1; d <= count; d++) {
                const date = new Date(this.cal.year, this.cal.month, d);
                const iso = this.iso(date);
                cells.push({
                    d, iso,
                    available: this.dates.includes(iso),
                    label: date.toLocaleDateString(undefined, { weekday: 'long', month: 'long', day: 'numeric' }),
                });
            }
            return cells;
        },

        canPrev() {
            const now = new Date();
            return this.cal.year > now.getFullYear()
                || (this.cal.year === now.getFullYear() && this.cal.month > now.getMonth());
        },

        canNext() { return new Date(this.cal.year, this.cal.month + 1, 1) <= this.maxDate; },

        prevMonth() { if (!this.canPrev()) return; this.shiftMonth(-1); },
        nextMonth() { if (!this.canNext()) return; this.shiftMonth(1); },

        shiftMonth(delta) {
            const d = new Date(this.cal.year, this.cal.month + delta, 1);
            this.cal = { year: d.getFullYear(), month: d.getMonth() };
            this.fetchDates();
        },

        fetchDates() {
            const today = new Date();
            const first = new Date(this.cal.year, this.cal.month, 1);
            const last = new Date(this.cal.year, this.cal.month + 1, 0);
            const from = first < today ? today : first;
            const to = last > this.maxDate ? this.maxDate : last;
            if (from > to) { this.dates = []; return; }

            const q = new URLSearchParams({
                type: this.sel.type.key,
                subject: this.sel.subject,
                grade: this.sel.grade,
                from: this.iso(from),
                to: this.iso(to),
                timezone: this.tz,
            });
            this.load('dates', `${this.API}/availability/dates?${q}`, d => this.dates = d);
        },

        fetchSlots() {
            const q = new URLSearchParams({
                type: this.sel.type.key,
                subject: this.sel.subject,
                grade: this.sel.grade,
                date: this.sel.date,
                timezone: this.tz,
            });
            this.load('slots', `${this.API}/availability/slots?${q}`, d => this.slots = d);
        },

        // ── Formatting ──────────────────────────────────────────
        slotTime(isoString) {
            return new Date(isoString).toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });
        },

        prettyDate(iso) {
            if (!iso) return '';
            const [y, m, d] = iso.split('-').map(Number);
            return new Date(y, m - 1, d).toLocaleDateString(undefined, { weekday: 'short', month: 'long', day: 'numeric' });
        },

        prettyDateTime(isoString) {
            if (!isoString) return '';
            return new Date(isoString).toLocaleString(undefined, {
                weekday: 'short', month: 'long', day: 'numeric', hour: '2-digit', minute: '2-digit',
            });
        },

        // ── Live validation ─────────────────────────────────────
        validateField(f) {
            const v = this.guest[f];
            let e = '';
            if (f === 'name') {
                if (!v) e = 'Please enter your name.';
                else if (v.length < 2) e = 'Your name looks too short.';
                else if (v.length > 100) e = 'Name may not exceed 100 characters.';
            }
            if (f === 'email') {
                if (!v) e = 'Please enter your email.';
                else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v)) e = 'Enter a valid email address.';
            }
            if (f === 'phone' && v && !/^[+0-9 ().-]{7,30}$/.test(v)) e = 'Enter a valid phone number.';
            if (f === 'notes' && v.length > 1000) e = 'Notes may not exceed 1000 characters.';
            if (e) this.errors[f] = e; else delete this.errors[f];
            return !e;
        },

        // ── Submit ──────────────────────────────────────────────
        async submit() {
            if (this.loading.submit) return;

            const valid = ['name', 'email', 'phone', 'notes'].map(f => this.validateField(f)).every(Boolean);
            if (!valid) {
                const firstInvalid = document.querySelector('#guest-name[aria-invalid], #guest-email[aria-invalid], #guest-phone[aria-invalid], #guest-notes[aria-invalid]');
                if (firstInvalid) firstInvalid.focus();
                return;
            }

            this.loading.submit = true;
            this.banner = '';
            try {
                const r = await this.api(`${this.API}/bookings`, {
                    method: 'POST',
                    body: JSON.stringify({
                        type: this.sel.type.key,
                        subject: this.sel.subject,
                        grade: this.sel.grade,
                        starts_at: this.sel.slot.starts_at,
                        timezone: this.tz,
                        name: this.guest.name,
                        email: this.guest.email,
                        phone: this.guest.phone || null,
                        notes: this.guest.notes || null,
                        website: this.guest.website,
                        cf_turnstile_response: this.cfToken,
                    }),
                });

                if (r.status === 201) {
                    this.result = { ...r.json.data, manage_token: r.json.manage_token, manage_url: r.json.manage_url };
                    this.goTo(7);
                } else if (r.status === 422 && r.json && r.json.errors) {
                    let bannered = false;
                    for (const [field, msgs] of Object.entries(r.json.errors)) {
                        if (['name', 'email', 'phone', 'notes'].includes(field)) this.errors[field] = msgs[0];
                        else if (field === 'cf_turnstile_response') this.errors.captcha = msgs[0];
                        else if (!bannered) { this.banner = msgs[0]; bannered = true; }
                    }
                    this.resetTurnstile();
                } else {
                    this.fail(r, 'We could not complete your booking. Please try again.');
                    this.resetTurnstile();
                }
            } catch (e) {
                this.banner = 'Could not reach the server — check your connection.';
            } finally {
                this.loading.submit = false;
            }
        },

        copyToken() {
            if (!this.result) return;
            navigator.clipboard.writeText(this.result.manage_token).then(() => {
                this.copied = true;
                setTimeout(() => this.copied = false, 2000);
            });
        },

        // ── Navigation ──────────────────────────────────────────
        goTo(n) {
            this.step = n;
            this.banner = '';
            this.announce = `Step ${n} of ${this.steps.length}: ${this.steps[n - 1]}`;
            this.$nextTick(() => document.getElementById(`step-title-${n}`)?.focus());
            if (n === 6) this.renderTurnstile();
        },

        renderTurnstile() {
            if (!this.turnstile.enabled || this.turnstileWidgetId !== null) return;
            if (typeof window.turnstile === 'undefined') {
                setTimeout(() => this.renderTurnstile(), 300);
                return;
            }
            this.turnstileWidgetId = window.turnstile.render(this.$refs.turnstile, {
                sitekey: this.turnstile.siteKey,
                callback: (token) => { this.cfToken = token; delete this.errors.captcha; },
                'expired-callback': () => { this.cfToken = ''; },
            });
        },

        resetTurnstile() {
            if (this.turnstile.enabled && this.turnstileWidgetId !== null && window.turnstile) {
                window.turnstile.reset(this.turnstileWidgetId);
                this.cfToken = '';
            }
        },

        back() { if (this.step > 1) this.goTo(this.step - 1); },

        restart() {
            this.sel = { type: null, subject: null, grade: null, date: null, slot: null };
            this.guest = { name: '', email: '', phone: '', notes: '', website: '' };
            this.errors = {};
            this.result = null;
            this.slots = [];
            this.dates = [];
            this.goTo(1);
            this.fetchTypes();
        },
    };
}
</script>
@endpush
