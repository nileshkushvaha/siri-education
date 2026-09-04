<?php

declare(strict_types=1);

namespace App\Filament\Resources\StudentPackageEntitlements\Tables;

use App\Filament\Support\Tables\AdminListTable;
use App\Package\Enums\PackageEntitlementStatus;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Read-only. No record actions, no bulk actions, no editing — a
 * student's lesson balance is changed only by consuming a lesson
 * through PackageEntitlementService.
 */
class StudentPackageEntitlementsTable
{
    public static function configure(Table $table): Table
    {
        $table
            ->columns([
                TextColumn::make('student.name')
                    ->label('Student')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('instructor.name')
                    ->label('Instructor')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('proposal.packageBenefitRule.name')
                    ->label('Package Offer')
                    ->placeholder('—'),
                TextColumn::make('total_quantity')
                    ->label('Total Lessons')
                    ->sortable(),
                TextColumn::make('used_quantity')
                    ->label('Used Lessons')
                    ->sortable(),
                TextColumn::make('remaining_quantity')
                    ->label('Remaining')
                    ->weight('bold')
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (PackageEntitlementStatus $state): string => $state->color()),
                // The absolute expiry written at activation; null means
                // the offer carried no validity limit.
                TextColumn::make('expires_at')
                    ->label('Valid Until')
                    ->dateTime()
                    ->placeholder('No expiry')
                    ->sortable(),
                TextColumn::make('activated_at')
                    ->label('Activated')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('completed_at')
                    ->label('Completed')
                    ->dateTime()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(
                        collect(PackageEntitlementStatus::cases())
                            ->mapWithKeys(fn (PackageEntitlementStatus $s) => [$s->value => $s->label()])
                            ->toArray()
                    ),
            ])
            ->defaultSort('activated_at', 'desc');

        return AdminListTable::apply($table);
    }
}
