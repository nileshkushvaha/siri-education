<?php

declare(strict_types=1);

namespace App\Filament\Resources\InstructorOnboarding\Tables;

use App\Enums\InstructorStatus;
use App\Filament\Resources\InstructorOnboarding\InstructorOnboardingResource;
use App\Models\User;
use App\Services\Instructor\InstructorOnboardingService;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class InstructorOnboardingTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Instructor')
                    ->description(fn (User $record): string => $record->email)
                    ->searchable(['name', 'email'])
                    ->sortable(),
                TextColumn::make('instructor_status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => InstructorStatus::from($state)->label())
                    ->color(fn (string $state): string => InstructorStatus::from($state)->color())
                    ->sortable(),
                TextColumn::make('onboarding_completion')
                    ->label('Profile Complete')
                    ->state(fn (User $record): string => app(InstructorOnboardingService::class)->progress($record)['percentage'].'%'),
                TextColumn::make('instructor_application_submitted_at')
                    ->label('Submitted')
                    ->dateTime()
                    ->placeholder('Not submitted')
                    ->sortable(),
                TextColumn::make('days_pending')
                    ->label('Days Pending')
                    ->state(function (User $record): ?string {
                        if (! in_array(InstructorStatus::from($record->instructor_status), InstructorStatus::needsReview(), true)
                            || $record->instructor_application_submitted_at === null) {
                            return null;
                        }

                        return (string) now()->diffInDays($record->instructor_application_submitted_at);
                    })
                    ->placeholder('—')
                    ->color(fn (?string $state): ?string => $state !== null && (int) $state >= 3 ? 'danger' : null),
                TextColumn::make('instructor_reviewed_at')
                    ->label('Last Reviewed')
                    ->dateTime()
                    ->placeholder('Never')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('instructor_status')
                    ->label('Status')
                    ->options(collect(InstructorStatus::cases())
                        ->mapWithKeys(fn (InstructorStatus $status): array => [$status->value => $status->label()])
                        ->toArray()),
            ])
            ->recordActions([
                Action::make('review')
                    ->label('Review')
                    ->icon('heroicon-m-arrow-top-right-on-square')
                    ->color('primary')
                    ->url(fn (User $record): string => InstructorOnboardingResource::getUrl('edit', ['record' => $record])),
            ])
            ->defaultSort('instructor_application_submitted_at', 'desc');
    }
}
