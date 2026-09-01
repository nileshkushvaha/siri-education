<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Tables;

use App\Enums\InstructorStatus;
use App\Enums\StudentStatus;
use App\Models\User;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Builder;

/**
 * The columns every admin list of people is built from — All Users, the
 * role-scoped Students/Instructors lists, and instructor onboarding.
 *
 * They live here rather than in each table because the same three
 * questions kept being answered slightly differently on each screen:
 * who is this person, how do I reach them, and what state are they in.
 * A tooltip fixed on one list and not the others is the failure mode
 * this file exists to prevent.
 */
final class UserColumns
{
    /**
     * Name with the email as its subtitle. Two columns for "who is this"
     * pushed the contact and status columns off the fold on a laptop;
     * search still matches either half.
     */
    public static function person(string $label = 'Person'): TextColumn
    {
        return TextColumn::make('name')
            ->label($label)
            ->description(fn (User $record): string => $record->email)
            ->weight(FontWeight::Medium)
            ->sortable()
            ->copyable()
            ->copyableState(fn (User $record): string => $record->email)
            ->copyMessage('Email copied')
            ->searchable(query: fn (Builder $query, string $search): Builder => $query
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%"));
    }

    /**
     * E.164 when the number has been normalised, the raw entry
     * otherwise — whoever is phoning needs whichever one exists. Search
     * covers both plus the national number, so a number pasted in any
     * format from a support ticket finds the person.
     */
    public static function mobile(): TextColumn
    {
        return TextColumn::make('profile.phone_e164')
            ->label('Mobile')
            ->state(fn (User $record): ?string => $record->profile?->phone_e164 ?: $record->profile?->phone)
            ->placeholder('—')
            ->copyable()
            ->copyMessage('Mobile number copied')
            ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas(
                'profile',
                fn (Builder $profileQuery) => $profileQuery
                    ->where('phone_e164', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('phone_national_number', 'like', "%{$search}%")
            ));
    }

    public static function country(): TextColumn
    {
        return TextColumn::make('profile.country.name')
            ->label('Country')
            ->placeholder('—')
            ->searchable()
            ->toggleable();
    }

    /**
     * Can this person sign in — the platform-wide question, distinct from
     * the role lifecycle below. Email confirmation is this column's
     * subtitle rather than a third status badge: "Active", "Verified" and
     * a lifecycle "Active" side by side read as one confusing block when
     * each means something different.
     */
    public static function accountAccess(): TextColumn
    {
        return TextColumn::make('status')
            ->label('Account access')
            ->tooltip('Whether this person can sign in, and whether their email address is confirmed. Separate from the instructor/student lifecycle.')
            ->badge()
            ->formatStateUsing(fn (string $state): string => User::statusLabel($state))
            ->color(fn (string $state): string => User::statusColor($state))
            ->description(fn (User $record): string => $record->email_verified_at
                ? 'Email verified'
                : 'Email not verified')
            // Searched by what the badge says, not by the stored value:
            // an admin types "pending", never "pending_verification".
            ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereIn(
                'status',
                User::statusesMatching($search),
            ))
            ->sortable();
    }

    /**
     * @param  string  $attribute  'profile.instructor_status' normally; the
     *                             onboarding list selects the joined column directly.
     */
    public static function instructorLifecycle(string $attribute = 'profile.instructor_status', string $label = 'Instructor lifecycle'): TextColumn
    {
        return TextColumn::make($attribute)
            ->label($label)
            ->tooltip('Where this person is in the instructor application/teaching lifecycle: Draft → Submitted → Under Review → Approved → Active, plus Vacation/Suspended/Archived/Rejected. Independent of account access.')
            ->badge()
            ->formatStateUsing(fn ($state): string => self::instructorStatus($state)?->label() ?? '—')
            ->color(fn ($state): string => self::instructorStatus($state)?->color() ?? 'gray')
            ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas(
                'profile',
                fn (Builder $profileQuery) => $profileQuery->whereIn(
                    'instructor_status',
                    LifecycleSearch::valuesMatching(InstructorStatus::cases(), $search),
                ),
            ))
            ->sortable();
    }

    public static function studentLifecycle(string $attribute = 'profile.student_status', string $label = 'Student lifecycle'): TextColumn
    {
        return TextColumn::make($attribute)
            ->label($label)
            ->tooltip('Where this person is in the student lifecycle: Registered → Active, plus Suspended/Archived. Independent of account access.')
            ->badge()
            ->formatStateUsing(fn ($state): string => self::studentStatus($state)?->label() ?? '—')
            ->color(fn ($state): string => self::studentStatus($state)?->color() ?? 'gray')
            ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas(
                'profile',
                fn (Builder $profileQuery) => $profileQuery->whereIn(
                    'student_status',
                    LifecycleSearch::valuesMatching(StudentStatus::cases(), $search),
                ),
            ))
            ->sortable();
    }

    /**
     * The state arrives as an enum through the `profile` relation and as
     * a raw string through the onboarding list's join — accept both
     * rather than making callers care which query they came from.
     */
    private static function instructorStatus(mixed $state): ?InstructorStatus
    {
        return match (true) {
            $state instanceof InstructorStatus => $state,
            is_string($state) => InstructorStatus::tryFrom($state),
            default => null,
        };
    }

    private static function studentStatus(mixed $state): ?StudentStatus
    {
        return match (true) {
            $state instanceof StudentStatus => $state,
            is_string($state) => StudentStatus::tryFrom($state),
            default => null,
        };
    }
}
