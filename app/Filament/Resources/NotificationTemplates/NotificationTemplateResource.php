<?php

declare(strict_types=1);

namespace App\Filament\Resources\NotificationTemplates;

use App\Filament\Navigation\Concerns\HasCentralizedNavigation;
use App\Filament\Resources\NotificationTemplates\Pages\EditNotificationTemplate;
use App\Filament\Resources\NotificationTemplates\Pages\ListNotificationTemplates;
use App\Filament\Resources\NotificationTemplates\Schemas\NotificationTemplateForm;
use App\Filament\Resources\NotificationTemplates\Tables\NotificationTemplatesTable;
use App\Models\NotificationTemplate;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * GAP-039 requirement #6 — list/view/edit only. No create/delete
 * ability anywhere: template_key/channel pairs are a fixed, code-owned
 * set (NotificationTemplateRegistry), pre-seeded by migration — an
 * administrator may only edit an existing row's content or activation
 * state, never invent a new key/channel.
 */
class NotificationTemplateResource extends Resource
{
    use HasCentralizedNavigation;

    protected static ?string $model = NotificationTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static ?string $navigationLabel = 'Notification Templates';

    protected static ?string $modelLabel = 'Notification Template';

    protected static ?string $pluralModelLabel = 'Notification Templates';

    protected static string|\UnitEnum|null $navigationGroup = 'Communication';

    protected static ?int $navigationSort = 6;

    protected static ?string $slug = 'notification-templates';

    public static function canCreate(): bool
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

    public static function form(Schema $schema): Schema
    {
        return NotificationTemplateForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return NotificationTemplatesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListNotificationTemplates::route('/'),
            'edit' => EditNotificationTemplate::route('/{record}/edit'),
        ];
    }
}
