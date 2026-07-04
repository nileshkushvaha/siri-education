{{--
    Selectable option card (booking type, subject, grade …).
    Pass Alpine bindings via attributes: @click, :aria-pressed, :class …
    Always a real <button> for keyboard + screen-reader support.
--}}
<button type="button"
        {{ $attributes->merge([
            'class' => 'group w-full rounded-2xl border-2 border-slate-200 bg-white p-4 text-left shadow-sm transition
                        hover:border-indigo-300 hover:shadow-md
                        focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-200
                        aria-pressed:border-indigo-600 aria-pressed:bg-indigo-50/60',
        ]) }}>
    {{ $slot }}
</button>
