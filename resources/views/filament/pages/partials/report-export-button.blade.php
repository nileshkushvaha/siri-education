{{-- Phase 18I — export action; hidden without export authorization (server-side re-check always runs). --}}
@if($this->canExportCsv($exportKey))
    <button
        type="button"
        wire:click="exportCsv('{{ $exportKey }}')"
        wire:loading.attr="disabled"
        class="rounded-lg px-3 py-2 text-sm font-semibold text-gray-700 ring-1 ring-gray-300 hover:bg-gray-50 disabled:opacity-50 dark:text-gray-200 dark:ring-white/20 dark:hover:bg-white/5"
    >
        <span wire:loading.remove wire:target="exportCsv">Export CSV — {{ $label }}</span>
        <span wire:loading wire:target="exportCsv">Exporting…</span>
    </button>
@endif
