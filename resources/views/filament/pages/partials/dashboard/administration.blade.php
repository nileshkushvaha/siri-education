{{--
    Secondary administration area.

    User creation, settings, security, the activity log and login
    history are legitimate destinations but are not daily marketplace
    operations, so they sit below the business content and render at
    lower visual weight.

    Page authoring, post authoring and role creation are deliberately
    absent from the dashboard entirely — they belong to their own
    sidebar modules.
--}}
@if (count($dashboard->administrationLinks) > 0)
    <section aria-labelledby="dashboard-administration-heading" class="border-t border-gray-950/5 pt-5 dark:border-white/10">
        <h2 id="dashboard-administration-heading" class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
            Administration
        </h2>

        <div class="mt-3 flex flex-wrap gap-2">
            @foreach ($dashboard->administrationLinks as $link)
                <a
                    href="{{ $link['url'] }}"
                    title="{{ $link['description'] }}"
                    class="inline-flex items-center gap-2 rounded-lg bg-gray-50 px-3 py-2 text-xs font-medium text-gray-600 ring-1 ring-gray-950/5 transition-colors hover:bg-gray-100 hover:text-gray-950 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 motion-reduce:transition-none dark:bg-white/5 dark:text-gray-400 dark:ring-white/10 dark:hover:bg-white/10 dark:hover:text-white"
                >
                    <x-filament::icon :icon="$link['icon']" class="h-4 w-4 shrink-0" />
                    {{ $link['label'] }}
                </a>
            @endforeach
        </div>
    </section>
@endif
