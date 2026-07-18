{{--
    Read-only KYC status list — rows come from App\Filament\Components\InstructorDocumentViewer::rows().
    Never renders a storage/media URL, only a link into the
    authorization-gated download route.
--}}
<div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
    @foreach($rows as $row)
        <div class="flex items-center justify-between rounded-lg border border-gray-200 px-3 py-2 dark:border-gray-700">
            <div>
                <p class="text-sm font-medium text-gray-950 dark:text-white">{{ $row['label'] }}</p>
                <p class="text-xs {{ $row['uploaded'] ? 'text-success-600 dark:text-success-400' : 'text-gray-500 dark:text-gray-400' }}">
                    {{ $row['uploaded'] ? 'Uploaded' : 'Not uploaded' }}
                </p>
            </div>

            @if($row['download_url'])
                <a
                    href="{{ $row['download_url'] }}"
                    class="fi-link inline-flex items-center gap-1 text-sm font-medium text-primary-600 hover:underline dark:text-primary-400"
                >
                    Download
                </a>
            @endif
        </div>
    @endforeach
</div>
