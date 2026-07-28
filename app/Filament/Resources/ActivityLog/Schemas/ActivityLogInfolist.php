<?php

declare(strict_types=1);

namespace App\Filament\Resources\ActivityLog\Schemas;

use App\Enums\ActivityActorType;
use App\Models\Activity;
use App\Support\ActivityLogColors;
use App\Support\ModelDisplayName;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;

class ActivityLogInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([

            Grid::make(3)->schema([
                TextEntry::make('log_name')
                    ->label('Log Channel')
                    ->badge()
                    ->color('gray'),

                TextEntry::make('event')
                    ->label('Event')
                    ->badge()
                    ->color(fn (?string $state): string => ActivityLogColors::forEvent($state)),

                TextEntry::make('created_at')
                    ->label('Performed At')
                    ->dateTime('Y-m-d H:i:s'),
            ]),

            Grid::make(2)->schema([

                Section::make('Subject')
                    ->icon('heroicon-o-document-text')
                    ->schema([
                        TextEntry::make('subject_type')
                            ->label('Type')
                            ->formatStateUsing(fn (?string $state): string => ModelDisplayName::for($state)),

                        TextEntry::make('subject_id')
                            ->label('ID')
                            ->default('—')
                            ->copyable(),

                        TextEntry::make('subject_link')
                            ->label('Record')
                            ->state(function (Activity $record): string {
                                if (! $record->subject_type || ! $record->subject_id) {
                                    return '—';
                                }
                                $class = $record->subject_type;
                                if (! class_exists($class)) {
                                    return 'Unable to load this record type';
                                }
                                try {
                                    $model = (new $class)->find($record->subject_id);
                                    $label = ModelDisplayName::for($class);

                                    return $model
                                        ? $label.' #'.$record->subject_id.' — record still exists'
                                        : $label.' #'.$record->subject_id.' — record was deleted';
                                } catch (\Throwable) {
                                    return '—';
                                }
                            }),
                    ]),

                Section::make('Performed By')
                    ->icon('heroicon-o-user')
                    ->schema([
                        TextEntry::make('actor_type')
                            ->label('Actor Type')
                            ->badge()
                            ->color(fn (?ActivityActorType $state): string => $state?->color() ?? 'gray')
                            ->icon(fn (?ActivityActorType $state): string => $state?->icon() ?? 'heroicon-o-user')
                            ->formatStateUsing(fn (?ActivityActorType $state): string => $state?->label() ?? '—'),

                        TextEntry::make('actor_name_display')
                            ->label('Name')
                            ->state(fn (Activity $record): string => $record->actorName()),

                        TextEntry::make('actor_email_display')
                            ->label('Email')
                            ->state(fn (Activity $record): string => $record->actorEmail() ?? '—')
                            ->copyable(),

                        TextEntry::make('guest_phone')
                            ->label('Phone')
                            ->default('—')
                            ->visible(fn (Activity $record): bool => $record->isGuest()),
                    ]),
            ]),

            Section::make('Description')
                ->icon('heroicon-o-chat-bubble-left-ellipsis')
                ->schema([
                    TextEntry::make('description')
                        ->label('')
                        ->columnSpanFull(),
                ])
                ->collapsible(),

            Tabs::make('Changes')
                ->tabs([
                    Tab::make('Before')
                        ->icon('heroicon-o-arrow-left-circle')
                        ->schema([
                            TextEntry::make('before_values')
                                ->label('')
                                ->state(function (Activity $record): string {
                                    $old = data_get($record->attribute_changes, 'old');

                                    return $old ? json_encode($old, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : 'No previous values recorded.';
                                })
                                ->fontFamily(FontFamily::Mono)
                                ->extraAttributes(['class' => 'whitespace-pre-wrap break-all text-xs'])
                                ->columnSpanFull(),
                        ]),

                    Tab::make('After')
                        ->icon('heroicon-o-arrow-right-circle')
                        ->schema([
                            TextEntry::make('after_values')
                                ->label('')
                                ->state(function (Activity $record): string {
                                    $new = data_get($record->attribute_changes, 'attributes');

                                    return $new ? json_encode($new, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : 'No new values recorded.';
                                })
                                ->fontFamily(FontFamily::Mono)
                                ->extraAttributes(['class' => 'whitespace-pre-wrap break-all text-xs'])
                                ->columnSpanFull(),
                        ]),

                    Tab::make('Metadata')
                        ->icon('heroicon-o-information-circle')
                        ->schema([
                            TextEntry::make('batch_uuid')
                                ->label('Batch UUID')
                                ->default('—')
                                ->copyable()
                                ->helperText('Groups related log entries created in a single operation.'),

                            TextEntry::make('ip_address')
                                ->label('IP Address')
                                ->state(fn (Activity $record): string => $record->ip_address
                                    ?? data_get($record->properties, 'ip')
                                    ?? data_get($record->properties, 'ip_address')
                                    ?? '—')
                                ->copyable(),

                            TextEntry::make('method')
                                ->label('HTTP Method')
                                ->default('—'),

                            TextEntry::make('route')
                                ->label('Route / Path')
                                ->default('—'),

                            TextEntry::make('session_id')
                                ->label('Session ID')
                                ->default('—')
                                ->copyable(),

                            TextEntry::make('user_agent_display')
                                ->label('User Agent')
                                ->state(fn (Activity $record): string => $record->user_agent
                                    ?? data_get($record->properties, 'user_agent')
                                    ?? '—')
                                ->limit(120)
                                ->tooltip(fn (Activity $record): string => $record->user_agent
                                    ?? data_get($record->properties, 'user_agent')
                                    ?? ''),

                            TextEntry::make('properties_raw')
                                ->label('Full Properties (JSON)')
                                ->state(function (Activity $record): string {
                                    $props = $record->properties?->toArray() ?? [];

                                    return $props ? json_encode($props, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : '{}';
                                })
                                ->fontFamily(FontFamily::Mono)
                                ->extraAttributes(['class' => 'whitespace-pre-wrap break-all text-xs'])
                                ->columnSpanFull(),
                        ]),
                ])
                ->columnSpanFull(),
        ]);
    }
}
