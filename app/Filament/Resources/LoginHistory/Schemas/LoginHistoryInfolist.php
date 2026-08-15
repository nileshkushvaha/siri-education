<?php

declare(strict_types=1);

namespace App\Filament\Resources\LoginHistory\Schemas;

use App\Models\LoginHistory;
use App\Support\LoginHistoryColors;
use App\Support\Timezone\ViewerDateTime;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class LoginHistoryInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([

            Grid::make(3)->schema([
                TextEntry::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => LoginHistoryColors::forStatus($state))
                    ->formatStateUsing(fn (string $state): string => ucfirst($state)),

                TextEntry::make('login_method')
                    ->label('Login Method')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (?string $state): string => $state ? ucfirst(str_replace('_', ' ', $state)) : '—'),

                TextEntry::make('logged_in_at')
                    ->label('Login Time')
                    ->dateTime('Y-m-d H:i:s'),
            ]),

            Grid::make(2)->schema([
                Section::make('User')
                    ->icon('heroicon-o-user')
                    ->schema([
                        TextEntry::make('user.name')
                            ->label('Name')
                            ->default('—'),

                        TextEntry::make('user.email')
                            ->label('Email')
                            ->default('—')
                            ->copyable(),
                    ]),

                Section::make('Session')
                    ->icon('heroicon-o-key')
                    ->schema([
                        TextEntry::make('session_id')
                            ->label('Session ID')
                            ->default('—')
                            ->copyable()
                            ->limit(40)
                            ->tooltip(fn (LoginHistory $record): string => $record->session_id ?? ''),

                        TextEntry::make('logged_out_at')
                            ->label('Logout Time')
                            ->state(fn (LoginHistory $record): string => $record->logged_out_at
                                ? ViewerDateTime::dateTime($record->logged_out_at, format: 'Y-m-d H:i:s')
                                : 'Still active / not recorded'
                            ),
                    ]),
            ]),

            Grid::make(2)->schema([
                Section::make('Network')
                    ->icon('heroicon-o-globe-alt')
                    ->schema([
                        TextEntry::make('ip_address')
                            ->label('IP Address')
                            ->copyable()
                            ->default('—'),

                        TextEntry::make('location_country')
                            ->label('Country')
                            ->default('—'),

                        TextEntry::make('location_city')
                            ->label('City')
                            ->default('—'),
                    ]),

                Section::make('Device')
                    ->icon('heroicon-o-device-phone-mobile')
                    ->schema([
                        TextEntry::make('browser')
                            ->label('Browser')
                            ->default('—'),

                        TextEntry::make('platform')
                            ->label('Operating System')
                            ->default('—'),

                        TextEntry::make('device_type')
                            ->label('Device Type')
                            ->badge()
                            ->color('gray')
                            ->default('—'),
                    ]),
            ]),

            Section::make('Advanced details')
                ->description('The raw browser/device text sent with this request — for troubleshooting only.')
                ->icon('heroicon-o-computer-desktop')
                ->schema([
                    TextEntry::make('user_agent')
                        ->label('')
                        ->default('—')
                        ->columnSpanFull(),
                ])
                ->collapsible()
                ->collapsed(),
        ]);
    }
}
