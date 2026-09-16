<?php

declare(strict_types=1);

namespace App\Filament\Pages\Settings;

use App\Filament\Navigation\Concerns\HasCentralizedNavigation;
use App\Filament\Navigation\Concerns\HasSettingsSectionBreadcrumb;
use App\Settings\BookingSettings;
use App\Settings\FeatureSettings;
use App\Settings\InstructorSettings;
use App\Settings\LessonSettings;
use App\Settings\LocalizationSettings;
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
    use HasCentralizedNavigation;
    use HasSettingsAccess;
    use HasSettingsSectionBreadcrumb;
    use LogsSettingsUpdates;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static ?string $navigationLabel = 'Platform';

    protected static string|\UnitEnum|null $navigationGroup = 'Platform';

    protected static ?int $navigationSort = 6;

    protected static ?string $slug = 'settings/platform-foundation';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function getLabel(): string
    {
        return 'Platform';
    }

    public function getTitle(): string|Htmlable
    {
        return 'Platform';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Feature switches, booking windows, lesson completion, instructor approval and the default country.';
    }

    public function mount(): void
    {
        $booking = app(BookingSettings::class);
        $lessons = app(LessonSettings::class);
        $instructor = app(InstructorSettings::class);
        $localization = app(LocalizationSettings::class);
        $features = app(FeatureSettings::class);

        $this->form->fill([
            'demo_duration_minutes' => $booking->demo_duration_minutes,
            'reservation_expiry_minutes' => $booking->reservation_expiry_minutes,
            'minimum_booking_notice_minutes' => $booking->minimum_booking_notice_minutes,
            'demo_minimum_booking_notice_minutes' => $booking->demo_minimum_booking_notice_minutes,
            'maximum_advance_booking_days' => $booking->maximum_advance_booking_days,
            'cancellation_window_hours' => $booking->cancellation_window_hours,
            'reschedule_limit' => $booking->reschedule_limit,
            // These two are READ by the lesson lifecycle from LessonSettings.
            // BookingSettings carries same-named copies that nothing reads;
            // for a while this page wrote only those, so changing
            // "Auto-completion Delay" in the admin had no effect at all.
            'no_show_grace_minutes' => $lessons->no_show_grace_minutes,
            'auto_completion_delay_minutes' => $lessons->auto_complete_grace_minutes,
            'approval_required' => $instructor->approval_required,
            'profile_publish_requires_approval' => $instructor->profile_publish_requires_approval,
            'featured_instructor_limit' => $instructor->featured_instructor_limit,
            'availability_required_for_public_profile' => $instructor->availability_required_for_public_profile,
            'default_country' => $localization->default_country,
            'demo_lessons_enabled' => $features->demo_lessons_enabled,
            'wallet_enabled' => $features->wallet_enabled,
            'referral_enabled' => $features->referral_enabled,
            'waitlist_enabled' => $features->waitlist_enabled,
            'homework_enabled' => $features->homework_enabled,
            'recording_enabled' => $features->recording_enabled,
            'country_academic_packages_enabled' => $features->country_academic_packages_enabled,
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
                            ->label('Save changes')
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
            Section::make('Features')
                ->description('The on/off switch for each module. Module settings elsewhere have no effect while its switch is off.')
                ->columnSpanFull()
                ->schema([
                    Grid::make(3)->schema([
                        Toggle::make('demo_lessons_enabled')
                            ->label('Demo lessons')
                            ->helperText('Free demos use the student\'s country-aware academic flow (Class, Grade or Year).'),
                        Toggle::make('wallet_enabled')
                            ->label('Wallet')
                            ->helperText('Recharge limits are under Wallet.'),
                        Toggle::make('referral_enabled')
                            ->label('Referral')
                            ->helperText('Reward rules are under Referral Campaigns.'),
                        Toggle::make('waitlist_enabled')
                            ->label('Waitlist'),
                        Toggle::make('homework_enabled')
                            ->label('Homework'),
                        Toggle::make('recording_enabled')
                            ->label('Recording')
                            ->helperText('Whether lesson recording exists at all. Whether new lessons are recorded, and how, is under Meetings.'),
                        Toggle::make('country_academic_packages_enabled')
                            ->label('Lesson packages fund bookings')
                            ->helperText('Instructor package offers carry the student\'s education system, class, subject and curriculum, and a paid package can then fund a single lesson with that instructor in the booking flow. Needs education systems, levels, published curricula and instructor eligibilities set up for the countries concerned. Offers created while this is off carry no academic context and cannot fund bookings until backfilled (packages:backfill-academic-context).'),
                    ]),
                ]),

            Section::make('Booking')
                ->description('How far ahead and how late students can book, and what they can change afterwards.')
                ->columnSpanFull()
                ->schema([
                    Grid::make(3)->schema([
                        $this->integerInput('minimum_booking_notice_minutes', 'Earliest booking notice (minutes)', 0, 43200)
                            ->helperText('Paid lessons must start at least this long after booking.'),
                        $this->integerInput('demo_minimum_booking_notice_minutes', 'Demo booking notice (minutes)', 0, 43200)
                            ->helperText('Free demos only. Keep it short so a student can try an instructor who is free now.'),
                        $this->integerInput('maximum_advance_booking_days', 'Booking horizon (days)', 1, 365)
                            ->helperText('Furthest ahead a lesson or a recurring series may be scheduled.'),
                        $this->integerInput('demo_duration_minutes', 'Demo duration (minutes)', 1, 480),
                        $this->integerInput('reservation_expiry_minutes', 'Slot hold during checkout (minutes)', 1, 240)
                            ->helperText('A slot stays reserved this long while the student pays; then it is released.'),
                        $this->integerInput('cancellation_window_hours', 'Free cancellation until (hours before start)', 0, 720)
                            ->helperText('Cancelling later than this is subject to the refund policy.'),
                        $this->integerInput('reschedule_limit', 'Reschedules per booking', 0, 20)
                            ->helperText('0 disables rescheduling by students.'),
                    ]),
                ]),

            Section::make('Lesson completion')
                ->description('What happens after the scheduled end. Joining windows are under Meetings.')
                ->columnSpanFull()
                ->schema([
                    Grid::make(2)->schema([
                        $this->integerInput('no_show_grace_minutes', 'No-show can be recorded after (minutes)', 0, 120)
                            ->helperText('Minutes after the scheduled start before a participant may be marked absent.'),
                        $this->integerInput('auto_completion_delay_minutes', 'Mark completed after (minutes)', 0, 10080)
                            ->helperText('Minutes after the scheduled end before an ended lesson is completed. Runs every 5 minutes, so 15 means 15–20 minutes. Until then students see "Lesson ended · Completion pending".'),
                    ]),
                ]),

            Section::make('Instructors')
                ->columnSpanFull()
                ->schema([
                    Grid::make(2)->schema([
                        Toggle::make('approval_required')
                            ->label('Approve new instructors before they can teach'),
                        Toggle::make('profile_publish_requires_approval')
                            ->label('Approve profile changes before they are published'),
                        Toggle::make('availability_required_for_public_profile')
                            ->label('Show only instructors with availability publicly'),
                        $this->integerInput('featured_instructor_limit', 'Featured instructors shown', 0, 100)
                            ->helperText('How many instructors the featured list holds. 0 hides it.'),
                    ]),
                ]),

            Section::make('Localization')
                ->description('Timezone and currency defaults are under General.')
                ->columnSpanFull()
                ->collapsible()
                ->collapsed()
                ->schema([
                    TextInput::make('default_country')
                        ->label('Default country')
                        ->helperText('Two-letter country code, for example IN or US. Used before a visitor\'s country is known.')
                        ->minLength(2)
                        ->maxLength(2)
                        ->required()
                        ->columnSpan(1),
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
        $lessonsOk = $this->saveLessons($data);
        $instructorOk = $this->saveInstructor($data);
        $localizationOk = $this->saveLocalization($data);
        $featuresOk = $this->saveFeatures($data);

        if (! $bookingOk || ! $lessonsOk || ! $instructorOk || ! $localizationOk || ! $featuresOk) {
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

    /** @param array<string, mixed> $data */
    private function saveBooking(array $data): bool
    {
        return $this->saveSettingsWithAudit(BookingSettings::class, 'settings', function (BookingSettings $settings) use ($data): void {
            $settings->demo_duration_minutes = (int) $data['demo_duration_minutes'];
            $settings->reservation_expiry_minutes = (int) $data['reservation_expiry_minutes'];
            $settings->minimum_booking_notice_minutes = (int) $data['minimum_booking_notice_minutes'];
            $settings->demo_minimum_booking_notice_minutes = (int) $data['demo_minimum_booking_notice_minutes'];
            $settings->maximum_advance_booking_days = (int) $data['maximum_advance_booking_days'];
            $settings->cancellation_window_hours = (int) $data['cancellation_window_hours'];
            $settings->reschedule_limit = (int) $data['reschedule_limit'];
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function saveLessons(array $data): bool
    {
        return $this->saveSettingsWithAudit(LessonSettings::class, 'settings', function (LessonSettings $settings) use ($data): void {
            $settings->no_show_grace_minutes = (int) $data['no_show_grace_minutes'];
            $settings->auto_complete_grace_minutes = (int) $data['auto_completion_delay_minutes'];
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
            $settings->country_academic_packages_enabled = (bool) ($data['country_academic_packages_enabled'] ?? false);
        });
    }
}
