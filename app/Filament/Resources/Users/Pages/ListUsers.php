<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    /**
     * Account-access tabs. The list stays search-first (see UsersTable);
     * tabs give a one-click view of who is pending, blocked or suspended.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $tabs = ['all' => Tab::make('All')->badge(fn (): int => User::query()->count())];

        foreach ([User::STATUS_ACTIVE, User::STATUS_PENDING, User::STATUS_INACTIVE, User::STATUS_SUSPENDED, User::STATUS_BLOCKED] as $status) {
            $tabs[$status] = Tab::make(User::statusLabel($status))
                ->badge(fn (): int => User::query()->where('status', $status)->count())
                ->badgeColor(User::statusColor($status))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', $status));
        }

        $tabs['deleted'] = Tab::make('Deleted')
            ->badge(fn (): int => User::query()->onlyTrashed()->count())
            ->badgeColor('danger')
            ->modifyQueryUsing(fn (Builder $query) => $query->onlyTrashed());

        return $tabs;
    }
}
