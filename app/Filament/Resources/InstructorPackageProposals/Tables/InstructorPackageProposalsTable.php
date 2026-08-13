<?php

declare(strict_types=1);

namespace App\Filament\Resources\InstructorPackageProposals\Tables;

use App\Models\InstructorPackageProposal;
use App\Package\Enums\InstructorPackageProposalStatus;
use App\Package\Exceptions\PackageException;
use App\Package\Services\InstructorPackageProposalService;
use App\Support\MoneyFormatter;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Lifecycle actions only — no status select, no editing of price/
 * quantities/instructor/student, no delete. Every mutation routes
 * through InstructorPackageProposalService, which re-validates the
 * transition. Mirrors InstructorWithdrawalRequestsTable's shape.
 */
class InstructorPackageProposalsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('instructor.name')
                    ->label('Instructor')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('student.name')
                    ->label('Student')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('packageBenefitRule.name')
                    ->label('Package Offer'),
                TextColumn::make('unit_price_minor')
                    ->label('Unit Price')
                    ->state(fn (InstructorPackageProposal $record): string => self::money($record->unit_price_minor, $record->currency_code)),
                TextColumn::make('paid_quantity')
                    ->label('Paid Lessons'),
                TextColumn::make('calculated_price_minor')
                    ->label('Calculated Price')
                    ->state(fn (InstructorPackageProposal $record): string => self::money($record->calculated_price_minor, $record->currency_code)),
                TextColumn::make('final_price_minor')
                    ->label('Final Price')
                    ->weight('bold')
                    ->state(fn (InstructorPackageProposal $record): string => self::money($record->final_price_minor, $record->currency_code)),
                TextColumn::make('override_reason')
                    ->label('Override Reason')
                    ->placeholder('—')
                    ->limit(40)
                    ->toggleable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (InstructorPackageProposalStatus $state): string => $state->color()),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(
                        collect(InstructorPackageProposalStatus::cases())
                            ->mapWithKeys(fn (InstructorPackageProposalStatus $s) => [$s->value => $s->label()])
                            ->toArray()
                    ),
            ])
            ->recordActions([
                Action::make('approve')
                    ->icon('heroicon-m-check-badge')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription('Approves the proposal. Optionally override the final price shown to the student — an override always requires a reason and is fully audited.')
                    ->authorize(fn (InstructorPackageProposal $record): bool => auth()->user()?->can('approve', $record) ?? false)
                    ->visible(fn (InstructorPackageProposal $record): bool => $record->status->canTransitionTo(InstructorPackageProposalStatus::Approved))
                    ->form([
                        TextInput::make('final_price')
                            ->label(fn (InstructorPackageProposal $record): string => sprintf('Admin final price (calculated: %s)', self::money($record->calculated_price_minor, $record->currency_code)))
                            ->numeric()
                            ->minValue(0)
                            ->default(fn (InstructorPackageProposal $record): float => $record->calculated_price_minor / (10 ** MoneyFormatter::minorUnitsFor($record->currency_code)))
                            ->helperText('Leave as the calculated amount to approve without an override.'),
                        TextInput::make('override_reason')
                            ->label('Override Reason')
                            ->maxLength(1000)
                            ->required(fn (Get $get, InstructorPackageProposal $record): bool => self::isOverride($get('final_price'), $record))
                            ->visible(fn (Get $get, InstructorPackageProposal $record): bool => self::isOverride($get('final_price'), $record))
                            ->helperText('Required when the final price differs from the calculated price.'),
                    ])
                    ->action(function (InstructorPackageProposal $record, array $data): void {
                        $overrideMinor = self::isOverride($data['final_price'] ?? null, $record)
                            ? MoneyFormatter::toMinor((string) $data['final_price'], MoneyFormatter::minorUnitsFor($record->currency_code))
                            : null;

                        self::callService(
                            fn (InstructorPackageProposalService $service) => $service->approve($record, auth()->user(), $overrideMinor, $data['override_reason'] ?? null),
                            'Package proposal approved',
                        );
                    }),

                Action::make('reject')
                    ->icon('heroicon-m-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('Rejects the proposal. The instructor may submit a new one.')
                    ->authorize(fn (InstructorPackageProposal $record): bool => auth()->user()?->can('reject', $record) ?? false)
                    ->visible(fn (InstructorPackageProposal $record): bool => $record->status->canTransitionTo(InstructorPackageProposalStatus::Rejected))
                    ->form([
                        Textarea::make('reason')
                            ->required()
                            ->maxLength(1000)
                            ->helperText('Shown to the instructor.'),
                    ])
                    ->action(fn (InstructorPackageProposal $record, array $data) => self::callService(
                        fn (InstructorPackageProposalService $service) => $service->reject($record, auth()->user(), $data['reason']),
                        'Package proposal rejected',
                    )),
            ])
            ->defaultSort('created_at', 'desc');
    }

    private static function isOverride(mixed $finalPrice, InstructorPackageProposal $record): bool
    {
        if ($finalPrice === null || $finalPrice === '') {
            return false;
        }

        $minor = MoneyFormatter::toMinor((string) $finalPrice, MoneyFormatter::minorUnitsFor($record->currency_code));

        return $minor !== $record->calculated_price_minor;
    }

    private static function money(?int $minor, ?string $currencyCode): string
    {
        if ($minor === null || $currencyCode === null) {
            return '—';
        }

        return MoneyFormatter::format($minor, $currencyCode);
    }

    private static function callService(callable $callback, string $successTitle): void
    {
        try {
            $callback(app(InstructorPackageProposalService::class));
            Notification::make()->title($successTitle)->success()->send();
        } catch (PackageException $e) {
            Notification::make()->title('Action failed')->body($e->getMessage())->danger()->send();
        }
    }
}
