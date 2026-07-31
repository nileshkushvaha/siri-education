{{-- Contact Form Block --}}
<section class="relative py-20 overflow-hidden" data-contact-form-block>
    @php
        $contactSettings = app(\App\Settings\GeneralSettings::class);
        $contactPhone = $contactSettings->support_phone ?? null;
        $contactEmail = $contactSettings->support_email ?? null;
        $contactAddress = $contactSettings->address ?? null;
    @endphp
    <div class="contact-form-shell max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="grid grid-cols-1 lg:grid-cols-5 gap-10 items-stretch">

            {{-- ── Left info panel ──────────────────────────────────────── --}}
            <div class="contact-form-info lg:col-span-2 space-y-8">
                <span class="contact-form-orb contact-form-orb-one" aria-hidden="true"></span>
                <span class="contact-form-orb contact-form-orb-two" aria-hidden="true"></span>

                {{-- Heading --}}
                <div>
                    @if($title ?? false)
                        <h2 class="text-3xl sm:text-4xl font-bold text-slate-800 leading-tight">{{ $title }}</h2>
                    @endif
                    @if($description ?? false)
                        <p class="mt-3 text-base text-slate-500 leading-relaxed">{{ $description }}</p>
                    @endif
                </div>

                <div class="contact-info-grid">
                    @if($contactPhone)
                        <a href="tel:{{ preg_replace('/[^+0-9]/', '', $contactPhone) }}" class="contact-info-item">
                            <span class="contact-info-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 0 0 2.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 0 1-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.362-.271.527-.734.417-1.173L6.963 3.102A1.125 1.125 0 0 0 5.872 2.25H4.5A2.25 2.25 0 0 0 2.25 4.5v2.25Z"/></svg></span>
                            <span><small>Call our team</small><strong>{{ $contactPhone }}</strong></span>
                        </a>
                    @endif
                    @if($contactEmail)
                        <a href="mailto:{{ $contactEmail }}" class="contact-info-item">
                            <span class="contact-info-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M21.75 6.75v10.5A2.25 2.25 0 0 1 19.5 19.5h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0-8.659 5.771a2.25 2.25 0 0 1-2.182 0L2.25 6.75"/></svg></span>
                            <span><small>Email support</small><strong>{{ $contactEmail }}</strong></span>
                        </a>
                    @endif
                    <a href="{{ route('faqs.index') }}" class="contact-info-item">
                        <span class="contact-info-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M8.625 9.75a3.375 3.375 0 1 1 5.83 2.318c-.86.895-2.455 1.43-2.455 2.807m0 3.375h.008v.008H12v-.008Z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg></span>
                        <span><small>Quick answers</small><strong>Explore our FAQs</strong></span>
                    </a>
                    @if($contactAddress)
                        <div class="contact-info-item">
                            <span class="contact-info-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 21s7.5-5.25 7.5-12a7.5 7.5 0 1 0-15 0C4.5 15.75 12 21 12 21Z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M14.25 9a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Z"/></svg></span>
                            <span><small>Our location</small><strong>{{ $contactAddress }}</strong></span>
                        </div>
                    @endif
                </div>
            </div>

            {{-- ── Right form panel ─────────────────────────────────────── --}}
            <div class="contact-form-panel lg:col-span-3">
                <div class="relative rounded-3xl bg-white/40 backdrop-blur-xl border border-white/60 shadow-2xl shadow-indigo-200/30 p-8 sm:p-10">

                    {{-- Subtle inner highlight --}}
                    <div class="pointer-events-none absolute inset-0 rounded-3xl bg-gradient-to-br from-white/60 via-transparent to-transparent" aria-hidden="true"></div>

                    {{-- Success state --}}
                    @if(session('success'))
                    <div class="mb-6 flex items-start gap-3 rounded-2xl bg-emerald-50/80 backdrop-blur-sm border border-emerald-200/60 px-5 py-4 text-sm text-emerald-700 shadow-sm">
                        <div class="flex h-6 w-6 flex-shrink-0 items-center justify-center rounded-full bg-emerald-100 mt-0.5">
                            <svg class="h-3.5 w-3.5 text-emerald-600" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                            </svg>
                        </div>
                        <div>
                            <p class="mt-0.5 text-emerald-600">{{ session('success') }}</p>
                        </div>
                    </div>
                    @endif

                    <form method="POST" action="{{ route('contact.submit') }}" class="relative space-y-5">
                        @csrf
                        <input type="hidden" name="block_id" value="{{ $block_id ?? '' }}">
                        <input type="text" name="website" tabindex="-1" autocomplete="off" class="hidden" aria-hidden="true">

                        @php
                            $fields = $fields ?? [];
                            $inputBase = 'w-full rounded-xl bg-white/70 backdrop-blur-sm border px-4 py-3 text-sm text-slate-800 placeholder-slate-400 transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-indigo-400/50 focus:border-indigo-300 focus:bg-white/90 hover:bg-white/80';
                            $inputNormal = $inputBase . ' border-slate-200/80 shadow-sm';
                            $inputError  = $inputBase . ' border-red-300 bg-red-50/60';

                            // Group into pairs for 2-column layout (skip textarea/select)
                            $rendered = [];
                        @endphp

                        {{-- Render fields — pair text/email/tel side-by-side when consecutive --}}
                        @php $i = 0; @endphp
                        @while($i < count($fields))
                            @php
                                $field     = $fields[$i];
                                $fieldName = $field['name'] ?? ('field_' . $i);
                                $fieldType = $field['type'] ?? 'text';
                                $isInline  = in_array($fieldType, ['text', 'email', 'tel', 'phone', 'number', 'url']);
                                $nextField = $fields[$i + 1] ?? null;
                                $nextType  = $nextField['type'] ?? '';
                                $nextInline = $nextField && in_array($nextType, ['text', 'email', 'tel', 'phone', 'number', 'url']);
                                $makePair  = $isInline && $nextInline;
                            @endphp

                            @if($makePair)
                                {{-- 2-column pair --}}
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    @for($p = 0; $p < 2; $p++)
                                    @php
                                        $f         = $fields[$i + $p];
                                        $fName     = $f['name'] ?? ('field_' . ($i + $p));
                                        $fType     = $f['type'] ?? 'text';
                                        $fLabel    = $f['label'] ?? ucfirst(str_replace('_', ' ', $fName));
                                        $fRequired = (bool) ($f['required'] ?? false);
                                        $fPlaceholder = $f['placeholder'] ?? '';
                                        $fValue    = old($fName);
                                        $hasErr    = isset($errors) && $errors->has($fName);
                                    @endphp
                                    <div>
                                        <label for="{{ $fName }}" class="block text-xs font-semibold text-slate-600 uppercase tracking-wider mb-1.5">
                                            {{ $fLabel }}@if($fRequired)<span class="text-red-500 ml-0.5">*</span>@endif
                                        </label>
                                        <input
                                            id="{{ $fName }}"
                                            name="{{ $fName }}"
                                            type="{{ $fType === 'phone' ? 'tel' : $fType }}"
                                            value="{{ $fValue }}"
                                            @if($fRequired) required @endif
                                            placeholder="{{ $fPlaceholder }}"
                                            class="{{ $hasErr ? $inputError : $inputNormal }}"
                                        >
                                        @if(isset($errors))
                                            @error($fName)
                                                <p class="mt-1.5 text-xs text-red-500 flex items-center gap-1">
                                                    <svg class="h-3 w-3 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>
                                                    {{ $message }}
                                                </p>
                                            @enderror
                                        @endif
                                    </div>
                                    @endfor
                                </div>
                                @php $i += 2; @endphp
                            @else
                                {{-- Single full-width field --}}
                                @php
                                    $fLabel    = $field['label'] ?? ucfirst(str_replace('_', ' ', $fieldName));
                                    $fRequired = (bool) ($field['required'] ?? false);
                                    $fPlaceholder = $field['placeholder'] ?? '';
                                    $fValue    = old($fieldName);
                                    $fOptions  = array_filter(array_map('trim', explode(',', (string) ($field['options'] ?? ''))));
                                    $hasErr    = isset($errors) && $errors->has($fieldName);
                                @endphp
                                <div>
                                    <label for="{{ $fieldName }}" class="block text-xs font-semibold text-slate-600 uppercase tracking-wider mb-1.5">
                                        {{ $fLabel }}@if($fRequired)<span class="text-red-500 ml-0.5">*</span>@endif
                                    </label>
                                    @if($fieldType === 'textarea')
                                        <textarea
                                            id="{{ $fieldName }}"
                                            name="{{ $fieldName }}"
                                            rows="4"
                                            @if($fRequired) required @endif
                                            placeholder="{{ $fPlaceholder }}"
                                            class="{{ $hasErr ? $inputError : $inputNormal }} resize-none"
                                        >{{ $fValue }}</textarea>
                                    @elseif($fieldType === 'select')
                                        <div class="relative">
                                            <select
                                                id="{{ $fieldName }}"
                                                name="{{ $fieldName }}"
                                                @if($fRequired) required @endif
                                                class="{{ $hasErr ? $inputError : $inputNormal }} appearance-none pr-10"
                                            >
                                                <option value="">Select…</option>
                                                @foreach($fOptions as $option)
                                                    <option value="{{ $option }}" @selected($fValue === $option)>{{ $option }}</option>
                                                @endforeach
                                            </select>
                                            <div class="pointer-events-none absolute inset-y-0 right-3 flex items-center">
                                                <svg class="h-4 w-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                                                </svg>
                                            </div>
                                        </div>
                                    @else
                                        <input
                                            id="{{ $fieldName }}"
                                            name="{{ $fieldName }}"
                                            type="{{ in_array($fieldType, ['email','tel','text','phone'], true) ? ($fieldType === 'phone' ? 'tel' : $fieldType) : 'text' }}"
                                            value="{{ $fValue }}"
                                            @if($fRequired) required @endif
                                            placeholder="{{ $fPlaceholder }}"
                                            class="{{ $hasErr ? $inputError : $inputNormal }}"
                                        >
                                    @endif
                                    @if(isset($errors))
                                        @error($fieldName)
                                            <p class="mt-1.5 text-xs text-red-500 flex items-center gap-1">
                                                <svg class="h-3 w-3 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>
                                                {{ $message }}
                                            </p>
                                        @enderror
                                    @endif
                                </div>
                                @php $i++; @endphp
                            @endif
                        @endwhile

                        <x-ui.turnstile-static />

                        {{-- Submit --}}
                        @if(filled($button_text ?? null))
                        <div class="pt-1">
                            <button
                                type="submit"
                                class="group relative w-full inline-flex items-center justify-center gap-2.5 overflow-hidden rounded-2xl px-8 py-3.5 text-sm font-semibold text-white shadow-lg shadow-indigo-500/25 transition-all duration-200 hover:shadow-xl hover:shadow-indigo-500/30 hover:-translate-y-0.5 active:translate-y-0"
                                style="background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 50%, #7c3aed 100%)"
                            >
                                {{-- Shimmer --}}
                                <span class="pointer-events-none absolute inset-0 -translate-x-full group-hover:translate-x-full transition-transform duration-700 bg-gradient-to-r from-transparent via-white/15 to-transparent skew-x-12"></span>

                                <svg class="h-4 w-4 relative" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 12L3.269 3.126A59.768 59.768 0 0121.485 12 59.77 59.77 0 013.27 20.876L5.999 12zm0 0h7.5"/>
                                </svg>
                                <span class="relative">{{ $button_text }}</span>
                            </button>
                        </div>
                        @endif
                    </form>
                </div>
            </div>

        </div>
    </div>
</section>
