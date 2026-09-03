<?php

declare(strict_types=1);

namespace App\Filament\Resources\StudentLessonPrices\Pages;

use App\Filament\Resources\StudentLessonPrices\StudentLessonPriceResource;
use App\Models\StudentLessonPrice;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListStudentLessonPrices extends ListRecords
{
    protected static string $resource = StudentLessonPriceResource::class;

    public function getSubheading(): ?string
    {
        return 'What students are charged per booking type, subject, level, country and duration. The most specific active row wins; an instructor override always beats the base price.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Add price'),
        ];
    }

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All')
                ->badge(fn (): int => StudentLessonPrice::query()->count()),
            'base' => Tab::make('Base prices')
                ->badge(fn (): int => StudentLessonPrice::query()->whereNull('instructor_id')->count())
                ->modifyQueryUsing(fn (Builder $query) => $query->whereNull('instructor_id')),
            'overrides' => Tab::make('Instructor overrides')
                ->badge(fn (): int => StudentLessonPrice::query()->whereNotNull('instructor_id')->count())
                ->modifyQueryUsing(fn (Builder $query) => $query->whereNotNull('instructor_id')),
            'inactive' => Tab::make('Inactive')
                ->badge(fn (): int => StudentLessonPrice::query()->where('is_active', false)->count())
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_active', false)),
            'expired' => Tab::make('Expired')
                ->badge(fn (): int => StudentLessonPrice::query()->where('effective_until', '<', now())->count())
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('effective_until', '<', now())),
        ];
    }
}
