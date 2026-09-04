<?php

declare(strict_types=1);

namespace App\Filament\Resources\LoginHistory\Tables;

use App\Filament\Support\AdminDayRange;
use App\Filament\Support\Tables\AdminListTable;
use App\Models\LoginHistory;
use App\Models\User;
use App\Support\LoginHistoryColors;
use App\Support\Timezone\ViewerDateTime;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class LoginHistoryTable
{
    public static function configure(Table $table): Table
    {
        $table
            ->defaultSort('logged_in_at', 'desc')
            ->columns([
                TextColumn::make('user.name')
                    ->label('User')
                    ->searchable()
                    ->sortable()
                    ->description(fn (LoginHistory $record): ?string => $record->user?->email),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => LoginHistoryColors::forStatus($state))
                    ->formatStateUsing(fn (string $state): string => ucfirst($state)),

                TextColumn::make('login_method')
                    ->label('Method')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (?string $state): string => $state ? ucfirst(str_replace('_', ' ', $state)) : '—')
                    ->toggleable(),

                TextColumn::make('ip_address')
                    ->label('IP Address')
                    ->searchable()
                    ->copyable()
                    ->sortable(),

                TextColumn::make('browser')
                    ->label('Browser')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('platform')
                    ->label('OS')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('device_type')
                    ->label('Device')
                    ->badge()
                    ->color('gray')
                    ->toggleable(),

                TextColumn::make('logged_in_at')
                    ->label('Login')
                    ->dateTime('M j, Y H:i')
                    ->sortable()
                    ->since()
                    ->tooltip(fn (LoginHistory $record): string => ViewerDateTime::labelled($record->logged_in_at, format: 'Y-m-d H:i:s') ?? ''),

                TextColumn::make('logged_out_at')
                    ->label('Logout')
                    ->state(fn (LoginHistory $record): string => $record->logged_out_at
                        ? ViewerDateTime::dateTime($record->logged_out_at)
                        : '—'
                    )
                    ->sortable()
                    ->toggleable(),
            ])

            ->filters([
                SelectFilter::make('user_id')
                    ->label('User')
                    ->options(fn (): array => User::query()
                        ->whereIn('id', LoginHistory::query()->distinct()->whereNotNull('user_id')->pluck('user_id'))
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->toArray()
                    )
                    ->searchable()
                    ->placeholder('All users'),

                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'success' => 'Success',
                        'failed' => 'Failed',
                        'locked' => 'Locked',
                        'blocked' => 'Blocked',
                        'unverified' => 'Unverified',
                    ])
                    ->placeholder('All statuses'),

                SelectFilter::make('browser')
                    ->label('Browser')
                    ->options(fn (): array => LoginHistory::query()
                        ->distinct()
                        ->whereNotNull('browser')
                        ->pluck('browser', 'browser')
                        ->toArray()
                    )
                    ->placeholder('All browsers'),

                SelectFilter::make('device_type')
                    ->label('Device')
                    ->options(fn (): array => LoginHistory::query()
                        ->distinct()
                        ->whereNotNull('device_type')
                        ->pluck('device_type', 'device_type')
                        ->toArray()
                    )
                    ->placeholder('All devices'),

                Filter::make('ip_address')
                    ->label('IP Address')
                    ->form([
                        TextInput::make('ip')
                            ->label('IP Address')
                            ->placeholder('e.g. 192.168.1.1'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['ip'], fn (Builder $q) => $q->where('ip_address', 'like', '%'.$data['ip'].'%'))
                    )
                    ->indicateUsing(fn (array $data): array => $data['ip'] ? ['IP: '.$data['ip']] : []),

                Filter::make('date_range')
                    ->label('Date Range')
                    ->form([
                        DatePicker::make('from')->label('From')->native(false),
                        DatePicker::make('until')->label('Until')->native(false),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'], fn (Builder $q) => $q->where('logged_in_at', '>=', AdminDayRange::viewerDay($data['from'])->startUtc))
                        ->when($data['until'], fn (Builder $q) => $q->where('logged_in_at', '<', AdminDayRange::viewerDay($data['until'])->endUtcExclusive))
                    )
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

        return AdminListTable::apply($table);
    }
}
