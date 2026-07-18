{{--
    Read-only KYC status list — rows come from App\Filament\Components\InstructorDocumentViewer::rows().
    Never renders a storage/media URL, only a link into the
    authorization-gated download route.
--}}
@php($uploadedCount = collect($rows)->where('uploaded', true)->count())

<div class="mb-4 flex items-center justify-between gap-4 rounded-lg border border-gray-200 bg-gray-50 px-4 py-3 dark:border-white/10 dark:bg-white/5">
    <div>
        <p class="text-sm font-bold text-gray-950 dark:text-white">Evidence checklist</p>
        <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">Open files only when required for this review. Every download is authorization checked.</p>
    </div>
    <x-filament::badge :color="$uploadedCount === count($rows) ? 'success' : 'warning'">{{ $uploadedCount }} of {{ count($rows) }} uploaded</x-filament::badge>
</div>

<div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
    @foreach($rows as $row)
        <div class="flex min-h-20 items-center justify-between gap-3 rounded-lg border px-3 py-3 {{ $row['uploaded'] ? 'border-success-200 bg-success-50/60 dark:border-success-400/20 dark:bg-success-400/5' : 'border-gray-200 bg-gray-50 dark:border-white/10 dark:bg-white/[0.03]' }}">
            <div>
                <p class="text-sm font-medium text-gray-950 dark:text-white">{{ $row['label'] }}</p>
                <p class="text-xs {{ $row['uploaded'] ? 'text-success-600 dark:text-success-400' : 'text-gray-500 dark:text-gray-400' }}">
                    {{ $row['uploaded'] ? 'Uploaded' : 'Not uploaded' }}
                </p>
            </div>

            @if($row['download_url'])
                <a
                    href="{{ $row['download_url'] }}"
                    class="fi-link inline-flex min-h-9 items-center gap-1 rounded-md px-2 text-sm font-bold text-primary-600 hover:bg-primary-50 dark:text-primary-400 dark:hover:bg-primary-400/10"
                >
                    Download
                </a>
            @endif
        </div>
    @endforeach
</div>
