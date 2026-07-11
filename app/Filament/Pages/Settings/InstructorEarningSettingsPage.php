<?php

declare(strict_types=1);

namespace App\Filament\Pages\Settings;

use App\Earnings\Enums\CompensationAgreementStatus;
use App\Earnings\Enums\CompensationPayBasis;
use App\Earnings\Services\CompensationActivationPreflight;
use App\Filament\Resources\InstructorCompensationAgreements\InstructorCompensationAgreementResource;
use App\Models\InstructorCompensationAgreement;
use App\Models\User;
use App\Settings\InstructorEarningSettings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions as ActionsComponent;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form as FormComponent;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

/**
 * Global instructor payout rules. Simple platform-wide defaults this
 * phase — per-instructor and per-subject rules are future phases.
 * Amounts are integer minor units.
 */
class InstructorEarningSettingsPage extends Page
{
    use HasSettingsAccess;
    use LogsSettingsUpdates;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $navigationLabel = 'Instructor Earnings';

    protected static string|\UnitEnum|null $navigationGroup = 'Platform';

    protected static ?int $navigationSort = 9;

    protected static ?string $slug = 'settings/instructor-earnings';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function getLabel(): string
    {
        return 'Instructor Earning Settings';
    }

    public function getTitle(): string|Htmlable
    {
        return 'Instructor Earning Settings';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Payout rules: how instructor earnings are calculated, held, released, and settled. No external transfers are executed.';
    }

    public function mount(): void
    {
        $settings = app(InstructorEarningSettings::class);

        $this->form->fill([
            'earnings_enabled' => $settings->earnings_enabled,
            'periodic_compensation_enabled' => $settings->periodic_compensation_enabled,
            'demo_compensation_policy' => $settings->demo_compensation_policy,
            'demo_fixed_amount_minor' => $settings->demo_fixed_amount_minor,
            'hold_days' => $settings->hold_days,
            'auto_release_enabled' => $settings->auto_release_enabled,
            'minimum_settlement_amount_minor' => $settings->minimum_settlement_amount_minor,
            'settlement_frequency' => $settings->settlement_frequency,
            'withdrawals_enabled' => $settings->withdrawals_enabled,
            'minimum_withdrawal_minor' => $settings->minimum_withdrawal_minor,
            'maximum_withdrawal_minor' => $settings->maximum_withdrawal_minor,
            'maximum_active_requests_per_instructor' => $settings->maximum_active_requests_per_instructor,
            'payout_method_verification_required' => $settings->payout_method_verification_required,
            'instructor_cancellation_enabled' => $settings->instructor_cancellation_enabled,
            'withdrawal_review_required' => $settings->withdrawal_review_required,
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            FormComponent::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    ActionsComponent::make([
                        Action::make('save')
                            ->label('Save Settings')
                            ->submit('save')
                            ->keyBindings(['mod+s']),
                    ])->key('form-actions'),
                ]),
        ]);
    }

    public function defaultForm(Schema $schema): Schema
    {
        return $schema->statePath('data');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(2)->schema([
                Section::make('Earnings Operations')
                    ->description('Operational switches only. No compensation amount is configured globally.')
                    ->columnSpanFull()
                    ->schema([
                        Grid::make(2)->schema([
                            Toggle::make('earnings_enabled')
                                ->label('Earnings enabled')
                                ->helperText('Off = no automatic earning creation (lesson-based and periodic accrual). Enabling runs a server-side preflight — every payable instructor must have a valid agreement first.'),
                            Toggle::make('periodic_compensation_enabled')
                                ->label('Periodic compensation enabled')
                                ->helperText('Daily/weekly/monthly agreements pay fixed contractual amounts per period regardless of taught lessons. Keep OFF until attendance, leave, and partial-period rules are defined. Hourly agreements are unaffected.'),
                        ]),
                    ]),

                Section::make('Instructor Compensation')
                    ->description('Instructor compensation is configured individually through effective-dated compensation agreements. It is never calculated from the student-facing price.')
                    ->columnSpanFull()
                    ->schema([
                        Placeholder::make('compensation_overview')
                            ->hiddenLabel()
                            ->content(fn (): HtmlString => $this->compensationOverview()),
                        ActionsComponent::make([
                            Action::make('manage_compensation')
                                ->label('Manage Instructor Compensation')
                                ->icon('heroicon-m-briefcase')
                                ->url(InstructorCompensationAgreementResource::getUrl()),
                        ])->key('compensation-actions'),
                    ]),

                Section::make('Demo Lesson Compensation')
                    ->description('Demo lessons stay free to students. Demo compensation is an explicit policy, never derived from student pricing.')
                    ->columnSpanFull()
                    ->schema([
                        Grid::make(2)->schema([
                            Select::make('demo_compensation_policy')
                                ->label('Demo policy')
                                ->options([
                                    'none' => 'None (no demo compensation)',
                                    'fixed_demo_amount' => 'Fixed amount per demo lesson',
                                ])
                                ->required()
                                ->native(false),
                            TextInput::make('demo_fixed_amount_minor')
                                ->label('Fixed demo amount (minor units)')
                                ->helperText('Required when the policy is fixed. e.g. 15000 = 150.00 in the agreement currency.')
                                ->numeric()
                                ->minValue(1),
                        ]),
                    ]),

                Section::make('Hold & Release')
                    ->columnSpanFull()
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('hold_days')
                                ->label('Hold period (days)')
                                ->helperText('Dispute window after lesson completion before an earning can be released.')
                                ->numeric()
                                ->minValue(0)
                                ->required(),
                            Toggle::make('auto_release_enabled')
                                ->label('Auto-release after hold')
                                ->helperText('Gates the hourly instructor-earnings:release sweep.'),
                        ]),
                    ]),

                Section::make('Settlement')
                    ->columnSpanFull()
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('minimum_settlement_amount_minor')
                                ->label('Minimum batch amount (minor units)')
                                ->helperText('Empty = no minimum.')
                                ->numeric()
                                ->minValue(0),
                            Select::make('settlement_frequency')
                                ->label('Settlement frequency')
                                ->options([
                                    'manual' => 'Manual (admin-created batches)',
                                    'weekly' => 'Weekly (informational)',
                                    'monthly' => 'Monthly (informational)',
                                ])
                                ->required()
                                ->native(false),
                        ]),
                    ]),

                Section::make('Withdrawals')
                    ->description('Phase 15: instructors request payouts of released earnings to verified payout methods. Requests only reserve earnings — no external transfer is ever executed.')
                    ->columnSpanFull()
                    ->schema([
                        Grid::make(3)->schema([
                            Toggle::make('withdrawals_enabled')
                                ->label('Withdrawals enabled')
                                ->helperText('Off = instructors cannot submit withdrawal requests.'),
                            Toggle::make('payout_method_verification_required')
                                ->label('Verified payout method required')
                                ->helperText('Keep on in production.'),
                            Toggle::make('withdrawal_review_required')
                                ->label('Review before approval')
                                ->helperText('On = requests must enter review before they can be approved.'),
                        ]),
                        Grid::make(3)->schema([
                            TextInput::make('minimum_withdrawal_minor')
                                ->label('Minimum withdrawal (minor units)')
                                ->helperText('e.g. 50000 = 500.00.')
                                ->numeric()
                                ->minValue(0)
                                ->required(),
                            TextInput::make('maximum_withdrawal_minor')
                                ->label('Maximum withdrawal (minor units)')
                                ->helperText('Empty = no cap.')
                                ->numeric()
                                ->minValue(1),
                            TextInput::make('maximum_active_requests_per_instructor')
                                ->label('Max open requests per instructor')
                                ->numeric()
                                ->minValue(1)
                                ->required(),
                        ]),
                        Toggle::make('instructor_cancellation_enabled')
                            ->label('Instructors may cancel their own open requests'),
                    ]),
            ]),
        ]);
    }

    public function save(): void
    {
        try {
            $data = $this->form->getState();
        } catch (Halt) {
            return;
        }

        $settings = app(InstructorEarningSettings::class);
        $before = $this->snapshotSettings($settings);

        // Phase 14.3 — enabling earnings requires a passing server-side
        // preflight: every payable instructor agreed, no overlaps, active
        // currencies, no open compensation exceptions, periodic gate
        // consistent. Failures block the save and name the subjects.
        if ((bool) $data['earnings_enabled'] && ! $settings->earnings_enabled) {
            // The periodic gate the admin is saving now is part of the
            // preflight input — apply it (in memory) before checking.
            $originalPeriodicGate = $settings->periodic_compensation_enabled;
            $settings->periodic_compensation_enabled = (bool) $data['periodic_compensation_enabled'];

            $failures = app(CompensationActivationPreflight::class)->failures();

            if ($failures !== []) {
                $settings->periodic_compensation_enabled = $originalPeriodicGate;
                $body = collect($failures)
                    ->map(fn (array $failure): string => $failure['message']
                        .($failure['subjects'] !== [] ? ' — '.implode(', ', array_slice($failure['subjects'], 0, 10)).(count($failure['subjects']) > 10 ? '…' : '') : ''))
                    ->implode(' • ');

                Notification::make()
                    ->title('Earnings cannot be enabled yet')
                    ->body($body)
                    ->danger()
                    ->persistent()
                    ->send();

                return;
            }
        }

        $settings->earnings_enabled = (bool) $data['earnings_enabled'];
        $settings->periodic_compensation_enabled = (bool) $data['periodic_compensation_enabled'];
        $settings->demo_compensation_policy = $data['demo_compensation_policy'];
        $settings->demo_fixed_amount_minor = $data['demo_fixed_amount_minor'] !== null && $data['demo_fixed_amount_minor'] !== ''
            ? (int) $data['demo_fixed_amount_minor']
            : null;
        $settings->hold_days = (int) $data['hold_days'];
        $settings->auto_release_enabled = (bool) $data['auto_release_enabled'];
        $settings->minimum_settlement_amount_minor = $data['minimum_settlement_amount_minor'] !== null && $data['minimum_settlement_amount_minor'] !== ''
            ? (int) $data['minimum_settlement_amount_minor']
            : null;
        $settings->settlement_frequency = $data['settlement_frequency'];
        $settings->withdrawals_enabled = (bool) $data['withdrawals_enabled'];
        $settings->minimum_withdrawal_minor = (int) $data['minimum_withdrawal_minor'];
        $settings->maximum_withdrawal_minor = $data['maximum_withdrawal_minor'] !== null && $data['maximum_withdrawal_minor'] !== ''
            ? (int) $data['maximum_withdrawal_minor']
            : null;
        $settings->maximum_active_requests_per_instructor = max(1, (int) $data['maximum_active_requests_per_instructor']);
        $settings->payout_method_verification_required = (bool) $data['payout_method_verification_required'];
        $settings->instructor_cancellation_enabled = (bool) $data['instructor_cancellation_enabled'];
        $settings->withdrawal_review_required = (bool) $data['withdrawal_review_required'];

        $settings->save();

        $this->logSettingsUpdate('settings', $settings, $before);

        Notification::make()->title('Instructor earning settings saved')->success()->send();
    }

    /** Read-only operational counts for the compensation section. */
    private function compensationOverview(): HtmlString
    {
        $byStatus = InstructorCompensationAgreement::query()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $byBasis = InstructorCompensationAgreement::query()
            ->active()
            ->selectRaw('pay_basis, COUNT(*) as total')
            ->groupBy('pay_basis')
            ->pluck('total', 'pay_basis');

        $missing = User::query()
            ->whereHas('roles', fn ($q) => $q->where('name', 'instructor'))
            ->whereDoesntHave('compensationAgreements', fn ($q) => $q->where('status', CompensationAgreementStatus::Active))
            ->count();

        $line = fn (string $label, int $value): string => sprintf('<div class="flex justify-between gap-8"><span>%s</span><strong>%d</strong></div>', e($label), $value);

        return new HtmlString(
            '<div class="grid grid-cols-1 gap-1 text-sm sm:grid-cols-2">'
            .$line('Active agreements', (int) ($byStatus[CompensationAgreementStatus::Active->value] ?? 0))
            .$line('Scheduled agreements', (int) ($byStatus[CompensationAgreementStatus::Scheduled->value] ?? 0))
            .$line('Instructors missing active agreements', $missing)
            .$line('Hourly agreements (active)', (int) ($byBasis[CompensationPayBasis::Hourly->value] ?? 0))
            .$line('Daily agreements (active)', (int) ($byBasis[CompensationPayBasis::Daily->value] ?? 0))
            .$line('Weekly agreements (active)', (int) ($byBasis[CompensationPayBasis::Weekly->value] ?? 0))
            .$line('Monthly agreements (active)', (int) ($byBasis[CompensationPayBasis::Monthly->value] ?? 0))
            .'</div>'
        );
    }
}
