@extends('layouts.account')

@section('title', 'New Support Case — ' . config('app.name'))

@section('account-breadcrumbs')
    <x-account.breadcrumb :crumbs="[
        ['label' => 'Dashboard', 'url' => route('dashboard')],
        ['label' => 'Support Cases', 'url' => route('dashboard.support-cases')],
        ['label' => 'New Case'],
    ]" />
@endsection

@section('account-content')

    <div class="mb-6">
        <h1 class="text-xl font-bold text-fg-strong">New Support Case</h1>
        <p class="text-fg-muted text-sm mt-1">Tell us what's going on — you'll get a case reference to track it.</p>
    </div>

    <div class="rounded-2xl border border-edge bg-surface-raised backdrop-blur-xl p-5 sm:p-7">
        <form method="POST" action="{{ route('dashboard.support-cases.store') }}">
            @csrf

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5 mb-5">
                <div>
                    <label for="category" class="block text-xs font-semibold text-fg-muted mb-2">Category <span class="text-red-600 dark:text-red-400">*</span></label>
                    <select id="category" name="category" required
                        class="w-full min-h-11 px-4 py-3 rounded-xl bg-surface-raised border @error('category') border-red-500/50 @else border-edge @enderror text-fg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/30">
                        <option value="" disabled selected>Select a category</option>
                        @foreach(\App\SupportCases\Enums\SupportCaseCategory::cases() as $category)
                            <option value="{{ $category->value }}" {{ old('category') === $category->value ? 'selected' : '' }}>{{ $category->label() }}</option>
                        @endforeach
                    </select>
                    @error('category')<p class="mt-1.5 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="priority" class="block text-xs font-semibold text-fg-muted mb-2">Priority</label>
                    <select id="priority" name="priority"
                        class="w-full min-h-11 px-4 py-3 rounded-xl bg-surface-raised border border-edge text-fg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/30">
                        @foreach(\App\SupportCases\Enums\SupportCasePriority::cases() as $priority)
                            <option value="{{ $priority->value }}" {{ old('priority', 'medium') === $priority->value ? 'selected' : '' }}>{{ $priority->label() }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="mb-5">
                <label for="subject" class="block text-xs font-semibold text-fg-muted mb-2">Subject <span class="text-red-600 dark:text-red-400">*</span></label>
                <input type="text" id="subject" name="subject" value="{{ old('subject') }}" maxlength="255" required
                    class="w-full px-4 py-3 rounded-xl bg-surface-raised border @error('subject') border-red-500/50 @else border-edge @enderror text-fg placeholder-slate-600 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/30"
                    placeholder="Short summary of the issue">
                @error('subject')<p class="mt-1.5 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>@enderror
            </div>

            <div class="mb-6">
                <label for="description" class="block text-xs font-semibold text-fg-muted mb-2">Description <span class="text-red-600 dark:text-red-400">*</span></label>
                <textarea id="description" name="description" rows="5" maxlength="4000" required
                    class="w-full px-4 py-3 rounded-xl bg-surface-raised border @error('description') border-red-500/50 @else border-edge @enderror text-fg placeholder-slate-600 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/30"
                    placeholder="What happened, when, and anything that would help us investigate">{{ old('description') }}</textarea>
                @error('description')<p class="mt-1.5 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>@enderror
            </div>

            <div class="flex items-center gap-3">
                <button type="submit"
                    class="inline-flex min-h-11 items-center px-5 py-2.5 rounded-lg bg-indigo-500 text-sm font-semibold text-white hover:bg-indigo-400 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-300">
                    Submit Case
                </button>
                <a href="{{ route('dashboard.support-cases') }}" class="text-sm text-fg-muted hover:text-fg-strong">Cancel</a>
            </div>
        </form>
    </div>

@endsection
