<?php

declare(strict_types=1);

namespace App\Filament\Support\Tables;

use BackedEnum;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * List-page tabs generated from a model's status enum: "All" plus one tab
 * per case, each with a live count badge. Uses the enum's label() and
 * color() when it defines them, otherwise a headline of the case name
 * and a neutral badge.
 */
final class StatusTabs
{
    /**
     * @param  class-string<Model>  $model
     * @param  class-string<BackedEnum>  $enum
     * @param  (callable(Builder): Builder)|null  $scope  Applied to every tab and count (e.g. exclude archived rows)
     * @return array<string, Tab>
     */
    public static function forEnum(string $model, string $enum, string $column = 'status', ?callable $scope = null): array
    {
        $base = fn (): Builder => $scope ? $scope($model::query()) : $model::query();

        $tabs = [
            'all' => Tab::make('All')->badge(fn (): int => $base()->count()),
        ];

        foreach ($enum::cases() as $case) {
            $tabs[$case->value] = Tab::make(self::label($case))
                ->badge(fn (): int => $base()->where($column, $case)->count())
                ->badgeColor(self::color($case))
                ->modifyQueryUsing(fn (Builder $query) => $query->where($column, $case));
        }

        return $tabs;
    }

    private static function label(BackedEnum $case): string
    {
        return method_exists($case, 'label') ? (string) $case->label() : Str::headline($case->name);
    }

    private static function color(BackedEnum $case): string
    {
        return method_exists($case, 'color') ? (string) $case->color() : 'gray';
    }
}
