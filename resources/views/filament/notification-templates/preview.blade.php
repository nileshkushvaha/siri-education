<div class="space-y-4">
    <p class="text-sm text-gray-500 dark:text-gray-400">
        Rendered with safe, fictional sample data — never a real record.
    </p>

    <div>
        <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
            {{ $channel === 'database' ? 'Title' : 'Subject' }}
        </div>
        <div class="mt-1 text-sm font-medium">{{ $subject }}</div>
    </div>

    <div>
        <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Body</div>
        <div class="mt-1 space-y-1 text-sm">
            @foreach ($lines as $line)
                <p>{{ $line }}</p>
            @endforeach
        </div>
    </div>
</div>
