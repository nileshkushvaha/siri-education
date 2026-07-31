<?php

declare(strict_types=1);

namespace App\Filament\Resources\NewsletterSubscribers;

use App\Enums\NewsletterSubscriberStatus;
use App\Filament\Navigation\Concerns\HasCentralizedNavigation;
use App\Filament\Resources\NewsletterSubscribers\Pages\ListNewsletterSubscribers;
use App\Filament\Resources\NewsletterSubscribers\Pages\ViewNewsletterSubscriber;
use App\Models\NewsletterSubscriber;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class NewsletterSubscriberResource extends Resource
{
    use HasCentralizedNavigation;

    protected static ?string $model = NewsletterSubscriber::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelopeOpen;

    protected static ?string $navigationLabel = 'Newsletter Subscribers';

    protected static ?string $modelLabel = 'Newsletter Subscriber';

    protected static ?string $pluralModelLabel = 'Newsletter Subscribers';

    protected static string|\UnitEnum|null $navigationGroup = 'Communication';

    protected static ?int $navigationSort = 16;

    protected static ?string $slug = 'newsletter-subscribers';

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
            TextEntry::make('email')->copyable(),
            TextEntry::make('name')->default('—'),
            TextEntry::make('status')->badge(),
            TextEntry::make('source')->default('—'),
            TextEntry::make('subscribed_at')->label('Subscribed At')->dateTime()->default('—'),
            TextEntry::make('unsubscribed_at')->label('Unsubscribed At')->dateTime()->default('—'),
            TextEntry::make('created_at')->label('First Seen')->dateTime(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('email')->searchable()->sortable(),
                TextColumn::make('name')->searchable()->default('—'),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (NewsletterSubscriberStatus $state): string => match ($state) {
                        NewsletterSubscriberStatus::Subscribed => 'success',
                        NewsletterSubscriberStatus::Unsubscribed => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('source')->default('—')->toggleable(),
                TextColumn::make('subscribed_at')->label('Subscribed')->dateTime('M j, Y H:i')->sortable()->since(),
                TextColumn::make('unsubscribed_at')->label('Unsubscribed')->dateTime('M j, Y H:i')->sortable()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    'subscribed' => 'Subscribed',
                    'unsubscribed' => 'Unsubscribed',
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
            'index' => ListNewsletterSubscribers::route('/'),
            'view' => ViewNewsletterSubscriber::route('/{record}'),
        ];
    }
}
