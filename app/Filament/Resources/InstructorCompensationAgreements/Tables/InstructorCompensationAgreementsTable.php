<?php

declare(strict_types=1);

namespace App\Filament\Resources\InstructorCompensationAgreements\Tables;

use App\Earnings\Contracts\InstructorCompensationAgreementServiceInterface;
use App\Earnings\Enums\CompensationAgreementStatus;
use App\Earnings\Enums\CompensationPayBasis;
use App\Earnings\Exceptions\EarningException;
use App\Filament\Support\Tables\AdminListTable;
use App\Models\AcademicLevel;
use App\Models\Currency;
use App\Models\InstructorCompensationAgreement;
use App\Models\Subject;
use App\Support\MoneyFormatter;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;

/**
 * No generic edit, no status dropdown, no delete. Financial terms are
 * shown read-only; every lifecycle mutation is a dedicated action that
 * requires the relevant permission, takes a mandatory internal reason
 * where the policy demands one, and calls
 * InstructorCompensationAgreementService. Student pricing appears
 * nowhere on this surface.
 */
class InstructorCompensationAgreementsTable
{
    public static function configure(Table $table): Table
    {
        $table
            ->columns([
                TextColumn::make('reference')
                    ->label('Reference')
                    ->searchable()
                    ->fontFamily('mono')
                    ->copyable(),
                TextColumn::make('instructor.name')
                    ->label('Instructor')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('pay_basis')
                    ->label('Basis')
                    ->formatStateUsing(fn (CompensationPayBasis $state): string => $state->shortLabel()),
                TextColumn::make('amount_minor')
                    ->label('Agreed Amount')
                    ->state(fn (InstructorCompensationAgreement $record): string => MoneyFormatter::format($record->amount_minor, $record->currency_code))
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (CompensationAgreementStatus $state): string => $state->label())
                    ->color(fn (CompensationAgreementStatus $state): string => $state->color()),
                TextColumn::make('version')
                    ->label('v')
                    ->sortable(),
                TextColumn::make('effective_from')
                    ->label('Effective From')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('effective_until')
                    ->label('Until')
                    ->dateTime()
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('timezone')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('approver.name')
                    ->label('Approved By')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(CompensationAgreementStatus::cases())
                        ->mapWithKeys(fn (CompensationAgreementStatus $s) => [$s->value => $s->label()])
                        ->toArray()),
                SelectFilter::make('pay_basis')
                    ->label('Basis')
                    ->options(collect(CompensationPayBasis::cases())
                        ->mapWithKeys(fn (CompensationPayBasis $b) => [$b->value => $b->shortLabel()])
                        ->toArray()),
                SelectFilter::make('instructor_id')
                    ->label('Instructor')
                    ->relationship('instructor', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->recordActions([
                Action::make('view_details')
                    ->label('View')
                    ->icon('heroicon-m-eye')
                    ->color('gray')
                    ->modalHeading('Agreement & decision context')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->authorize(fn (InstructorCompensationAgreement $record): bool => auth()->user()?->can('view', $record) ?? false)
                    ->modalContent(fn (InstructorCompensationAgreement $record): View => view('filament.compensation.agreement-details', [
                        'agreement' => $record->load(['instructor.profile', 'instructor.educations', 'instructor.teacherSubjects.subject', 'overrides.subject', 'overrides.academicLevel']),
                    ])),

                Action::make('add_override')
                    ->label('Add Override')
                    ->icon('heroicon-m-adjustments-horizontal')
                    ->color('gray')
                    ->authorize(fn (InstructorCompensationAgreement $record): bool => auth()->user()?->can('schedule', $record) ?? false)
                    ->visible(fn (InstructorCompensationAgreement $record): bool => $record->pay_basis === CompensationPayBasis::Hourly && $record->status->isEditable())
                    ->form([
                        Select::make('subject_id')
                            ->label('Subject')
                            ->options(fn (): array => Subject::query()->orderBy('name')->pluck('name', 'id')->toArray())
                            ->searchable()
                            ->nullable(),
                        Select::make('academic_level_id')
                            ->label('Education level')
                            ->options(fn (): array => AcademicLevel::query()->orderBy('name')->pluck('name', 'id')->toArray())
                            ->searchable()
                            ->nullable(),
                        TextInput::make('duration_minutes')
                            ->label('Lesson duration (minutes)')
                            ->numeric()
                            ->minValue(15)
                            ->maxValue(480)
                            ->nullable(),
                        TextInput::make('amount_minor')
                            ->label('Hourly override amount (smallest currency unit)')
                            ->numeric()
                            ->minValue(1)
                            ->required()
                            ->helperText(fn (InstructorCompensationAgreement $record): string => sprintf(
                                'Enter the amount in the smallest unit of this agreement\'s currency (%s) — e.g. 10000 equals 100.00 in its main unit.',
                                $record->currency_code,
                            )),
                    ])
                    ->action(fn (InstructorCompensationAgreement $record, array $data) => self::callService(
                        fn (InstructorCompensationAgreementServiceInterface $service) => $service->addOverride(
                            $record,
                            auth()->user(),
                            $data['subject_id'] ?? null,
                            $data['academic_level_id'] ?? null,
                            isset($data['duration_minutes']) && $data['duration_minutes'] !== null && $data['duration_minutes'] !== '' ? (int) $data['duration_minutes'] : null,
                            (int) $data['amount_minor'],
                        ),
                        'Override added',
                    )),

                Action::make('schedule')
                    ->icon('heroicon-m-clock')
                    ->color('info')
                    ->requiresConfirmation()
                    ->authorize(fn (InstructorCompensationAgreement $record): bool => auth()->user()?->can('schedule', $record) ?? false)
                    ->visible(fn (InstructorCompensationAgreement $record): bool => $record->status === CompensationAgreementStatus::Draft)
                    ->action(fn (InstructorCompensationAgreement $record) => self::callService(
                        fn (InstructorCompensationAgreementServiceInterface $service) => $service->schedule($record, auth()->user()),
                        'Agreement scheduled',
                    )),

                Action::make('activate')
                    ->icon('heroicon-m-check-badge')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription('Activates this agreement and freezes its financial terms. Rate changes will require a replacement agreement.')
                    ->authorize(fn (InstructorCompensationAgreement $record): bool => auth()->user()?->can('activate', $record) ?? false)
                    ->visible(fn (InstructorCompensationAgreement $record): bool => in_array($record->status, [CompensationAgreementStatus::Draft, CompensationAgreementStatus::Scheduled], true))
                    ->form([
                        Textarea::make('reason')
                            ->label('Internal reason')
                            ->required()
                            ->maxLength(1000)
                            ->helperText('Admin-only. Never shown to the instructor.'),
                    ])
                    ->action(fn (InstructorCompensationAgreement $record, array $data) => self::callService(
                        fn (InstructorCompensationAgreementServiceInterface $service) => $service->activate($record, auth()->user(), $data['reason']),
                        'Agreement activated',
                    )),

                Action::make('replace')
                    ->label('Replace')
                    ->icon('heroicon-m-arrow-path')
                    ->color('warning')
                    ->authorize(fn (InstructorCompensationAgreement $record): bool => auth()->user()?->can('replace', $record) ?? false)
                    ->visible(fn (InstructorCompensationAgreement $record): bool => $record->status === CompensationAgreementStatus::Active)
                    ->modalDescription('Ends this agreement at the new effective date and creates the successor. History is preserved; past earnings are never touched.')
                    ->form([
                        Select::make('pay_basis')
                            ->label('New basis')
                            ->options(collect(CompensationPayBasis::cases())->mapWithKeys(fn (CompensationPayBasis $b) => [$b->value => $b->label()])->toArray())
                            ->required()
                            ->native(false),
                        TextInput::make('amount_minor')
                            ->label('New agreed amount (smallest currency unit)')
                            ->numeric()
                            ->minValue(1)
                            ->required()
                            ->helperText('Enter the amount in the smallest unit of the currency below — e.g. paise for INR, cents for USD.'),
                        Select::make('currency_code')
                            ->label('Currency')
                            ->options(fn (): array => Currency::query()->active()->orderBy('sort_order')->pluck('code', 'code')->toArray())
                            ->required()
                            ->native(false),
                        DateTimePicker::make('effective_from')
                            ->label('Cutover (new agreement starts)')
                            ->helperText('Periodic bases must start on their period boundary in the agreement timezone.')
                            ->required(),
                        Textarea::make('reason')
                            ->label('Internal reason')
                            ->required()
                            ->maxLength(1000),
                    ])
                    ->action(fn (InstructorCompensationAgreement $record, array $data) => self::callService(
                        fn (InstructorCompensationAgreementServiceInterface $service) => $service->replace(
                            $record,
                            auth()->user(),
                            CompensationPayBasis::from($data['pay_basis']),
                            (int) $data['amount_minor'],
                            $data['currency_code'],
                            // Entered wall time is in the agreement timezone.
                            new \DateTimeImmutable($data['effective_from'], new \DateTimeZone($record->timezone)),
                            $data['reason'],
                        ),
                        'Replacement agreement created',
                    )),

                Action::make('end')
                    ->icon('heroicon-m-stop-circle')
                    ->color('danger')
                    ->authorize(fn (InstructorCompensationAgreement $record): bool => auth()->user()?->can('end', $record) ?? false)
                    ->visible(fn (InstructorCompensationAgreement $record): bool => $record->status === CompensationAgreementStatus::Active)
                    ->form([
                        DateTimePicker::make('effective_until')
                            ->label('Ends at')
                            ->helperText('Periodic bases must end on their period boundary in the agreement timezone.')
                            ->required(),
                        Textarea::make('reason')
                            ->label('Internal reason')
                            ->required()
                            ->maxLength(1000),
                    ])
                    ->action(fn (InstructorCompensationAgreement $record, array $data) => self::callService(
                        fn (InstructorCompensationAgreementServiceInterface $service) => $service->end($record, auth()->user(), new \DateTimeImmutable($data['effective_until'], new \DateTimeZone($record->timezone)), $data['reason']),
                        'Agreement end recorded',
                    )),

                Action::make('cancel')
                    ->icon('heroicon-m-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->authorize(fn (InstructorCompensationAgreement $record): bool => auth()->user()?->can('cancel', $record) ?? false)
                    ->visible(fn (InstructorCompensationAgreement $record): bool => in_array($record->status, [CompensationAgreementStatus::Draft, CompensationAgreementStatus::Scheduled], true))
                    ->form([
                        Textarea::make('reason')->label('Internal reason')->maxLength(1000),
                    ])
                    ->action(fn (InstructorCompensationAgreement $record, array $data) => self::callService(
                        fn (InstructorCompensationAgreementServiceInterface $service) => $service->cancel($record, auth()->user(), $data['reason'] ?? null),
                        'Agreement cancelled',
                    )),

                Action::make('view_history')
                    ->label('History')
                    ->icon('heroicon-m-clock')
                    ->color('gray')
                    ->modalHeading('Compensation history')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->authorize(fn (InstructorCompensationAgreement $record): bool => auth()->user()?->can('viewHistory', $record) ?? false)
                    ->modalContent(fn (InstructorCompensationAgreement $record): View => view('filament.compensation.agreement-history', [
                        'agreements' => InstructorCompensationAgreement::query()
                            ->forInstructor($record->instructor_id)
                            ->orderByDesc('effective_from')
                            ->orderByDesc('version')
                            ->get(),
                    ])),
            ])
            ->defaultSort('created_at', 'desc');

        return AdminListTable::apply($table);
    }

    private static function callService(callable $callback, string $successTitle): void
    {
        try {
            $callback(app(InstructorCompensationAgreementServiceInterface::class));
            Notification::make()->title($successTitle)->success()->send();
        } catch (EarningException $e) {
            Notification::make()->title('Action failed')->body($e->getMessage())->danger()->send();
        }
    }
}
