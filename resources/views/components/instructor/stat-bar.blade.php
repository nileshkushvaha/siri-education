@props(['stats'])

<div class="grid grid-cols-3 gap-4">
    <div class="rounded-2xl border border-edge p-4 text-center" style="background:rgba(255,255,255,0.03)">
        <p class="text-2xl font-bold text-fg-strong">
            {{ $stats['years_experience'] > 0 ? $stats['years_experience'].'y' : '—' }}
        </p>
        <p class="text-fg-muted text-xs mt-1">Years Experience</p>
    </div>
    <div class="rounded-2xl border border-edge p-4 text-center" style="background:rgba(255,255,255,0.03)">
        <p class="text-2xl font-bold text-fg-strong">{{ $stats['students_count'] }}</p>
        <p class="text-fg-muted text-xs mt-1">Students</p>
    </div>
    <div class="rounded-2xl border border-edge p-4 text-center" style="background:rgba(255,255,255,0.03)">
        <p class="text-2xl font-bold text-fg-strong">
            {{ $stats['avg_rating'] !== null ? number_format($stats['avg_rating'], 1) : 'N/A' }}
        </p>
        <p class="text-fg-muted text-xs mt-1">Avg Rating</p>
    </div>
</div>
