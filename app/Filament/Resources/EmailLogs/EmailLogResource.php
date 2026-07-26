<?php

declare(strict_types=1);

namespace App\Filament\Resources\EmailLogs;

use App\Filament\Navigation\Concerns\HasCentralizedNavigation;
use App\Filament\Resources\EmailLogs\Pages\ListEmailLogs;
use App\Filament\Resources\EmailLogs\Pages\ViewEmailLog;
use App\Models\EmailLog;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class EmailLogResource extends Resource
{
    use HasCentralizedNavigation;

    protected static ?string $model = EmailLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected static ?string $navigationLabel = 'Email Logs';

    protected static ?string $modelLabel = 'Email Log';

    protected static ?string $pluralModelLabel = 'Email Logs';

    protected static string|\UnitEnum|null $navigationGroup = 'Communication';

    protected static ?int $navigationSort = 5;

    protected static ?string $slug = 'email-logs';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('status')->badge(),
            TextEntry::make('category')->badge(),
            TextEntry::make('provider')->badge(),
            TextEntry::make('mailer')->badge(),
            TextEntry::make('subject')->columnSpanFull(),
            TextEntry::make('from_address')->label('From'),
            TextEntry::make('from_name'),
            KeyValueEntry::make('to')->columnSpanFull(),
            KeyValueEntry::make('cc')->columnSpanFull(),
            KeyValueEntry::make('bcc')->columnSpanFull(),
            TextEntry::make('notification_type')->columnSpanFull(),
            TextEntry::make('notification_id'),
            TextEntry::make('provider_message_id'),
            TextEntry::make('error')->columnSpanFull(),
            TextEntry::make('created_at')->dateTime(),
            TextEntry::make('sent_at')->dateTime(),
            TextEntry::make('delivered_at')->dateTime(),
            TextEntry::make('failed_at')->dateTime(),
            KeyValueEntry::make('metadata')->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'sent', 'delivered' => 'success',
                        'failed', 'bounced', 'complained', 'suppressed' => 'danger',
                        'delayed' => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('category')->badge()->sortable(),
                TextColumn::make('subject')->searchable()->limit(48),
                TextColumn::make('to')
                    ->label('To')
                    ->state(fn (EmailLog $record): string => collect($record->to ?? [])
                        ->pluck('address')
                        ->filter()
                        ->implode(', ')),
                TextColumn::make('provider')->badge()->toggleable(),
                TextColumn::make('provider_message_id')->searchable()->toggleable(),
                TextColumn::make('created_at')->dateTime('M j, Y H:i')->sortable()->since(),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    'pending' => 'Pending',
                    'sent' => 'Sent',
                    'delivered' => 'Delivered',
                    'delayed' => 'Delayed',
                    'failed' => 'Failed',
                    'bounced' => 'Bounced',
                    'complained' => 'Complained',
                    'suppressed' => 'Suppressed',
                ]),
                SelectFilter::make('category')->options([
                    'auth' => 'Auth',
                    'booking' => 'Booking',
                    'payment' => 'Payment',
                    'tutor' => 'Tutor',
                    'wallet' => 'Wallet',
                    'support' => 'Support',
                    'admin' => 'Admin',
                    'general' => 'General',
                ]),
                SelectFilter::make('provider')->options([
                    'resend' => 'Resend',
                    'log' => 'Log',
                    'array' => 'Array',
                    'smtp' => 'SMTP',
                ]),
            ])
            ->recordAction('view')
            ->actions([
                ViewAction::make()->label(''),
            ])
            ->bulkActions([])
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(25);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEmailLogs::route('/'),
            'view' => ViewEmailLog::route('/{record}'),
        ];
    }
}
