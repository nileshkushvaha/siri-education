<?php

declare(strict_types=1);

namespace App\Filament\Resources\InstructorOnboarding\Tables;

use App\Enums\InstructorStatus;
use App\Filament\Resources\InstructorOnboarding\InstructorOnboardingResource;
use App\Filament\Resources\Users\Tables\UserColumns;
use App\Filament\Support\Tables\AdminListTable;
use App\Models\User;
use App\Services\Instructor\InstructorOnboardingService;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class InstructorOnboardingTable
{
    public static function configure(Table $table): Table
    {
        $table
            ->columns([
                // Shared with the Users / Students / Instructors lists so a
                // reviewer reads the same cells here as everywhere else —
                // including the mobile number, which is how an applicant
                // gets chased when an application stalls.
                UserColumns::person('Instructor'),
                UserColumns::mobile(),
                UserColumns::instructorLifecycle('instructor_status'),
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
            ->recordActions([
                Action::make('review')
                    ->label('Review')
                    ->icon('heroicon-m-arrow-top-right-on-square')
                    ->color('primary')
                    ->url(fn (User $record): string => InstructorOnboardingResource::getUrl('edit', ['record' => $record])),
            ])
            // No status filter: the tabs above the table (Needs Review /
            // Approved & Active / Rejected, Suspended & Archived / All)
            // already do that triage, and search now matches a status by
            // its label — the dropdown was a third way to ask the same
            // question. Consistent with the Users lists, which dropped
            // their filter panel for the same reason.
            ->searchPlaceholder('Search by name, email, mobile or application status')
            ->searchDebounce('400ms')
            ->persistSearchInSession()
            ->defaultSort('instructor_application_submitted_at', 'desc');

        return AdminListTable::apply($table);
    }
}
