<?php

declare(strict_types=1);

namespace App\Filament\Resources\Lessons\Tables;

use App\Filament\Support\AdminDayRange;
use App\Lessons\Contracts\LessonLifecycleServiceInterface;
use App\Lessons\Enums\LessonAttendanceStatus;
use App\Lessons\Enums\LessonStatus;
use App\Lessons\Exceptions\LessonException;
use App\Models\Lesson;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Every lifecycle mutation routes through LessonLifecycleServiceInterface
 * so transition guards, booking sync, and audit run exactly as they do
 * everywhere else — zero duplicated business logic.
 */
class LessonsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('booking.reference')
                    ->label('Booking')
                    ->searchable()
                    ->sortable()
                    ->fontFamily('mono')
                    ->copyable(),
                TextColumn::make('student.name')
                    ->label('Student')
                    ->sortable(),
                TextColumn::make('instructor.name')
                    ->label('Instructor')
                    ->sortable(),
                TextColumn::make('subject.name')
                    ->label('Subject')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('subjectTopic.name')
                    ->label('Topic')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('starts_at')
                    ->label('Starts')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (LessonStatus $state): string => $state->label())
                    ->color(fn (LessonStatus $state): string => $state->color()),
                TextColumn::make('student_attendance_status')
                    ->label('Student Att.')
                    ->badge()
                    ->formatStateUsing(fn (LessonAttendanceStatus $state): string => $state->label())
                    ->color(fn (LessonAttendanceStatus $state): string => $state->color())
                    ->toggleable(),
                TextColumn::make('instructor_attendance_status')
                    ->label('Instructor Att.')
                    ->badge()
                    ->formatStateUsing(fn (LessonAttendanceStatus $state): string => $state->label())
                    ->color(fn (LessonAttendanceStatus $state): string => $state->color())
                    ->toggleable(),
                TextColumn::make('meeting_link')
                    ->label('Meeting')
                    // Participant-facing join URL only — host_url/password
                    // are hidden on the model and never surface here.
                    ->state(fn (Lesson $record): ?string => $record->booking?->meeting_url ? 'Join link' : null)
                    ->url(fn (Lesson $record): ?string => $record->booking?->meeting_url, shouldOpenInNewTab: true)
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('completed_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('auto_completed_at')
                    ->label('Auto-completed')
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(LessonStatus::cases())
                        ->mapWithKeys(fn (LessonStatus $s) => [$s->value => $s->label()])
                        ->toArray()),
                SelectFilter::make('instructor_id')
                    ->label('Instructor')
                    ->relationship('instructor', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('student_id')
                    ->label('Student')
                    ->relationship('student', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('subject_id')
                    ->label('Subject')
                    ->relationship('subject', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('subject_topic_id')
                    ->label('Topic')
                    ->relationship('subjectTopic', 'name')
                    ->searchable()
                    ->preload(),
                Filter::make('starts_between')
                    ->form([
                        DatePicker::make('from'),
                        DatePicker::make('until'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'], fn (Builder $q, $date) => $q->where('starts_at', '>=', AdminDayRange::viewerDay($date)->startUtc))
                        ->when($data['until'], fn (Builder $q, $date) => $q->where('starts_at', '<', AdminDayRange::viewerDay($date)->endUtcExclusive))),
                TrashedFilter::make(),
            ])
            ->recordActions([
                Action::make('mark_live')
                    ->label('Mark Live')
                    ->icon('heroicon-m-play')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->authorize(fn (Lesson $record): bool => auth()->user()?->can('markAttendance', $record) ?? false)
                    ->visible(fn (Lesson $record): bool => $record->status->canTransitionTo(LessonStatus::Live))
                    ->action(fn (Lesson $record) => self::callService(
                        fn (LessonLifecycleServiceInterface $service) => $service->markLive($record, auth()->user()),
                        'Lesson marked live',
                    )),

                Action::make('mark_attendance')
                    ->label('Mark Attendance')
                    ->icon('heroicon-m-user-group')
                    ->color('info')
                    ->authorize(fn (Lesson $record): bool => auth()->user()?->can('markAttendance', $record) ?? false)
                    ->visible(fn (Lesson $record): bool => $record->status->isOpen())
                    ->form([
                        Select::make('party')
                            ->options(['student' => 'Student', 'instructor' => 'Instructor'])
                            ->required()
                            ->native(false),
                        Select::make('attendance')
                            ->options([
                                LessonAttendanceStatus::Attended->value => 'Attended',
                                LessonAttendanceStatus::NoShow->value => 'No-Show',
                            ])
                            ->required()
                            ->native(false),
                    ])
                    ->action(function (Lesson $record, array $data): void {
                        $status = LessonAttendanceStatus::from($data['attendance']);

                        // Panel actions are the admin override path (F/H):
                        // they bypass the no-show grace period.
                        self::callService(
                            fn (LessonLifecycleServiceInterface $service) => $data['party'] === 'student'
                                ? $service->markStudentAttendance($record, $status, auth()->user(), override: true)
                                : $service->markInstructorAttendance($record, $status, auth()->user(), override: true),
                            'Attendance marked',
                        );
                    }),

                Action::make('complete')
                    ->icon('heroicon-m-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->authorize(fn (Lesson $record): bool => auth()->user()?->can('complete', $record) ?? false)
                    ->visible(fn (Lesson $record): bool => $record->status->canTransitionTo(LessonStatus::Completed))
                    ->form([
                        Textarea::make('notes')->maxLength(1000),
                    ])
                    ->action(fn (Lesson $record, array $data) => self::callService(
                        // Admin completion is the override path (G): it may
                        // complete early and bypass attendance requirements.
                        fn (LessonLifecycleServiceInterface $service) => $service->complete($record, auth()->user(), $data['notes'] ?? null, override: true),
                        'Lesson completed',
                    )),

                Action::make('finalize_no_show')
                    ->label('Finalize No-Show')
                    ->icon('heroicon-m-eye-slash')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->authorize(fn (Lesson $record): bool => auth()->user()?->can('complete', $record) ?? false)
                    ->visible(fn (Lesson $record): bool => $record->status->isOpen()
                        && ($record->student_attendance_status === LessonAttendanceStatus::NoShow
                            || $record->instructor_attendance_status === LessonAttendanceStatus::NoShow))
                    ->action(fn (Lesson $record) => self::callService(
                        fn (LessonLifecycleServiceInterface $service) => $service->finalizeNoShow($record, auth()->user()),
                        'Lesson finalized as no-show',
                    )),

                Action::make('dispute')
                    ->icon('heroicon-m-exclamation-triangle')
                    ->color('danger')
                    ->authorize(fn (Lesson $record): bool => auth()->user()?->can('dispute', $record) ?? false)
                    ->visible(fn (Lesson $record): bool => $record->status->canTransitionTo(LessonStatus::Disputed))
                    ->form([
                        Textarea::make('reason')->required()->maxLength(1000),
                    ])
                    ->action(fn (Lesson $record, array $data) => self::callService(
                        fn (LessonLifecycleServiceInterface $service) => $service->dispute($record, auth()->user(), $data['reason']),
                        'Lesson marked disputed',
                    )),

                Action::make('cancel')
                    ->icon('heroicon-m-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->authorize(fn (Lesson $record): bool => auth()->user()?->can('cancel', $record) ?? false)
                    ->visible(fn (Lesson $record): bool => $record->status->canTransitionTo(LessonStatus::Cancelled))
                    ->form([
                        Textarea::make('reason')->maxLength(500),
                    ])
                    ->action(fn (Lesson $record, array $data) => self::callService(
                        fn (LessonLifecycleServiceInterface $service) => $service->cancel($record, auth()->user(), $data['reason'] ?? null),
                        'Lesson cancelled',
                    )),
            ])
            ->defaultSort('starts_at', 'desc');
    }

    /** Run a service call, converting domain failures into panel notifications. */
    private static function callService(callable $callback, string $successTitle): void
    {
        try {
            $callback(app(LessonLifecycleServiceInterface::class));
            Notification::make()->title($successTitle)->success()->send();
        } catch (LessonException $e) {
            Notification::make()->title('Action failed')->body($e->getMessage())->danger()->send();
        }
    }
}
