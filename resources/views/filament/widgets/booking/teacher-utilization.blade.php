{{--
    Teacher utilization table — data comes from BookingAnalyticsService
    (cached); no queries in the view.
--}}
@php $rows = $this->getRows(); @endphp

<x-filament-widgets::widget>
    <x-filament::section heading="Teacher utilization (last 30 days)">
        @if($rows->isEmpty())
            <p class="text-sm text-gray-500 dark:text-gray-400">No confirmed or completed sessions in this period.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            <th class="py-2 pr-4">Teacher</th>
                            <th class="py-2 pr-4 text-right">Booked (h)</th>
                            <th class="py-2 pr-4 text-right">Available (h)</th>
                            <th class="py-2 w-1/3">Utilization</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                        @foreach($rows as $row)
                            <tr>
                                <td class="py-2 pr-4 font-medium text-gray-900 dark:text-white">{{ $row['teacher'] }}</td>
                                <td class="py-2 pr-4 text-right tabular-nums">{{ number_format($row['booked_hours'], 1) }}</td>
                                <td class="py-2 pr-4 text-right tabular-nums">{{ number_format($row['available_hours'], 1) }}</td>
                                <td class="py-2">
                                    <div class="flex items-center gap-2">
                                        <div class="h-2 flex-1 rounded-full bg-gray-100 dark:bg-white/10">
                                            <div class="h-2 rounded-full {{ $row['utilization'] >= 75 ? 'bg-emerald-500' : ($row['utilization'] >= 40 ? 'bg-indigo-500' : 'bg-amber-500') }}"
                                                 style="width: {{ min(100, $row['utilization']) }}%"></div>
                                        </div>
                                        <span class="w-12 text-right text-xs tabular-nums text-gray-600 dark:text-gray-300">{{ number_format($row['utilization'], 1) }}%</span>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
