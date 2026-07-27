<?php

declare(strict_types=1);

namespace App\Filament\Pages\Settings;

use App\Earnings\Contracts\FinancialFeatureConfigurationServiceInterface;
use App\Earnings\Enums\CompensationAgreementStatus;
use App\Earnings\Enums\CompensationPayBasis;
use App\Earnings\Exceptions\CompensationException;
use App\Filament\Navigation\Concerns\HasCentralizedNavigation;
use App\Filament\Resources\InstructorCompensationAgreements\InstructorCompensationAgreementResource;
use App\Models\InstructorCompensationAgreement;
use App\Models\InstructorPayoutAttempt;
use App\Models\InstructorPayoutReconciliationIssue;
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
    use HasCentralizedNavigation;
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
            'payout_execution_enabled' => $settings->payout_execution_enabled,
            'payout_provider' => $settings->payout_provider,
            'payout_maker_checker_enabled' => $settings->payout_maker_checker_enabled,
            'payout_auto_retry_enabled' => $settings->payout_auto_retry_enabled,
            'payout_reconciliation_enabled' => $settings->payout_reconciliation_enabled,
            'payout_max_attempts' => $settings->payout_max_attempts,
            'payout_unknown_timeout_minutes' => $settings->payout_unknown_timeout_minutes,
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
                    ->description('Instructors request payouts of released earnings to verified payout methods. Requests only reserve earnings — no external transfer is ever executed.')
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

                Section::make('Payout Execution')
                    ->description('Executes approved withdrawals via a provider-neutral internal pipeline. Only the deterministic fake provider is registered — no external payout API exists yet.')
                    ->columnSpanFull()
                    ->schema([
                        Placeholder::make('payout_execution_overview')
                            ->hiddenLabel()
                            ->content(fn (): HtmlString => $this->payoutExecutionOverview()),
                        Grid::make(3)->schema([
                            Toggle::make('payout_execution_enabled')
                                ->label('Payout execution enabled')
                                ->helperText('Off = no provider is ever called. Enabling runs a server-side preflight and requires the Configure:InstructorPayoutExecution permission (enforced on save, not just in this form).'),
                            Select::make('payout_provider')
                                ->label('Provider')
                                ->options(['fake' => 'Fake (deterministic, no network calls)'])
                                ->required()
                                ->native(false)
                                ->helperText('Only the fake provider is registered.'),
                            Toggle::make('payout_maker_checker_enabled')
                                ->label('Maker-checker enabled')
                                ->helperText('On = the withdrawal approver cannot also execute its payout.'),
                        ]),
                        Grid::make(3)->schema([
                            Toggle::make('payout_auto_retry_enabled')
                                ->label('Automatic retry enabled')
                                ->helperText('Off by default. Only safe categories retry, bounded by the attempt ceiling.'),
                            Toggle::make('payout_reconciliation_enabled')
                                ->label('Reconciliation sweep enabled')
                                ->helperText('Gates instructor-payouts:reconcile.'),
                            TextInput::make('payout_max_attempts')
                                ->label('Max attempts per execution')
                                ->numeric()
                                ->minValue(1)
                                ->required(),
                        ]),
                        TextInput::make('payout_unknown_timeout_minutes')
                            ->label('Unknown/processing reconciliation timeout (minutes)')
                            ->numeric()
                            ->minValue(1)
                            ->required(),
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

        // The three financial feature switches flow ONLY through
        // FinancialFeatureConfigurationService, which enforces
        // the activation preflights regardless of caller. This page just
        // relays the request and displays the service's readiness result.
        $configuration = app(FinancialFeatureConfigurationServiceInterface::class);

        try {
            $this->applySwitch($configuration, 'earnings_enabled', (bool) $data['earnings_enabled'], $settings->earnings_enabled);
            // disableEarnings() auto-disables periodic — refresh view state.
            $this->applySwitch($configuration, 'periodic_compensation_enabled', (bool) $data['periodic_compensation_enabled'], $settings->periodic_compensation_enabled);
            $this->applySwitch($configuration, 'withdrawals_enabled', (bool) $data['withdrawals_enabled'], $settings->withdrawals_enabled);

            $requestedPayoutExecutionEnabled = (bool) ($data['payout_execution_enabled'] ?? $settings->payout_execution_enabled);

            if ($requestedPayoutExecutionEnabled !== $settings->payout_execution_enabled) {
                if (! (auth()->user()?->can('Configure:InstructorPayoutExecution') ?? false)) {
                    throw new CompensationException('You do not have permission to change payout execution.');
                }

                $this->applySwitch($configuration, 'payout_execution_enabled', $requestedPayoutExecutionEnabled, $settings->payout_execution_enabled);
            }
        } catch (CompensationException $e) {
            Notification::make()
                ->title('Feature cannot be enabled yet')
                ->body($e->getMessage())
                ->danger()
                ->persistent()
                ->send();

            $this->mount(); // reset the form to the persisted state

            return;
        }

        // The three financial switches above already persisted (and
        // audited, via FinancialFeatureConfigurationService) through
        // applySwitch() — $settings is a scoped singleton, so it already
        // reflects those changes here. This final atomic step covers the
        // remaining, non-preflighted fields only.
        $saved = $this->saveSettingsWithAudit(InstructorEarningSettings::class, 'settings', function (InstructorEarningSettings $settings) use ($data): void {
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
            $settings->minimum_withdrawal_minor = (int) $data['minimum_withdrawal_minor'];
            $settings->maximum_withdrawal_minor = $data['maximum_withdrawal_minor'] !== null && $data['maximum_withdrawal_minor'] !== ''
                ? (int) $data['maximum_withdrawal_minor']
                : null;
            $settings->maximum_active_requests_per_instructor = max(1, (int) $data['maximum_active_requests_per_instructor']);
            $settings->payout_method_verification_required = (bool) $data['payout_method_verification_required'];
            $settings->instructor_cancellation_enabled = (bool) $data['instructor_cancellation_enabled'];
            $settings->withdrawal_review_required = (bool) $data['withdrawal_review_required'];
            $settings->payout_provider = $data['payout_provider'];
            $settings->payout_maker_checker_enabled = (bool) $data['payout_maker_checker_enabled'];
            $settings->payout_auto_retry_enabled = (bool) $data['payout_auto_retry_enabled'];
            $settings->payout_reconciliation_enabled = (bool) $data['payout_reconciliation_enabled'];
            $settings->payout_max_attempts = max(1, (int) $data['payout_max_attempts']);
            $settings->payout_unknown_timeout_minutes = max(1, (int) $data['payout_unknown_timeout_minutes']);
        });

        if (! $saved) {
            return;
        }

        Notification::make()->title('Instructor earning settings saved')->success()->send();
    }

    private function applySwitch(
        FinancialFeatureConfigurationServiceInterface $configuration,
        string $switch,
        bool $requested,
        bool $current,
    ): void {
        if ($requested === $current) {
            return;
        }

        match ([$switch, $requested]) {
            ['earnings_enabled', true] => $configuration->enableEarnings(auth()->user()),
            ['earnings_enabled', false] => $configuration->disableEarnings(auth()->user()),
            ['periodic_compensation_enabled', true] => $configuration->enablePeriodicCompensation(auth()->user()),
            ['periodic_compensation_enabled', false] => $configuration->disablePeriodicCompensation(auth()->user()),
            ['withdrawals_enabled', true] => $configuration->enableWithdrawals(auth()->user()),
            ['withdrawals_enabled', false] => $configuration->disableWithdrawals(auth()->user()),
            ['payout_execution_enabled', true] => $configuration->enablePayoutExecution(auth()->user()),
            ['payout_execution_enabled', false] => $configuration->disablePayoutExecution(auth()->user()),
        };
    }

    /** Read-only operational counts for the payout execution section. */
    private function payoutExecutionOverview(): HtmlString
    {
        $byStatus = InstructorPayoutAttempt::query()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $openIssues = InstructorPayoutReconciliationIssue::query()->open()->count();

        $line = fn (string $label, int $value): string => sprintf('<div class="flex justify-between gap-8"><span>%s</span><strong>%d</strong></div>', e($label), $value);

        return new HtmlString(
            '<div class="grid grid-cols-1 gap-1 text-sm sm:grid-cols-2">'
            .$line('Processing attempts', (int) ($byStatus['processing'] ?? 0) + (int) ($byStatus['submitted'] ?? 0) + (int) ($byStatus['acknowledged'] ?? 0))
            .$line('Unknown outcome attempts', (int) ($byStatus['unknown'] ?? 0))
            .$line('Succeeded attempts', (int) ($byStatus['succeeded'] ?? 0))
            .$line('Open reconciliation issues', $openIssues)
            .sprintf(
                '<div class="mt-3 text-sm sm:col-span-2"><strong>Payout execution activation readiness:</strong> %s</div>',
                e(app(FinancialFeatureConfigurationServiceInterface::class)->evaluatePayoutExecutionReadiness()->summary()),
            )
            .'</div>'
        );
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
            .sprintf(
                '<div class="mt-3 text-sm sm:col-span-2"><strong>Earnings activation readiness:</strong> %s</div>',
                e(app(FinancialFeatureConfigurationServiceInterface::class)->evaluateEarningsReadiness()->summary()),
            )
            .'</div>'
        );
    }
}
