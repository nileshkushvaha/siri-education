@props(['date' => '', 'name' => ''])

<div class="relative mb-8 overflow-hidden rounded-3xl border border-white/[0.07] bg-gradient-to-r from-[rgb(var(--portal-a)/.10)] via-[rgb(var(--portal-b)/.06)] to-[rgb(var(--portal-c)/.07)] p-5 sm:p-6">
    <div class="absolute -right-10 -top-16 h-40 w-40 rounded-full bg-[rgb(var(--portal-c)/.09)] blur-3xl" aria-hidden="true"></div>
    <div class="relative">
        <p class="text-sm font-medium text-[rgb(var(--portal-a))] mb-1">{{ $date }}</p>
        <h1 class="text-3xl sm:text-4xl font-bold text-white mb-2">
            Welcome back,
            <span class="bg-gradient-to-r from-[rgb(var(--portal-a))] via-indigo-300 to-[rgb(var(--portal-c))] bg-clip-text text-transparent">{{ $name }}</span>! 👋
        </h1>
        <p class="text-slate-300">{{ ($accountAudience ?? null) === \App\Enums\PortalAudience::Instructor ? 'Your teaching workspace is ready for the day.' : 'Ready to continue your learning journey?' }}</p>
    </div>
</div>
