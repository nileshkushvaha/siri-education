<?php

declare(strict_types=1);

namespace App\Filament\Resources\ContactInquiries;

use App\Filament\Navigation\Concerns\HasCentralizedNavigation;
use App\Filament\Resources\ContactInquiries\Pages\ListContactInquiries;
use App\Filament\Resources\ContactInquiries\Pages\ViewContactInquiry;
use App\Models\ContactInquiry;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class ContactInquiryResource extends Resource
{
    use HasCentralizedNavigation;

    protected static ?string $model = ContactInquiry::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static ?string $navigationLabel = 'Contact Inquiries';

    protected static ?string $modelLabel = 'Contact Inquiry';

    protected static ?string $pluralModelLabel = 'Contact Inquiries';

    protected static string|\UnitEnum|null $navigationGroup = 'Communication';

    protected static ?int $navigationSort = 6;

    protected static ?string $slug = 'contact-inquiries';

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
            TextEntry::make('name'),
            TextEntry::make('email')->copyable()->default('—'),
            TextEntry::make('phone')->copyable()->default('—'),
            TextEntry::make('subject')->default('—'),
            TextEntry::make('message')->columnSpanFull()->default('—'),
            KeyValueEntry::make('meta')->label('Submission Details')->columnSpanFull(),
            TextEntry::make('ip_address')->label('IP Address')->copyable()->default('—'),
            TextEntry::make('user_agent')->label('User Agent')->default('—')->columnSpanFull(),
            TextEntry::make('created_at')->label('Submitted At')->dateTime(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('email')->searchable()->sortable()->default('—'),
                TextColumn::make('phone')->searchable()->default('—'),
                TextColumn::make('subject')->searchable()->limit(48)->default('—'),
                TextColumn::make('message')->searchable()->limit(48)->default('—'),
                TextColumn::make('created_at')->label('Submitted')->dateTime('M j, Y H:i')->sortable()->since(),
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
            'index' => ListContactInquiries::route('/'),
            'view' => ViewContactInquiry::route('/{record}'),
        ];
    }
}
