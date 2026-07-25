@php
    $gridColumns = [
        2 => 'md:grid-cols-2',
        3 => 'md:grid-cols-2 lg:grid-cols-3',
        4 => 'md:grid-cols-2 lg:grid-cols-4',
    ][(int) $columns] ?? 'md:grid-cols-2 lg:grid-cols-4';
@endphp

<div>
    @if($teachers->isNotEmpty() || filled($title) || filled($description) || filled($eyebrow))
        <section class="py-16 sm:py-20">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                @if(filled($eyebrow) || filled($title) || filled($description) || (filled($linkLabel) && filled($linkUrl)))
                    <div class="flex flex-col gap-6 md:flex-row md:items-end md:justify-between">
                        <div class="max-w-3xl">
                            @if(filled($eyebrow))
                                <p class="text-sm font-black uppercase tracking-[0.15em] text-indigo-600">{{ $eyebrow }}</p>
                            @endif

                            @if(filled($title))
                                <h2 class="mt-3 text-3xl font-black tracking-tight text-slate-950 sm:text-4xl">{{ $title }}</h2>
                            @endif

                            @if(filled($description))
                                <p class="mt-4 text-base leading-7 text-slate-600">{{ $description }}</p>
                            @endif
                        </div>

                        @if(filled($linkLabel) && filled($linkUrl))
                            <a href="{{ $linkUrl }}" class="inline-flex min-h-11 items-center rounded-xl border border-indigo-200 bg-white px-4 text-sm font-black text-indigo-700 shadow-sm transition hover:-translate-y-0.5 hover:bg-indigo-50 focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-100">{{ $linkLabel }} →</a>
                        @endif
                    </div>
                @endif

                @if($teachers->isNotEmpty())
                    <div class="mt-10 grid grid-cols-1 gap-5 {{ $gridColumns }}">
                        @foreach($teachers as $teacher)
                            <x-instructor.card :instructor="$teacher" />
                        @endforeach
                    </div>
                @endif
            </div>
        </section>
    @endif
</div>
