<?php

declare(strict_types=1);

namespace App\Filament\Resources\StudentPackagePurchases\Tables;

use App\Package\Enums\PackagePurchaseStatus;
use App\Support\MoneyFormatter;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Read-only. No record actions, no bulk actions, no editing — the
 * amount and currency are an immutable snapshot of what the student
 * accepted, and the status moves only on verified settlement.
 *
 * Gateway-internal identifiers (order/intent ids) are deliberately
 * absent: they belong to individual payment attempts, not to the
 * purchase, and are not admin-actionable here.
 */
class StudentPackagePurchasesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')
                    ->label('Reference')
                    ->searchable()
                    ->copyable(),
                TextColumn::make('student.name')
                    ->label('Student')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('proposal.instructor.name')
                    ->label('Instructor')
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('proposal.packageBenefitRule.name')
                    ->label('Package Offer')
                    ->placeholder('—'),
                TextColumn::make('amount_minor')
                    ->label('Amount')
                    ->formatStateUsing(fn (int $state, $record): string => MoneyFormatter::format($state, (string) $record->currency_code))
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (PackagePurchaseStatus $state): string => $state->color()),
                TextColumn::make('payments_count')
                    ->label('Attempts')
                    ->counts('payments')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('accepted_at')
                    ->label('Accepted')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('paid_at')
                    ->label('Paid')
                    ->dateTime()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(
                        collect(PackagePurchaseStatus::cases())
                            ->mapWithKeys(fn (PackagePurchaseStatus $s) => [$s->value => $s->label()])
                            ->toArray()
                    ),
            ])
            ->defaultSort('accepted_at', 'desc');
    }
}
