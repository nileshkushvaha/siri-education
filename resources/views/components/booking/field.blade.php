{{--
    Validated form field bound to the wizard's `guest` + `errors` state.
    Props: name, label, type, model (Alpine expression), required,
    autocomplete, placeholder. Validates live on blur via validateField().
--}}
@props([
    'name',
    'label',
    'model',
    'type' => 'text',
    'required' => false,
    'autocomplete' => null,
    'placeholder' => null,
])

<div>
    <label for="guest-{{ $name }}" class="block text-sm font-semibold text-slate-700">
        {{ $label }}
        @if($required)<span class="text-red-500" aria-hidden="true">*</span>@endif
    </label>

    @if($type === 'textarea')
        <textarea id="guest-{{ $name }}" name="{{ $name }}" rows="3"
                  x-model.trim="{{ $model }}"
                  @blur="validateField('{{ $name }}')"
                  @input="if (errors.{{ $name }}) validateField('{{ $name }}')"
                  @if($placeholder) placeholder="{{ $placeholder }}" @endif
                  :aria-invalid="errors.{{ $name }} ? 'true' : null"
                  aria-describedby="error-{{ $name }}"
                  class="mt-1.5 block w-full rounded-xl border-slate-300 border bg-white px-3.5 py-2.5 text-sm shadow-sm
                         focus:border-indigo-500 focus:ring-4 focus:ring-indigo-100 focus:outline-none"
                  :class="errors.{{ $name }} && 'border-red-400 focus:border-red-500 focus:ring-red-100'"></textarea>
    @else
        <input id="guest-{{ $name }}" name="{{ $name }}" type="{{ $type }}"
               x-model.trim="{{ $model }}"
               @blur="validateField('{{ $name }}')"
               @input="if (errors.{{ $name }}) validateField('{{ $name }}')"
               @if($required) required @endif
               @if($autocomplete) autocomplete="{{ $autocomplete }}" @endif
               @if($placeholder) placeholder="{{ $placeholder }}" @endif
               :aria-invalid="errors.{{ $name }} ? 'true' : null"
               aria-describedby="error-{{ $name }}"
               class="mt-1.5 block w-full rounded-xl border-slate-300 border bg-white px-3.5 py-2.5 text-sm shadow-sm
                      focus:border-indigo-500 focus:ring-4 focus:ring-indigo-100 focus:outline-none"
               :class="errors.{{ $name }} && 'border-red-400 focus:border-red-500 focus:ring-red-100'">
    @endif

    <p id="error-{{ $name }}" x-show="errors.{{ $name }}" x-cloak role="alert"
       class="mt-1 text-xs font-medium text-red-600" x-text="errors.{{ $name }}"></p>
</div>
