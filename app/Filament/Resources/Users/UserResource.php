<?php

namespace App\Filament\Resources\Users;

use App\Filament\Navigation\Concerns\HasCentralizedNavigation;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Pages\ViewUser;
use App\Filament\Resources\Users\RelationManagers\ActivityLogRelationManager;
use App\Filament\Resources\Users\RelationManagers\LoginHistoryRelationManager;
use App\Filament\Resources\Users\Schemas\UserForm;
use App\Filament\Resources\Users\Tables\UsersTable;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class UserResource extends Resource
{
    use HasCentralizedNavigation;

    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|\UnitEnum|null $navigationGroup = 'Users & Access';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    // Page title, breadcrumb and empty states all read from here, so the
    // "All Users" the sidebar promises is the same words the page shows —
    // and it stays distinguishable from the role-scoped Students /
    // Instructors lists once you have landed on one of them.
    protected static ?string $pluralModelLabel = 'all users';

    public static function form(Schema $schema): Schema
    {
        return UserForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return UsersTable::configure($table);
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'email'];
    }

    public static function getRelations(): array
    {
        return [
            // General account activity stays visible here too (not
            // instructor-specific); Experience/Education moved fully to
            // InstructorOnboardingResource since they're onboarding-only.
            ActivityLogRelationManager::class,
            LoginHistoryRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'view' => ViewUser::route('/{record}'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
