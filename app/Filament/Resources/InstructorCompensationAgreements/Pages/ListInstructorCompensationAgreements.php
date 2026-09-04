<?php

declare(strict_types=1);

namespace App\Filament\Resources\InstructorCompensationAgreements\Pages;

use App\Earnings\Contracts\InstructorCompensationAgreementServiceInterface;
use App\Earnings\Enums\CompensationAgreementStatus;
use App\Earnings\Enums\CompensationPayBasis;
use App\Earnings\Exceptions\EarningException;
use App\Filament\Concerns\HasRelatedResourceLinks;
use App\Filament\Resources\InstructorCompensationAgreements\InstructorCompensationAgreementResource;
use App\Filament\Support\RelatedResourceLinkGroups;
use App\Filament\Support\Tables\StatusTabs;
use App\Models\Currency;
use App\Models\InstructorCompensationAgreement;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;

class ListInstructorCompensationAgreements extends ListRecords
{
    use HasRelatedResourceLinks;

    protected static string $resource = InstructorCompensationAgreementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('create_draft')
                ->label('New Agreement')
                ->icon('heroicon-m-plus')
                ->authorize(fn (): bool => auth()->user()?->can('create', InstructorCompensationAgreement::class) ?? false)
                ->modalDescription('The agreed amount is an internal decision informed by experience, qualifications, and expertise — it is never calculated from student pricing.')
                ->form([
                    Select::make('instructor_id')
                        ->label('Instructor')
                        ->options(fn (): array => User::query()
                            ->whereHas('roles', fn ($q) => $q->where('name', 'instructor'))
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->toArray())
                        ->searchable()
                        ->required(),
                    Select::make('pay_basis')
                        ->label('Compensation basis')
                        ->options(collect(CompensationPayBasis::cases())->mapWithKeys(fn (CompensationPayBasis $b) => [$b->value => $b->label()])->toArray())
                        ->helperText('Compensation basis, not settlement frequency — settlement and hold rules stay independent.')
                        ->required()
                        ->native(false),
                    TextInput::make('amount_minor')
                        ->label('Agreed amount (smallest currency unit)')
                        ->helperText('Enter the amount in the smallest unit of the currency below — e.g. 80000 equals 800.00 per hour/day/week/month, depending on the basis chosen.')
                        ->numeric()
                        ->minValue(1)
                        ->required(),
                    Select::make('currency_code')
                        ->label('Currency')
                        ->options(fn (): array => Currency::query()->active()->orderBy('sort_order')->pluck('code', 'code')->toArray())
                        ->required()
                        ->native(false),
                    TextInput::make('timezone')
                        ->label('Agreement timezone')
                        ->default(config('app.timezone'))
                        ->helperText('Controls day/week/month boundaries for periodic bases.')
                        ->required(),
                    DateTimePicker::make('effective_from')
                        ->label('Effective from')
                        ->helperText('Daily: 00:00 · Weekly: Monday 00:00 · Monthly: the 1st, 00:00 — in the agreement timezone. Partial periods are handled through audited adjustments, never proration.')
                        ->required(),
                    Textarea::make('internal_reason')
                        ->label('Internal reason')
                        ->helperText('Admin-only decision context (experience, qualifications, expertise). Never shown to the instructor.')
                        ->required()
                        ->maxLength(1000),
                    Textarea::make('notes')
                        ->label('Notes (admin-only)')
                        ->maxLength(1000),
                ])
                ->action(function (array $data): void {
                    try {
                        app(InstructorCompensationAgreementServiceInterface::class)->createDraft(
                            auth()->user(),
                            User::query()->findOrFail($data['instructor_id']),
                            CompensationPayBasis::from($data['pay_basis']),
                            (int) $data['amount_minor'],
                            $data['currency_code'],
                            $data['timezone'],
                            // Entered wall time is in the agreement timezone.
                            new \DateTimeImmutable($data['effective_from'], new \DateTimeZone($data['timezone'])),
                            $data['internal_reason'],
                            $data['notes'] ?? null,
                        );

                        Notification::make()->title('Draft agreement created')->success()->send();
                    } catch (EarningException $e) {
                        Notification::make()->title('Action failed')->body($e->getMessage())->danger()->send();
                    }
                }),
        ];
    }

    protected function getRelatedResourceLinks(): array
    {
        return RelatedResourceLinkGroups::instructorFinance();
    }

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        return StatusTabs::forEnum(InstructorCompensationAgreement::class, CompensationAgreementStatus::class);
    }
}
