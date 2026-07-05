<?php

namespace App\Filament\Resources\Languages\Pages;

use App\Filament\Resources\Languages\LanguageResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListLanguages extends ListRecords
{
    protected static string $resource = LanguageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All')->modifyQueryUsing(fn (Builder $query) => $query->withTrashed()),
            'active' => Tab::make('Active')->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'active')),
            'inactive' => Tab::make('Inactive')->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'inactive')),
            'trashed' => Tab::make('Archived')->modifyQueryUsing(fn (Builder $query) => $query->onlyTrashed()),
        ];
    }
}
