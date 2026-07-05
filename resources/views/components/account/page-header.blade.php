@props(['date' => '', 'name' => ''])

<div class="mb-10">
    <div>
        <p class="text-slate-400 text-sm mb-1">{{ $date }}</p>
        <h1 class="text-3xl sm:text-4xl font-bold text-white mb-2">
            Welcome back,
            <span class="text-grad">{{ $name }}</span>! 👋
        </h1>
        <p class="text-slate-400">Ready to continue your learning journey?</p>
    </div>
</div>
