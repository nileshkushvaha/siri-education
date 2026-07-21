<?php

declare(strict_types=1);

namespace App\Filament\Pages\Settings;

use App\Settings\BookingSettings;
use App\Settings\FeatureSettings;
use App\Settings\InstructorSettings;
use App\Settings\LocalizationSettings;
use App\Settings\WalletSettings;
use BackedEnum;
use Filament\Actions\Action;
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

class PlatformFoundationSettingsPage extends Page
{
    use HasSettingsAccess;
    use LogsSettingsUpdates;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static ?string $navigationLabel = 'Platform Foundation';

    protected static string|\UnitEnum|null $navigationGroup = 'Platform';

    protected static ?int $navigationSort = 6;

    protected static ?string $slug = 'settings/platform-foundation';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function getLabel(): string
    {
        return 'Platform Foundation Settings';
    }

    public function getTitle(): string|Htmlable
    {
        return 'Platform Foundation Settings';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Prepare booking, wallet, instructor, localization, and feature flag defaults. Referral reward rules live in Referral Campaigns; meeting providers live under Meeting Settings.';
    }

    public function getBreadcrumbs(): array
    {
        return [
            '/admin' => 'Dashboard',
            '/admin/settings/general' => 'Settings',
            '#' => 'Platform Foundation',
        ];
    }

    public function mount(): void
    {
        $booking = app(BookingSettings::class);
        $wallet = app(WalletSettings::class);
        $instructor = app(InstructorSettings::class);
        $localization = app(LocalizationSettings::class);
        $features = app(FeatureSettings::class);

        $this->form->fill([
            'demo_duration_minutes' => $booking->demo_duration_minutes,
            'reservation_expiry_minutes' => $booking->reservation_expiry_minutes,
            'minimum_booking_notice_minutes' => $booking->minimum_booking_notice_minutes,
            'maximum_advance_booking_days' => $booking->maximum_advance_booking_days,
            'cancellation_window_hours' => $booking->cancellation_window_hours,
            'reschedule_limit' => $booking->reschedule_limit,
            'no_show_grace_minutes' => $booking->no_show_grace_minutes,
            'auto_completion_delay_minutes' => $booking->auto_completion_delay_minutes,
            'minimum_recharge_amount' => $wallet->minimum_recharge_amount,
            'maximum_recharge_amount' => $wallet->maximum_recharge_amount,
            'low_balance_threshold' => $wallet->low_balance_threshold,
            'recurring_deduction_hours_before_lesson' => $wallet->recurring_deduction_hours_before_lesson,
            'approval_required' => $instructor->approval_required,
            'profile_publish_requires_approval' => $instructor->profile_publish_requires_approval,
            'featured_instructor_limit' => $instructor->featured_instructor_limit,
            'availability_required_for_public_profile' => $instructor->availability_required_for_public_profile,
            'default_country' => $localization->default_country,
            'country_detection_enabled' => $localization->country_detection_enabled,
            'allow_user_locale_switching' => $localization->allow_user_locale_switching,
            'demo_lessons_enabled' => $features->demo_lessons_enabled,
            'wallet_enabled' => $features->wallet_enabled,
            'referral_enabled' => $features->referral_enabled,
            'waitlist_enabled' => $features->waitlist_enabled,
            'homework_enabled' => $features->homework_enabled,
            'recording_enabled' => $features->recording_enabled,
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
                            ->label('Save Platform Settings')
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
                Section::make('Booking')
                    ->description('Foundation booking windows and lifecycle defaults.')
                    ->schema([
                        Grid::make(2)->schema([
                            $this->integerInput('demo_duration_minutes', 'Demo Duration', 1, 480),
                            $this->integerInput('reservation_expiry_minutes', 'Reservation Expiry', 1, 240),
                            $this->integerInput('minimum_booking_notice_minutes', 'Minimum Notice (minutes)', 0, 43200),
                            $this->integerInput('maximum_advance_booking_days', 'Advance Window (days)', 1, 365),
                            $this->integerInput('cancellation_window_hours', 'Cancellation Window', 0, 720),
                            $this->integerInput('reschedule_limit', 'Reschedule Limit', 0, 20),
                            $this->integerInput('no_show_grace_minutes', 'No-show Grace', 0, 120),
                            $this->integerInput('auto_completion_delay_minutes', 'Auto-completion Delay', 0, 10080),
                        ]),
                    ]),

                Section::make('Wallet')
                    ->description('Stored defaults only; wallet ledger logic ships later. Enable the Wallet module in Feature Flags below.')
                    ->schema([
                        Grid::make(2)->schema([
                            $this->numericInput('minimum_recharge_amount', 'Minimum Recharge', 0),
                            $this->numericInput('maximum_recharge_amount', 'Maximum Recharge', 0),
                            $this->numericInput('low_balance_threshold', 'Low Balance Threshold', 0),
                            $this->integerInput('recurring_deduction_hours_before_lesson', 'Deduct Before Lesson', 0, 720),
                        ]),
                    ]),

                Section::make('Instructor')
                    ->description('Instructor approval and public profile controls.')
                    ->schema([
                        Grid::make(2)->schema([
                            Toggle::make('approval_required')->label('Approval Required'),
                            Toggle::make('profile_publish_requires_approval')->label('Publish Requires Approval'),
                            $this->integerInput('featured_instructor_limit', 'Featured Limit', 0, 100),
                            Toggle::make('availability_required_for_public_profile')->label('Require Availability'),
                        ]),
                    ]),

                Section::make('Localization')
                    ->description('Country and locale-switching defaults. Timezone, language, and currency live under General Settings.')
                    ->schema([
                        TextInput::make('default_country')
                            ->label('Default Country')
                            ->helperText('ISO 3166-1 alpha-2 code, e.g. IN, US.')
                            ->minLength(2)
                            ->maxLength(2)
                            ->required(),
                        Toggle::make('country_detection_enabled')->label('Detect Country from Visitor'),
                        Toggle::make('allow_user_locale_switching')->label('Allow User Locale Switching'),
                    ]),

                Section::make('Feature Flags')
                    ->description('Global module switches — the single on/off source of truth for each feature. Domain sections above only hold configuration, not the switch itself. Reviews has its own canonical switch under Reviews & Quality Settings, not here.')
                    ->columnSpanFull()
                    ->schema([
                        Grid::make(4)->schema([
                            Toggle::make('demo_lessons_enabled')->label('Demo Lessons'),
                            Toggle::make('wallet_enabled')->label('Wallet'),
                            Toggle::make('referral_enabled')->label('Referral'),
                            Toggle::make('waitlist_enabled')->label('Waitlist'),
                            Toggle::make('homework_enabled')->label('Homework'),
                            Toggle::make('recording_enabled')->label('Recording'),
                        ]),
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

        $bookingOk = $this->saveBooking($data);
        $walletOk = $this->saveWallet($data);
        $instructorOk = $this->saveInstructor($data);
        $localizationOk = $this->saveLocalization($data);
        $featuresOk = $this->saveFeatures($data);

        if (! $bookingOk || ! $walletOk || ! $instructorOk || ! $localizationOk || ! $featuresOk) {
            // A failure notification was already shown by
            // saveSettingsWithAudit() for whichever group failed.
            return;
        }

        Notification::make()
            ->title('Platform foundation settings saved')
            ->success()
            ->send();
    }

    private function integerInput(string $name, string $label, int $min, int $max): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->integer()
            ->minValue($min)
            ->maxValue($max)
            ->required();
    }

    private function numericInput(string $name, string $label, int|float $min): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->numeric()
            ->minValue($min)
            ->required();
    }

    /** @param array<string, mixed> $data */
    private function saveBooking(array $data): bool
    {
        return $this->saveSettingsWithAudit(BookingSettings::class, 'settings', function (BookingSettings $settings) use ($data): void {
            $settings->demo_duration_minutes = (int) $data['demo_duration_minutes'];
            $settings->reservation_expiry_minutes = (int) $data['reservation_expiry_minutes'];
            $settings->minimum_booking_notice_minutes = (int) $data['minimum_booking_notice_minutes'];
            $settings->maximum_advance_booking_days = (int) $data['maximum_advance_booking_days'];
            $settings->cancellation_window_hours = (int) $data['cancellation_window_hours'];
            $settings->reschedule_limit = (int) $data['reschedule_limit'];
            $settings->no_show_grace_minutes = (int) $data['no_show_grace_minutes'];
            $settings->auto_completion_delay_minutes = (int) $data['auto_completion_delay_minutes'];
        });
    }

    /** @param array<string, mixed> $data */
    private function saveWallet(array $data): bool
    {
        return $this->saveSettingsWithAudit(WalletSettings::class, 'settings', function (WalletSettings $settings) use ($data): void {
            $settings->minimum_recharge_amount = (float) $data['minimum_recharge_amount'];
            $settings->maximum_recharge_amount = (float) $data['maximum_recharge_amount'];
            $settings->low_balance_threshold = (float) $data['low_balance_threshold'];
            $settings->recurring_deduction_hours_before_lesson = (int) $data['recurring_deduction_hours_before_lesson'];
        });
    }

    /** @param array<string, mixed> $data */
    private function saveInstructor(array $data): bool
    {
        return $this->saveSettingsWithAudit(InstructorSettings::class, 'settings', function (InstructorSettings $settings) use ($data): void {
            $settings->approval_required = (bool) ($data['approval_required'] ?? false);
            $settings->profile_publish_requires_approval = (bool) ($data['profile_publish_requires_approval'] ?? false);
            $settings->featured_instructor_limit = (int) $data['featured_instructor_limit'];
            $settings->availability_required_for_public_profile = (bool) ($data['availability_required_for_public_profile'] ?? false);
        });
    }

    /** @param array<string, mixed> $data */
    private function saveLocalization(array $data): bool
    {
        return $this->saveSettingsWithAudit(LocalizationSettings::class, 'settings', function (LocalizationSettings $settings) use ($data): void {
            $settings->default_country = strtoupper((string) $data['default_country']);
            $settings->country_detection_enabled = (bool) ($data['country_detection_enabled'] ?? false);
            $settings->allow_user_locale_switching = (bool) ($data['allow_user_locale_switching'] ?? false);
        });
    }

    /** @param array<string, mixed> $data */
    private function saveFeatures(array $data): bool
    {
        return $this->saveSettingsWithAudit(FeatureSettings::class, 'settings', function (FeatureSettings $settings) use ($data): void {
            $settings->demo_lessons_enabled = (bool) ($data['demo_lessons_enabled'] ?? false);
            $settings->wallet_enabled = (bool) ($data['wallet_enabled'] ?? false);
            $settings->referral_enabled = (bool) ($data['referral_enabled'] ?? false);
            $settings->waitlist_enabled = (bool) ($data['waitlist_enabled'] ?? false);
            $settings->homework_enabled = (bool) ($data['homework_enabled'] ?? false);
            $settings->recording_enabled = (bool) ($data['recording_enabled'] ?? false);
        });
    }
}
