<?php

declare(strict_types=1);

namespace App\Filament\Resources\Lessons;

use App\Filament\Navigation\Concerns\HasCentralizedNavigation;
use App\Filament\Resources\Lessons\Pages\ListLessons;
use App\Filament\Resources\Lessons\Tables\LessonsTable;
use App\Lessons\Enums\LessonStatus;
use App\Models\Lesson;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Lessons are created by the lifecycle engine (BookingConfirmed), never
 * from the panel — there is intentionally no Create or Edit page.
 * Lifecycle actions delegate to LessonLifecycleServiceInterface; no
 * business logic here.
 */
class LessonResource extends Resource
{
    use HasCentralizedNavigation;

    protected static ?string $model = Lesson::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAcademicCap;

    protected static string|\UnitEnum|null $navigationGroup = 'Booking';

    protected static ?int $navigationSort = 2;

    public static function table(Table $table): Table
    {
        return LessonsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLessons::route('/'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['booking', 'student', 'instructor', 'subject', 'subjectTopic']);
    }

    public static function getNavigationBadge(): ?string
    {
        $disputed = Lesson::query()->where('status', LessonStatus::Disputed)->count();

        return $disputed > 0 ? (string) $disputed : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('viewAny', Lesson::class) ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
