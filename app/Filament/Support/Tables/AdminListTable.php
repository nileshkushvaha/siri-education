<?php

declare(strict_types=1);

namespace App\Filament\Support\Tables;

use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;

/**
 * Shared list-table ergonomics for admin resources: every filter is its
 * own always-visible control above the table (no crowded dropdown),
 * filters and search survive navigation within the session, rows are
 * striped and page size is selectable. Call once per table, after the
 * resource-specific configuration.
 */
final class AdminListTable
{
    public static function apply(Table $table, ?string $searchPlaceholder = null): Table
    {
        $table
            ->filtersLayout(FiltersLayout::AboveContent)
            ->filtersFormColumns(['md' => 2, 'lg' => 4])
            ->deferFilters(false)
            ->persistFiltersInSession()
            ->persistSearchInSession()
            ->striped()
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(25);

        if ($searchPlaceholder !== null) {
            $table->searchPlaceholder($searchPlaceholder);
        }

        return $table;
    }
}
