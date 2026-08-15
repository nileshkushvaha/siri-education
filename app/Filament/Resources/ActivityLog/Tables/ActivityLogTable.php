<?php

declare(strict_types=1);

namespace App\Filament\Resources\ActivityLog\Tables;

use App\Enums\ActivityActorType;
use App\Filament\Support\AdminDayRange;
use App\Models\Activity;
use App\Models\User;
use App\Support\ActivityLogColors;
use App\Support\ModelDisplayName;
use App\Support\Timezone\ViewerDateTime;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

class ActivityLogTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('log_name')
                    ->label('Log')
                    ->badge()
                    ->color('gray')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('event')
                    ->label('Event')
                    ->badge()
                    ->color(fn (?string $state): string => ActivityLogColors::forEvent($state))
                    ->sortable(),

                TextColumn::make('description')
                    ->label('Description')
                    ->searchable()
                    ->limit(60)
                    ->tooltip(fn (Activity $record): string => $record->description),

                TextColumn::make('subject_type')
                    ->label('Subject')
                    ->formatStateUsing(fn (?string $state, Activity $record): string => $state
                        ? ModelDisplayName::for($state).' #'.($record->subject_id ?? '—')
                        : '—'
                    )
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('actor_type')
                    ->label('Actor')
                    ->badge()
                    ->color(fn (?ActivityActorType $state): string => $state?->color() ?? 'gray')
                    ->icon(fn (?ActivityActorType $state): string => $state?->icon() ?? 'heroicon-o-user')
                    ->formatStateUsing(fn (?ActivityActorType $state): string => $state?->label() ?? '—')
                    ->sortable(),

                TextColumn::make('actor_display')
                    ->label('By')
                    ->state(fn (Activity $record): string => $record->actorName())
                    ->description(fn (Activity $record): ?string => $record->actorEmail())
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(
                        fn (Builder $q) => $q
                            ->whereHas('causer', fn (Builder $q) => $q->where('name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%"))
                            ->orWhere('guest_name', 'like', "%{$search}%")
                            ->orWhere('guest_email', 'like', "%{$search}%")
                    )),

                TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime('M j, Y H:i')
                    ->sortable()
                    ->since()
                    ->tooltip(fn (Activity $record): string => ViewerDateTime::labelled($record->created_at, format: 'Y-m-d H:i:s')),
            ])

            ->filters([
                SelectFilter::make('actor_type')
                    ->label('Actor Type')
                    ->options(
                        collect(ActivityActorType::cases())
                            ->mapWithKeys(fn (ActivityActorType $t) => [$t->value => $t->label()])
                            ->toArray()
                    )
                    ->placeholder('All actors'),

                SelectFilter::make('event')
                    ->label('Event')
                    ->options(fn (): array => Cache::remember(
                        'activity_log_filter_events',
                        300,
                        fn (): array => Activity::query()
                            ->distinct()
                            ->whereNotNull('event')
                            ->pluck('event', 'event')
                            ->mapWithKeys(fn ($v) => [$v => ucwords(str_replace('_', ' ', $v))])
                            ->toArray()
                    ))
                    ->placeholder('All events'),

                SelectFilter::make('log_name')
                    ->label('Log Channel')
                    ->options(fn (): array => Cache::remember(
                        'activity_log_filter_channels',
                        300,
                        fn (): array => Activity::query()
                            ->distinct()
                            ->whereNotNull('log_name')
                            ->pluck('log_name', 'log_name')
                            ->mapWithKeys(fn ($v) => [$v => ucwords(str_replace('_', ' ', $v))])
                            ->toArray()
                    ))
                    ->placeholder('All channels'),

                SelectFilter::make('subject_type')
                    ->label('Subject Type')
                    ->options(fn (): array => Cache::remember(
                        'activity_log_filter_subjects',
                        300,
                        fn (): array => Activity::query()
                            ->distinct()
                            ->whereNotNull('subject_type')
                            ->pluck('subject_type', 'subject_type')
                            ->mapWithKeys(fn ($v) => [$v => ModelDisplayName::for($v)])
                            ->toArray()
                    ))
                    ->placeholder('All subjects'),

                SelectFilter::make('causer_id')
                    ->label('User')
                    ->options(fn (): array => Cache::remember(
                        'activity_log_filter_users',
                        300,
                        fn (): array => User::query()
                            ->whereIn('id', Activity::query()
                                ->where('causer_type', User::class)
                                ->whereNotNull('causer_id')
                                ->distinct()
                                ->pluck('causer_id')
                            )
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->toArray()
                    ))
                    ->searchable()
                    ->placeholder('All users'),

                Filter::make('date_range')
                    ->label('Date Range')
                    ->form([
                        DatePicker::make('from')->label('From')->native(false),
                        DatePicker::make('until')->label('Until')->native(false),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'], fn (Builder $q) => $q->where('created_at', '>=', AdminDayRange::viewerDay($data['from'])->startUtc))
                            ->when($data['until'], fn (Builder $q) => $q->where('created_at', '<', AdminDayRange::viewerDay($data['until'])->endUtcExclusive));
                    })
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];
                        if ($data['from']) {
                            $indicators[] = 'From: '.$data['from'];
                        }
                        if ($data['until']) {
                            $indicators[] = 'Until: '.$data['until'];
                        }

                        return $indicators;
                    }),
            ])

            ->recordAction('view')
            ->actions([
                ViewAction::make()->label(''),
            ])
            ->bulkActions([])
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->poll(null);
    }
}
