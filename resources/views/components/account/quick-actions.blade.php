<div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
    @foreach([
        ['My Profile',    'Update your details',    'from-violet-500 to-purple-500', 'M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z', 'profile.show'],
        ['Find a Tutor',  'Connect with experts',   'from-amber-500 to-orange-500',  'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z', '#'],
    ] as [$title, $sub, $gradient, $icon, $href])
    @php
        $url = ($href !== '#' && Route::has($href)) ? route($href) : '#';
    @endphp
    <a href="{{ $url }}"
       class="rounded-2xl border border-white/[0.07] p-5 hover:border-white/[0.12] transition-all group cursor-pointer"
       style="background:rgba(255,255,255,0.03)">
        <div class="w-10 h-10 rounded-xl bg-gradient-to-br {{ $gradient }} flex items-center justify-center mb-3 shadow-md group-hover:scale-110 transition-transform">
            <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="{{ $icon }}"/>
            </svg>
        </div>
        <p class="text-slate-200 font-semibold text-sm mb-0.5">{{ $title }}</p>
        <p class="text-slate-400 text-xs">{{ $sub }}</p>
    </a>
    @endforeach
</div>
