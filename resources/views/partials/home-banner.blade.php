@php
$countries = ['🇮🇳 India', '🇺🇸 United States', '🇬🇧 United Kingdom', '🇨🇦 Canada', '🇦🇺 Australia', '🌐 All Regions'];
@endphp

{{-- ============================================================
     HOME HERO BANNER — included on the homepage only
     ============================================================ --}}
<div class="bg-surface-dark" x-data="{ activeCountry: '🇮🇳 India' }">

    <section class="hero-mesh flex flex-col" style="min-height: 75vh;">

        {{-- Background orbs --}}
        <div class="bg-orb w-[600px] h-[600px] top-[-200px] right-[-150px] opacity-20"
            style="background: radial-gradient(circle, #6366F1, transparent)"></div>
        <div class="bg-orb w-[400px] h-[400px] bottom-0 left-[-80px] opacity-15"
            style="background: radial-gradient(circle, #8B5CF6, transparent); animation-delay: 3s;"></div>
        <div class="bg-orb w-[250px] h-[250px] top-1/3 left-1/3 opacity-10"
            style="background: radial-gradient(circle, #F59E0B, transparent); animation-delay: 5s;"></div>

        {{-- Main Hero Content --}}
        <div class="max-w-7xl mx-auto px-4 w-full flex-1 flex items-center py-8 relative z-10">
            <div class="grid lg:grid-cols-2 gap-12 xl:gap-20 items-center w-full">

                {{-- LEFT: Copy --}}
                <div>
                    {{-- Badge --}}
                    <div class="inline-flex items-center gap-2 glass rounded-full px-4 py-2 mb-6">
                        <div class="badge-dot"></div>
                        <span class="text-gray-300 text-sm">Trusted by <strong class="text-white">10,000+</strong> Students</span>
                        <span class="text-amber-400 text-xs font-bold ml-1">⭐ 4.9 Rated</span>
                    </div>

                    {{-- Headline --}}
                    <h1 class="text-4xl sm:text-5xl xl:text-6xl font-bold text-white leading-[1.1] mb-6">
                        Top Online Private<br>
                        Tutors for<br>
                        <span class="text-grad">1-on-1 Learning</span>
                    </h1>

                    {{-- Subheadline --}}
                    <p class="text-gray-400 text-lg leading-relaxed mb-8 max-w-lg">
                        Get expert online tutoring for school subjects and competitive exams.
                        Learn at your own pace with <span class="text-white font-medium">personalised sessions</span> from verified experts.
                    </p>

                    {{-- CTAs --}}
                    <div class="flex flex-wrap gap-4 mb-10">
                        <a href="{{ route('auth.register') }}" class="btn-amber px-8 py-4 rounded-2xl text-white font-bold text-base shadow-xl">
                            Find Tutor Now 🚀
                        </a>
                        <a href="#how-it-works" class="glass-md px-8 py-4 rounded-2xl text-white font-semibold text-base hover:bg-white/15 transition-colors flex items-center gap-2">
                            <div class="w-8 h-8 bg-white/20 rounded-full flex items-center justify-center text-xs">▶</div>
                            Watch Demo
                        </a>
                    </div>

                    {{-- Mini Stats --}}
                    <div class="flex flex-wrap gap-6 items-center">
                        <div class="text-center">
                            <div class="text-2xl font-bold text-white">10K+</div>
                            <div class="text-gray-500 text-xs mt-0.5">Students</div>
                        </div>
                        <div class="w-px h-8 bg-white/15"></div>
                        <div class="text-center">
                            <div class="text-2xl font-bold text-white">200+</div>
                            <div class="text-gray-500 text-xs mt-0.5">Expert Tutors</div>
                        </div>
                        <div class="w-px h-8 bg-white/15"></div>
                        <div class="text-center">
                            <div class="text-2xl font-bold text-white">500+</div>
                            <div class="text-gray-500 text-xs mt-0.5">Courses</div>
                        </div>
                        <div class="w-px h-8 bg-white/15"></div>
                        <div class="text-center">
                            <div class="text-2xl font-bold text-white">4.9 ★</div>
                            <div class="text-gray-500 text-xs mt-0.5">Avg Rating</div>
                        </div>
                    </div>
                </div>

                {{-- RIGHT: Dashboard Mockup --}}
                <div class="hidden lg:flex items-center justify-center relative py-12">

                    {{-- Floating geometric bg shapes --}}
                    <div class="absolute w-72 h-72 rounded-full border border-white/5 top-4 left-4 float-y-slow"></div>
                    <div class="absolute w-48 h-48 rounded-full border border-indigo-500/10 bottom-8 right-8 float-y" style="animation-delay:2s"></div>

                    {{-- Main course card --}}
                    <div class="glass-light rounded-3xl p-6 w-80 shadow-2xl float-y relative z-10">
                        <div class="flex items-center gap-3 mb-5">
                            <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-indigo-500 to-violet-600 flex items-center justify-center text-2xl shadow-lg shadow-indigo-500/30">💻</div>
                            <div>
                                <div class="font-bold text-slate-900 text-sm">Data Structures & Algo</div>
                                <div class="text-slate-400 text-xs">Computer Science · Intermediate</div>
                            </div>
                        </div>

                        {{-- Progress --}}
                        <div class="mb-4">
                            <div class="flex justify-between text-xs mb-1.5">
                                <span class="text-slate-400">Course Progress</span>
                                <span class="text-indigo-600 font-bold">68%</span>
                            </div>
                            <div class="h-2 bg-slate-100 rounded-full overflow-hidden">
                                <div class="h-full w-[68%] bg-gradient-to-r from-indigo-500 to-violet-500 rounded-full shimmer-line"></div>
                            </div>
                        </div>

                        {{-- Next lesson --}}
                        <div class="glass rounded-xl p-3 mb-4" style="background:rgba(99,102,241,.06); border-color:rgba(99,102,241,.15);">
                            <div class="flex items-center gap-2">
                                <span class="text-lg">▶</span>
                                <div>
                                    <div class="text-xs text-slate-400">Next Lesson</div>
                                    <div class="text-sm font-semibold text-slate-900">Binary Trees & BST</div>
                                </div>
                            </div>
                        </div>

                        <div class="flex items-center justify-between">
                            <div class="flex -space-x-2">
                                @foreach(['🟣','🔵','🟢'] as $dot)
                                <div class="w-7 h-7 rounded-full border-2 border-white bg-gradient-to-br from-indigo-400 to-violet-400 flex items-center justify-center text-xs">{{ $loop->iteration }}</div>
                                @endforeach
                                <div class="w-7 h-7 rounded-full border-2 border-white bg-slate-100 flex items-center justify-center text-[10px] text-slate-400 font-bold">+9</div>
                            </div>
                            <button class="btn-indigo px-4 py-2 rounded-xl text-white text-xs font-bold">Continue →</button>
                        </div>
                    </div>

                    {{-- Floating Achievement Badge --}}
                    <div class="absolute -bottom-2 -left-6 glass-light rounded-2xl p-4 shadow-xl float-y" style="animation-delay:1.5s; z-index:20;">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 bg-amber-100 rounded-xl flex items-center justify-center text-xl">🏆</div>
                            <div>
                                <div class="text-[10px] text-slate-400 uppercase tracking-wide">Achievement</div>
                                <div class="font-bold text-slate-900 text-sm">7-Day Streak!</div>
                                <div class="flex gap-0.5 mt-0.5">
                                    @for($i=0;$i<7;$i++)<div class="w-3 h-1.5 bg-amber-400 rounded-full {{ $i < 5 ? '' : 'opacity-30' }}"></div>@endfor
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- Floating Tutor Badge --}}
                    <div class="absolute -top-2 -right-4 glass-light rounded-2xl p-4 shadow-xl float-y" style="animation-delay:.8s; z-index:20;">
                        <div class="flex items-center gap-3">
                            <div class="w-12 h-12 rounded-full bg-gradient-to-br from-violet-400 to-indigo-500 flex items-center justify-center text-2xl border-2 border-white shadow">👩</div>
                            <div>
                                <div class="text-[10px] text-slate-400 uppercase tracking-wide">Your Tutor</div>
                                <div class="font-bold text-slate-900 text-sm">Dr. Sarah K.</div>
                                <div class="text-amber-400 text-xs">★★★★★ <span class="text-slate-400">(128)</span></div>
                            </div>
                        </div>
                        <div class="flex items-center gap-1.5 mt-2">
                            <div class="badge-dot"></div>
                            <span class="text-[11px] text-emerald-500 font-medium">Available Now</span>
                        </div>
                    </div>

                    {{-- Floating Live Session Badge --}}
                    <div class="absolute top-1/2 -right-8 glass-light rounded-2xl px-4 py-3 shadow-xl" style="transform:translateY(40px); z-index:20;">
                        <div class="flex items-center gap-2">
                            <div class="w-2 h-2 bg-red-500 rounded-full animate-pulse"></div>
                            <span class="text-xs font-bold text-slate-900">Live Session</span>
                        </div>
                        <div class="text-[10px] text-slate-400 mt-0.5">Physics · 43 students</div>
                    </div>
                </div>

            </div>
        </div>
    </section>

    {{-- Country Selector Banner --}}
    <div class="max-w-4xl mx-auto px-4 w-full pb-16 relative z-10">
        <div class="glass-md rounded-2xl p-5 shadow-2xl shadow-indigo-500/10">
            <div class="flex items-center gap-3 mb-4">
                <div class="w-8 h-8 rounded-xl bg-indigo-500/20 border border-indigo-500/30 flex items-center justify-center flex-shrink-0">
                    <svg class="w-4 h-4 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </div>
                <div>
                    <p class="text-white text-sm font-semibold">Select Your Country</p>
                    <p class="text-gray-400 text-xs">Find tutors and courses tailored to your curriculum</p>
                </div>
                <div class="ml-auto flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-emerald-500/10 border border-emerald-500/20 flex-shrink-0">
                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse inline-block"></span>
                    <span class="text-emerald-400 text-xs font-medium" x-text="activeCountry || '🇮🇳 India'"></span>
                </div>
            </div>

            <div class="flex flex-wrap gap-2">
                @foreach($countries as $country)
                <button
                    @click="activeCountry = '{{ $country }}'"
                    :class="activeCountry === '{{ $country }}'
                            ? 'bg-indigo-500/25 border-indigo-500/60 text-indigo-300 shadow-lg shadow-indigo-500/10'
                            : 'text-gray-400 hover:text-gray-200 hover:border-white/25'"
                    class="glass px-4 py-2 rounded-xl text-sm font-medium whitespace-nowrap transition-all duration-200 border border-white/10 flex items-center gap-1.5">
                    {{ $country }}
                    <span x-show="activeCountry === '{{ $country }}'" class="w-1.5 h-1.5 rounded-full bg-indigo-400 inline-block flex-shrink-0"></span>
                </button>
                @endforeach
            </div>

            <div class="mt-4 pt-4 border-t border-white/10 flex items-center justify-between">
                <p class="text-gray-500 text-xs">
                    Showing tutors available in <span class="text-white font-medium" x-text="activeCountry || '🇮🇳 India'"></span>
                </p>
                @if(Route::has('auth.register'))
                <a href="{{ route('auth.register') }}" class="btn-amber px-5 py-2 rounded-xl text-white font-bold text-sm">Find Tutors →</a>
                @else
                <a href="{{ route('auth.register') }}" class="btn-amber px-5 py-2 rounded-xl text-white font-bold text-sm">Find Tutors →</a>
                @endif
            </div>
        </div>
    </div>

</div>{{-- /x-data activeCountry --}}
