<?php

declare(strict_types=1);

namespace App\Filament\Pages\Settings;

use App\Booking\Contracts\EndsActiveMeetings;
use App\Booking\Enums\GoogleMeetSpaceAccess;
use App\Booking\Meetings\GoogleCalendarMeetProvider;
use App\Booking\Meetings\ZoomMeetingProvider;
use App\Booking\Registry\MeetingProviderRegistry;
use App\Booking\Services\GoogleCalendarConfigurationService;
use App\Booking\Services\MeetingJoinHandoffService;
use App\Booking\Services\RecordingAvailabilityResolver;
use App\Booking\Services\ZoomConfigurationService;
use App\Booking\Services\ZoomHostCapacityPreflightService;
use App\Filament\Navigation\Concerns\HasCentralizedNavigation;
use App\Filament\Navigation\Concerns\HasSettingsSectionBreadcrumb;
use App\Filament\Support\AdminDayRange;
use App\Settings\MeetingSettings;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\HtmlString;
use InvalidArgumentException;
use JsonException;
use Throwable;

/**
 * Admin home for everything meeting-related: the platform switches,
 * join links, meeting creation, recording, and the two provider
 * integrations (Google Meet, Zoom). Thin by design — every rule the
 * page enforces on save is one an underlying service also enforces.
 */
class MeetingSettingsPage extends Page
{
    use HasCentralizedNavigation;
    use HasSettingsAccess;
    use HasSettingsSectionBreadcrumb;
    use LogsSettingsUpdates;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedVideoCamera;

    protected static ?string $navigationLabel = 'Meetings';

    protected static string|\UnitEnum|null $navigationGroup = 'Platform';

    protected static ?int $navigationSort = 7;

    protected static ?string $slug = 'settings/meetings';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function getLabel(): string
    {
        return 'Meetings';
    }

    public function getTitle(): string|Htmlable
    {
        return 'Meetings';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'How lessons get a meeting, when participants can join, and how recordings are kept.';
    }

    public function mount(): void
    {
        $meeting = app(MeetingSettings::class);

        // Secrets are never rendered back: those fields start blank and a
        // blank submit keeps the stored value.
        $this->form->fill([
            'meetings_enabled' => $meeting->meetings_enabled,
            'default_provider' => $meeting->default_provider,
            'manual_provider_enabled' => $meeting->manual_provider_enabled,
            'platform_meeting_account' => $meeting->platform_meeting_account,

            'meeting_link_visible_before_minutes' => $meeting->meeting_link_visible_before_minutes,
            'meeting_link_visible_after_minutes' => $meeting->meeting_link_visible_after_minutes,
            'meeting_auto_close_enabled' => $meeting->meeting_auto_close_enabled,
            'student_join_url_visible' => $meeting->student_join_url_visible,
            'instructor_join_url_visible' => $meeting->instructor_join_url_visible,
            'participant_join_base_url' => $meeting->participant_join_base_url,

            'create_after_demo_booking_confirmation' => $meeting->create_after_demo_booking_confirmation,
            'create_after_paid_booking_confirmation' => $meeting->create_after_paid_booking_confirmation,

            'meeting_recording_enabled' => $meeting->recording_enabled,
            'effective_recording_availability' => app(RecordingAvailabilityResolver::class)->isAvailable() ? 'Available' : 'Unavailable',
            'recording_retention_days' => $meeting->recording_retention_days,
            'recording_student_playback_enabled' => $meeting->recording_student_playback_enabled,

            'google_meet_enabled' => $meeting->google_meet_enabled,
            'google_meet_recording_enabled' => $meeting->google_meet_recording_enabled,
            'google_meet_space_access' => GoogleMeetSpaceAccess::fromSetting($meeting->google_meet_space_access)->value,
            'google_meet_cohost_enabled' => $meeting->google_meet_cohost_enabled,
            'google_auth_type' => $meeting->google_auth_type,
            'google_calendar_id' => $meeting->google_calendar_id,
            'recording_drive_root_folder_id' => $meeting->recording_drive_root_folder_id,
            'recording_drive_shared_drive_id' => $meeting->recording_drive_shared_drive_id,
            'google_credentials_json' => null,
            'google_credentials_configured' => $meeting->google_credentials_configured,
            'google_config_status' => $meeting->google_config_status,
            'google_last_checked_at' => $meeting->google_last_checked_at,
            'google_credentials_updated_at' => $meeting->google_credentials_updated_at,

            'zoom_enabled' => $meeting->zoom_enabled,
            'zoom_account_id' => $meeting->zoom_account_id,
            'zoom_client_id' => $meeting->zoom_client_id,
            'zoom_client_secret' => null,
            'zoom_host_user_id' => $meeting->zoom_host_user_id,
            'zoom_host_email' => $meeting->zoom_host_email,
            'zoom_default_timezone' => $meeting->zoom_default_timezone,
            'zoom_host_capacity_enabled' => $meeting->zoom_host_capacity_enabled,
            'zoom_host_capacity_buffer_minutes' => $meeting->zoom_host_capacity_buffer_minutes,
            'zoom_capacity_fallback_provider' => $meeting->zoom_capacity_fallback_provider,
            'zoom_recording_enabled' => $meeting->zoom_recording_enabled,
            'zoom_recording_webhooks_enabled' => $meeting->zoom_recording_webhooks_enabled,
            'zoom_webhook_secret' => null,
            'zoom_recording_trash_source_after_persistence' => $meeting->zoom_recording_trash_source_after_persistence,
            'zoom_config_status' => $meeting->zoom_config_status,
            'zoom_last_checked_at' => $meeting->zoom_last_checked_at,
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
                        Action::make('test_google_configuration')
                            ->label('Check Google Setup')
                            ->color('gray')
                            ->action('testGoogleConfiguration'),
                        Action::make('validate_zoom_configuration')
                            ->label('Check Zoom Setup')
                            ->color('gray')
                            ->action('validateZoomConfiguration'),
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
            $this->generalSection(),
            $this->joiningSection(),
            $this->recordingSection(),
            $this->recordingStorageSection(),
            $this->googleMeetSection(),
            $this->zoomSection(),
        ]);
    }

    // ── Sections ──────────────────────────────────────────────────────

    private function generalSection(): Section
    {
        return Section::make('General')
            ->description('Turn lesson meetings on, choose the provider, and decide when meetings are created.')
            ->columnSpanFull()
            ->schema([
                Grid::make(2)->schema([
                    Toggle::make('meetings_enabled')
                        ->label('Enable meetings')
                        ->helperText('Off stops creating meeting links for every lesson.'),
                    Select::make('default_provider')
                        ->label('Default provider')
                        ->options(['manual' => 'Manual', 'google_meet' => 'Google Meet', 'zoom' => 'Zoom'])
                        ->required()
                        ->native(false)
                        ->helperText('Used for automatically created meetings. Set it up below first.'),
                    Toggle::make('create_after_demo_booking_confirmation')
                        ->label('Auto-create for free demos')
                        ->helperText('Create the meeting when a free demo is confirmed.'),
                    Toggle::make('create_after_paid_booking_confirmation')
                        ->label('Auto-create for paid lessons')
                        ->helperText('Create the meeting when payment is confirmed. Off means an admin creates it.'),
                    Toggle::make('manual_provider_enabled')
                        ->label('Allow manual links')
                        ->helperText('An admin can paste a meeting link on a booking.'),
                    TextInput::make('platform_meeting_account')
                        ->label('Platform meeting account')
                        ->maxLength(255)
                        ->helperText('For reference only.'),
                ]),
            ]);
    }

    private function joiningSection(): Section
    {
        return Section::make('Joining')
            ->description('When participants can join, who sees the link, and where it points.')
            ->columnSpanFull()
            ->schema([
                Grid::make(2)->schema([
                    $this->integerInput('meeting_link_visible_before_minutes', 'Allow joining before start (minutes)', 0, 10080)
                        ->live(debounce: 400)
                        ->helperText('Participants can join this many minutes before the scheduled start.'),
                    $this->integerInput('meeting_link_visible_after_minutes', 'Allow joining after end (minutes)', 0, 10080)
                        ->live(debounce: 400)
                        ->helperText('Joining closes this many minutes after the scheduled end.'),
                    Placeholder::make('join_window_example')
                        ->label('Example')
                        ->columnSpanFull()
                        ->content(fn (): string => $this->joinWindowExample()),
                    Toggle::make('student_join_url_visible')
                        ->label('Students can join')
                        ->helperText('Off hides the join link from students.'),
                    Toggle::make('instructor_join_url_visible')
                        ->label('Instructors can join')
                        ->helperText('Off hides the join link from instructors.'),
                    TextInput::make('participant_join_base_url')
                        ->label('Join domain')
                        ->placeholder('https://meet.sirieducation.com')
                        ->maxLength(255)
                        ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                            if (filled($value) && MeetingJoinHandoffService::normalizeOrigin((string) $value) === null) {
                                $fail('Enter an HTTPS address with only a host, for example https://meet.sirieducation.com.');
                            }
                        })
                        ->helperText('HTTPS address join links use. Leave empty to use the main site.'),
                    Toggle::make('meeting_auto_close_enabled')
                        ->label('Close the meeting when joining closes')
                        ->helperText($this->autoCloseHelper()),
                ]),
                Section::make('Related controls that are not this window')
                    ->collapsible()
                    ->collapsed()
                    ->schema([
                        Placeholder::make('join_window_related')
                            ->hiddenLabel()
                            ->content(new HtmlString(implode('<br>', [
                                '<strong>Joining window</strong> (above) — when the join link works for participants.',
                                '<strong>Close the meeting</strong> — ends the provider meeting when the window closes, for providers that support it. Independent of the link.',
                                '<strong>Recording capture delay</strong> — how long after the scheduled end SIRI first looks for a recording. Set in Recording, not here.',
                                '<strong>Lesson completion</strong> — the lesson outcome is finalised by its own lifecycle; joining and recording never change it.',
                            ]))),
                    ]),
            ]);
    }

    private function recordingSection(): Section
    {
        return Section::make('Recording & Playback')
            ->description('Whether new lessons are recorded, how long recordings are kept, and who can watch.')
            ->columnSpanFull()
            ->schema([
                Grid::make(2)->schema([
                    Toggle::make('meeting_recording_enabled')
                        ->label('Record new lessons')
                        ->helperText('Also requires the Recording feature in Platform and a provider that can record.'),
                    Placeholder::make('effective_recording_availability_display')
                        ->label('Recording right now')
                        ->content(fn (): string => ($this->data['effective_recording_availability'] ?? null) === 'Available'
                            ? 'Available — new lessons are recorded'
                            : 'Unavailable — new lessons are not recorded')
                        ->helperText('The result of both switches, as saved.'),
                    $this->integerInput('recording_retention_days', 'Keep recordings for (days)', 0, 3650)
                        ->helperText('Recordings are removed after this many days.'),
                    Toggle::make('recording_student_playback_enabled')
                        ->label('Student playback')
                        ->helperText('Off hides every recording from students. Single recordings can still be withheld on the Recordings screen.'),
                ]),
            ]);
    }

    private function recordingStorageSection(): Section
    {
        return Section::make('Recording Storage')
            ->description('Where SIRI keeps its own copy of every recording — Google Meet and Zoom alike. The provider\'s copy is never the stored one.')
            ->columnSpanFull()
            ->schema([
                Placeholder::make('recording_storage_status')
                    ->hiddenLabel()
                    ->columnSpanFull()
                    ->content(fn (): HtmlString => $this->recordingStorageStatus()),
                Grid::make(2)->schema([
                    Placeholder::make('recording_storage_driver_display')
                        ->label('Storage backend')
                        ->content(fn (): string => $this->storageDriverLabel())
                        ->helperText('Set by deployment (RECORDING_STORAGE_DRIVER), not on this page.'),
                    Placeholder::make('recording_storage_credentials_display')
                        ->label('Google credentials for Drive')
                        ->content(fn (): string => $this->yesNo($this->data['google_credentials_configured'] ?? null))
                        ->helperText('The same service account key as Google Meet, saved in that section.'),
                    TextInput::make('recording_drive_root_folder_id')
                        ->label('Drive folder ID')
                        ->maxLength(255)
                        ->helperText('Folder that holds recording copies, from the folder URL. Required when storage is Google Drive.'),
                    TextInput::make('recording_drive_shared_drive_id')
                        ->label('Shared Drive ID')
                        ->maxLength(255)
                        ->helperText('Only if the folder is in a Shared Drive.'),
                ]),
            ]);
    }

    private function googleMeetSection(): Section
    {
        return Section::make('Google Meet')
            ->description('Service account used to create Meet links and fetch Meet recordings.')
            ->columnSpanFull()
            ->schema([
                Grid::make(2)->schema([
                    Toggle::make('google_meet_enabled')->label('Enable Google Meet'),
                    Toggle::make('google_meet_recording_enabled')
                        ->label('Fetch Meet recordings')
                        ->helperText('Needs the Meet and Drive read scopes on the service account.'),
                    Select::make('google_meet_space_access')
                        ->label('Who can join a Meet')
                        ->options(GoogleMeetSpaceAccess::options())
                        ->required()
                        ->native(false)
                        ->helperText('Google records only while the host is present. Applies to new lessons.'),
                    Toggle::make('google_meet_cohost_enabled')
                        ->label('Make the instructor a Meet co-host')
                        ->helperText('Adds the instructor\'s Google account (profile setting, or their login email) as co-host of each new lesson space, so they join without the lobby and can admit students. Needs the "meetings.space.created" scope, which is already granted. Applies to new lessons.'),
                    Select::make('google_auth_type')
                        ->label('Authentication')
                        ->options(['service_account' => 'Service Account', 'oauth_user' => 'OAuth User (coming later)'])
                        // The provider only treats a service account as
                        // configured; letting an admin pick OAuth would stop
                        // Meet creation without saying why.
                        ->disableOptionWhen(fn (string $value): bool => $value === 'oauth_user')
                        ->required()
                        ->native(false),
                    TextInput::make('google_calendar_id')->label('Calendar ID')->maxLength(255),
                    Placeholder::make('google_credentials_configured_display')
                        ->label('Credentials stored')
                        ->content(fn (): string => $this->yesNo($this->data['google_credentials_configured'] ?? null)),
                    Placeholder::make('google_config_status_display')
                        ->label('Meeting setup status')
                        ->content(fn (): string => $this->configStatusLabel($this->data['google_config_status'] ?? null))
                        ->helperText('Checks meeting credentials only, not recording storage.'),
                    Placeholder::make('google_last_checked_at_display')
                        ->label('Last checked')
                        ->content(fn (): string => $this->timestampLabel($this->data['google_last_checked_at'] ?? null)),
                ]),
                Textarea::make('google_credentials_json')
                    ->label('Service account JSON')
                    ->rows(4)
                    ->columnSpanFull()
                    ->helperText('Paste a new key to replace the stored one. Leave blank to keep it. Last replaced: '.$this->timestampLabel($this->data['google_credentials_updated_at'] ?? null).'.'),
                Section::make('Setup guidance')
                    ->collapsible()
                    ->collapsed()
                    ->schema([
                        Placeholder::make('google_setup_guidance')
                            ->hiddenLabel()
                            ->content(new HtmlString(implode('<br>', [
                                '1. Create a Google Workspace service account with domain-wide delegation and paste its JSON key above.',
                                '2. Grant it the Calendar scope for Meet links and the Meet/Drive read scopes for recordings.',
                                '3. Share the recordings Drive folder (Recording Storage) with the service account.',
                                '4. Use <em>Check Google Setup</em>; "Ready" confirms meeting credentials, not storage.',
                                'See docs/deployment/recording-cutover.md and docs/deployment/google-account-activation.md.',
                            ]))),
                    ]),
            ]);
    }

    private function zoomSection(): Section
    {
        return Section::make('Zoom')
            ->description('Server-to-Server OAuth app for the platform\'s Zoom host account.')
            ->columnSpanFull()
            ->schema([
                Grid::make(2)->schema([
                    Toggle::make('zoom_enabled')->label('Enable Zoom'),
                    TextInput::make('zoom_default_timezone')->label('Default timezone')->maxLength(64)->placeholder('Asia/Kolkata'),
                    TextInput::make('zoom_account_id')->label('Account ID')->maxLength(255),
                    TextInput::make('zoom_client_id')->label('Client ID')->maxLength(255),
                    TextInput::make('zoom_client_secret')
                        ->label('Client secret')
                        ->password()
                        ->maxLength(255)
                        ->helperText('Leave blank to keep the stored secret.'),
                    TextInput::make('zoom_webhook_secret')
                        ->label('Webhook secret token')
                        ->password()
                        ->maxLength(255)
                        ->helperText('From the Zoom app\'s Event Subscriptions page. Leave blank to keep it.'),
                    TextInput::make('zoom_host_user_id')
                        ->label('Host user ID')
                        ->maxLength(255)
                        ->helperText('Zoom user that meetings are scheduled under. Takes precedence over host email.'),
                    TextInput::make('zoom_host_email')->label('Host email')->email()->maxLength(255),
                    Toggle::make('zoom_host_capacity_enabled')
                        ->label('Reserve host capacity')
                        ->helperText('Each Zoom booking reserves the host for its lesson window and is refused when the host is taken. Register the host and run the preflight first.'),
                    $this->integerInput('zoom_host_capacity_buffer_minutes', 'Host buffer (minutes)', 0, 120)
                        ->helperText('Extra time kept free on the host before and after each lesson.'),
                    Select::make('zoom_capacity_fallback_provider')
                        ->label('When no Zoom host is free')
                        ->options([GoogleCalendarMeetProvider::KEY => 'Book the lesson on Google Meet'])
                        ->placeholder('Refuse the booking')
                        ->nullable()
                        ->native(false)
                        ->helperText('With Google Meet chosen, a booking that finds every Zoom host taken is accepted on Google Meet instead. Each such lesson is audited and administrators are notified, because a Google Meet lesson only starts and records once the platform Meet host has joined it.'),
                    Toggle::make('zoom_recording_enabled')
                        ->label('Fetch Zoom recordings')
                        ->helperText('Needs a licensed Zoom account with cloud recording. Copies are stored in Recording Storage above.'),
                    Toggle::make('zoom_recording_webhooks_enabled')
                        ->label('Accept recording webhooks')
                        ->helperText('Zoom notifies SIRI when a recording is ready. Off relies on the scheduled check.'),
                    Toggle::make('zoom_recording_trash_source_after_persistence')
                        ->label('Trash Zoom copy after verification')
                        ->columnSpanFull()
                        ->helperText('Moves Zoom\'s copy to its recoverable trash once SIRI has stored and verified its own. Off keeps both copies.'),
                    Placeholder::make('zoom_config_status_display')
                        ->label('Meeting setup status')
                        ->content(fn (): string => $this->configStatusLabel($this->data['zoom_config_status'] ?? null))
                        ->helperText('Checks meeting credentials only, not recording storage.'),
                    Placeholder::make('zoom_last_checked_at_display')
                        ->label('Last checked')
                        ->content(fn (): string => $this->timestampLabel($this->data['zoom_last_checked_at'] ?? null)),
                ]),
                Section::make('Setup guidance')
                    ->collapsible()
                    ->collapsed()
                    ->schema([
                        Placeholder::make('zoom_setup_guidance')
                            ->hiddenLabel()
                            ->content(new HtmlString(implode('<br>', [
                                '1. Create a Server-to-Server OAuth app on the Zoom marketplace and paste its account, client ID and secret.',
                                '2. Grant the meeting and cloud-recording scopes listed in docs/deployment/zoom-activation.md.',
                                '3. Register the platform host (meetings:zoom-hosts:register), run meetings:zoom-hosts:preflight, then turn on <em>Reserve host capacity</em>.',
                                '4. Add the recording webhook (docs) and paste its secret token; use <em>Check Zoom Setup</em>.',
                                'Zoom recordings are copied into Recording Storage; without a working Drive folder they cannot be kept.',
                            ]))),
                    ]),
            ]);
    }

    // ── Derived readouts ──────────────────────────────────────────────

    /** "For a 7–8 PM lesson, joining is available from 6:45–8:15 PM (Asia/Kolkata)." from the live form values. */
    private function joinWindowExample(): string
    {
        $before = max(0, (int) ($this->data['meeting_link_visible_before_minutes'] ?? 0));
        $after = max(0, (int) ($this->data['meeting_link_visible_after_minutes'] ?? 0));
        $timezone = AdminDayRange::viewerLabel();

        $start = Carbon::today($timezone)->setTime(19, 0);
        $end = $start->copy()->addHour();

        return sprintf(
            'For a 7–8 PM lesson, joining is available from %s to %s (%s).',
            $start->copy()->subMinutes($before)->format('g:i A'),
            $end->copy()->addMinutes($after)->format('g:i A'),
            $timezone,
        );
    }

    /** Which providers actually support ending the meeting — derived, not asserted. */
    private function autoCloseHelper(): string
    {
        $registry = app(MeetingProviderRegistry::class);
        $supporting = array_values(array_filter(
            ['google_meet' => 'Google Meet', 'zoom' => 'Zoom'],
            fn (string $label, string $key): bool => $registry->has($key) && $registry->get($key) instanceof EndsActiveMeetings,
            ARRAY_FILTER_USE_BOTH,
        ));

        return $supporting === []
            ? 'No configured provider can end a meeting; this setting currently does nothing.'
            : sprintf('Ends the provider meeting so a class or recording cannot run on. Supported by %s.', implode(' and ', $supporting));
    }

    private function storageDriverLabel(): string
    {
        return match ((string) config('recordings.storage_driver')) {
            'google_drive' => 'Google Drive',
            'filesystem' => 'Local filesystem (development only)',
            default => (string) config('recordings.storage_driver'),
        };
    }

    /** A warning when Drive is the backend but cannot work. Never inferred from a provider's "Ready". */
    private function recordingStorageStatus(): HtmlString
    {
        if ((string) config('recordings.storage_driver') !== 'google_drive') {
            return new HtmlString('');
        }

        $missing = [];

        if (blank($this->data['recording_drive_root_folder_id'] ?? null)) {
            $missing[] = 'the Drive folder ID';
        }

        if (! filter_var($this->data['google_credentials_configured'] ?? null, FILTER_VALIDATE_BOOLEAN)) {
            $missing[] = 'a stored Google service account key';
        }

        if ($missing === []) {
            return new HtmlString('<span class="text-success-600 dark:text-success-400">Google Drive storage is configured for Google Meet and Zoom recordings.</span>');
        }

        return new HtmlString(sprintf(
            '<span class="text-danger-600 dark:text-danger-400"><strong>Recordings cannot be stored:</strong> Google Drive is the storage backend but %s is missing. Every capture fails until this is fixed. A provider\'s "Ready" status does not cover storage.</span>',
            implode(' and ', $missing),
        ));
    }

    // ── Read-only labels ──────────────────────────────────────────────

    private function yesNo(mixed $value): string
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 'Yes' : 'No';
    }

    private function configStatusLabel(mixed $status): string
    {
        return match ((string) $status) {
            'ready' => 'Ready',
            'incomplete' => 'Incomplete — some required details are missing',
            'invalid' => 'Invalid — the stored credentials were rejected',
            'not_configured' => 'Not configured',
            '' => 'Not checked yet',
            default => (string) $status,
        };
    }

    private function timestampLabel(mixed $value): string
    {
        if (blank($value)) {
            return 'Never';
        }

        try {
            $moment = Carbon::parse((string) $value);
        } catch (Throwable) {
            return (string) $value; // an unparseable value is still worth showing
        }

        return $moment->timezone(config('app.timezone'))->format('j M Y, H:i').' ('.$moment->diffForHumans().')';
    }

    // ── Save ──────────────────────────────────────────────────────────

    public function save(): void
    {
        try {
            $data = $this->form->getState();
        } catch (Halt) {
            return;
        }

        if ($this->refuseUnsafeZoomCapacity($data)) {
            return;
        }

        // A new Google key is validated before anything is written, so a
        // bad key never reaches the database or blocks the rest of the save.
        $newGoogleCredentials = null;

        if (filled($data['google_credentials_json'] ?? null)) {
            try {
                $newGoogleCredentials = $this->validateGoogleCredentialsJson((string) $data['google_credentials_json']);
            } catch (InvalidArgumentException $e) {
                Notification::make()->title('Google credentials not saved')->body($e->getMessage())->danger()->send();

                return;
            }
        }

        $saved = $this->saveSettingsWithAudit(MeetingSettings::class, 'settings', function (MeetingSettings $settings) use ($data, $newGoogleCredentials): void {
            $this->applyMeetingSettings($settings, $data);
            $this->applyGoogleSettings($settings, $data, $newGoogleCredentials !== null);
            $this->applyZoomSettings($settings, $data);
        });

        if (! $saved) {
            return;
        }

        $this->mount();

        Notification::make()
            ->title('Meeting settings saved')
            ->body($newGoogleCredentials !== null
                ? sprintf('Google credentials replaced — client_email: %s, client_id: %s', $newGoogleCredentials['client_email'], $newGoogleCredentials['client_id'])
                : null)
            ->success()
            ->send();
    }

    /**
     * Two gates the stored state must never violate, refused here with
     * the safe order of operations rather than left to fail at booking:
     * Zoom cannot be the default while host reservation is off (every
     * Zoom booking would be refused), and reservation cannot be switched
     * on while the preflight has findings. Returns true when the save
     * was refused.
     *
     * @param  array<string, mixed>  $data
     */
    private function refuseUnsafeZoomCapacity(array $data): bool
    {
        $capacityOn = $this->bool($data, 'zoom_host_capacity_enabled');

        if (($data['default_provider'] ?? null) === ZoomMeetingProvider::KEY && ! $capacityOn) {
            Notification::make()
                ->title('Meeting settings not saved')
                ->body('Zoom cannot be the default provider while "Reserve Host Capacity" is off — every Zoom booking would be refused. Register the host, run meetings:zoom-hosts:preflight, turn reservation on, then choose Zoom. To roll back, choose Google Meet first, then turn reservation off.')
                ->danger()
                ->send();

            return true;
        }

        // Google Meet may only take over full Zoom hours when it can
        // actually create meetings — judged on the SUBMITTED Google fields,
        // since the same save may be the one that configures it.
        if ($this->nullableString($data, 'zoom_capacity_fallback_provider') === GoogleCalendarMeetProvider::KEY) {
            $meetReady = $this->bool($data, 'google_meet_enabled')
                && ($data['google_auth_type'] ?? null) === 'service_account'
                && filled($data['google_calendar_id'] ?? null)
                && filled($data['platform_meeting_account'] ?? null)
                && (filled($data['google_credentials_json'] ?? null) || filled(app(MeetingSettings::class)->google_credentials_json));

            if (! $meetReady) {
                Notification::make()
                    ->title('Meeting settings not saved')
                    ->body('Google Meet cannot take over full Zoom hours until it is fully configured: enable Google Meet, use a service account with its JSON key, and set the calendar ID and platform meeting account. Choose "Refuse the booking" or complete the Google Meet setup first.')
                    ->danger()
                    ->send();

                return true;
            }
        }

        if ($capacityOn && ! app(MeetingSettings::class)->zoom_host_capacity_enabled) {
            $report = app(ZoomHostCapacityPreflightService::class)->report();

            if ($report->hasFindings()) {
                Notification::make()
                    ->title('Zoom host capacity reservation not enabled')
                    ->body($report->summary().' Run meetings:zoom-hosts:preflight for the full list; nothing on this page was saved.')
                    ->danger()
                    ->send();

                return true;
            }
        }

        return false;
    }

    /** @param  array<string, mixed>  $data */
    private function applyMeetingSettings(MeetingSettings $settings, array $data): void
    {
        $settings->meetings_enabled = $this->bool($data, 'meetings_enabled');
        $settings->default_provider = $data['default_provider'];
        $settings->manual_provider_enabled = $this->bool($data, 'manual_provider_enabled');
        $settings->platform_meeting_account = $this->nullableString($data, 'platform_meeting_account');

        $settings->meeting_link_visible_before_minutes = (int) $data['meeting_link_visible_before_minutes'];
        $settings->meeting_link_visible_after_minutes = (int) $data['meeting_link_visible_after_minutes'];
        $settings->meeting_auto_close_enabled = $this->bool($data, 'meeting_auto_close_enabled');
        $settings->student_join_url_visible = $this->bool($data, 'student_join_url_visible');
        $settings->instructor_join_url_visible = $this->bool($data, 'instructor_join_url_visible');
        $settings->participant_join_base_url = MeetingJoinHandoffService::normalizeOrigin($data['participant_join_base_url'] ?? null);

        $settings->create_after_demo_booking_confirmation = $this->bool($data, 'create_after_demo_booking_confirmation');
        $settings->create_after_paid_booking_confirmation = $this->bool($data, 'create_after_paid_booking_confirmation');

        $settings->recording_enabled = $this->bool($data, 'meeting_recording_enabled');
        $settings->recording_retention_days = (int) $data['recording_retention_days'];
        $settings->recording_student_playback_enabled = $this->bool($data, 'recording_student_playback_enabled');
    }

    /** @param  array<string, mixed>  $data */
    private function applyGoogleSettings(MeetingSettings $settings, array $data, bool $replaceCredentials): void
    {
        $settings->google_meet_enabled = $this->bool($data, 'google_meet_enabled');
        $settings->google_meet_recording_enabled = $this->bool($data, 'google_meet_recording_enabled');
        $settings->google_meet_space_access = GoogleMeetSpaceAccess::fromSetting($data['google_meet_space_access'] ?? null)->value;
        $settings->google_meet_cohost_enabled = $this->bool($data, 'google_meet_cohost_enabled');
        $settings->google_auth_type = $data['google_auth_type'];
        $settings->google_calendar_id = $this->nullableString($data, 'google_calendar_id');
        $settings->recording_drive_root_folder_id = $this->nullableString($data, 'recording_drive_root_folder_id');
        $settings->recording_drive_shared_drive_id = $this->nullableString($data, 'recording_drive_shared_drive_id');

        // A blank submit keeps the existing encrypted key.
        if ($replaceCredentials) {
            $settings->google_credentials_json = Crypt::encryptString((string) $data['google_credentials_json']);
            $settings->google_credentials_updated_at = Carbon::now()->toIso8601String();
        }
    }

    /** @param  array<string, mixed>  $data */
    private function applyZoomSettings(MeetingSettings $settings, array $data): void
    {
        $enabled = $this->bool($data, 'zoom_enabled');

        $settings->zoom_enabled = $enabled;
        $settings->zoom_account_id = $this->nullableString($data, 'zoom_account_id');
        $settings->zoom_client_id = $this->nullableString($data, 'zoom_client_id');
        $settings->zoom_host_user_id = $this->nullableString($data, 'zoom_host_user_id');
        $settings->zoom_host_email = $this->nullableString($data, 'zoom_host_email');
        $settings->zoom_default_timezone = $this->nullableString($data, 'zoom_default_timezone');
        $settings->zoom_host_capacity_enabled = $this->bool($data, 'zoom_host_capacity_enabled');
        $settings->zoom_host_capacity_buffer_minutes = (int) ($data['zoom_host_capacity_buffer_minutes'] ?? 5);
        $settings->zoom_capacity_fallback_provider = $this->nullableString($data, 'zoom_capacity_fallback_provider');

        // A provider that cannot create meetings cannot record them: the
        // recording switches are only ever stored on alongside Zoom itself.
        $settings->zoom_recording_enabled = $enabled && $this->bool($data, 'zoom_recording_enabled');
        $settings->zoom_recording_webhooks_enabled = $settings->zoom_recording_enabled && $this->bool($data, 'zoom_recording_webhooks_enabled');
        $settings->zoom_recording_trash_source_after_persistence = $settings->zoom_recording_enabled && $this->bool($data, 'zoom_recording_trash_source_after_persistence');

        // Blank secret fields keep the stored values.
        foreach (['zoom_client_secret', 'zoom_webhook_secret'] as $secret) {
            if (filled($data[$secret] ?? null)) {
                $settings->{$secret} = Crypt::encryptString((string) $data[$secret]);
            }
        }
    }

    /**
     * Fails closed on anything that is not a well-formed service-account
     * JSON — never partially trusts it, never logs it.
     *
     * @return array{client_id: string, client_email: string}
     *
     * @throws InvalidArgumentException with a message safe to show the admin
     */
    private function validateGoogleCredentialsJson(string $json): array
    {
        try {
            $decoded = json_decode($json, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('The service account JSON is not valid JSON.');
        }

        if (! is_array($decoded) || ($decoded['type'] ?? null) !== 'service_account') {
            throw new InvalidArgumentException('The service account JSON must have "type": "service_account".');
        }

        foreach (['client_id', 'client_email', 'private_key'] as $field) {
            if (blank($decoded[$field] ?? null)) {
                throw new InvalidArgumentException(sprintf('The service account JSON is missing "%s".', $field));
            }
        }

        return [
            'client_id' => (string) $decoded['client_id'],
            'client_email' => (string) $decoded['client_email'],
        ];
    }

    // ── Provider checks ───────────────────────────────────────────────

    public function testGoogleConfiguration(): void
    {
        $service = app(GoogleCalendarConfigurationService::class);
        $status = $service->check();
        $diagnostics = $service->lastDiagnostics();
        $reason = $service->lastDiagnostic();

        $this->mount();

        $lines = $diagnostics === null ? [] : [
            sprintf('Client ID: %s', $diagnostics->clientId ?? 'unknown'),
            sprintf('Client email: %s', $diagnostics->clientEmail ?? 'unknown'),
            sprintf('Delegated subject: %s', $diagnostics->delegatedSubject ?? 'unknown'),
            sprintf('Requested scopes: %s', implode(', ', $diagnostics->requestedScopes)),
            sprintf('Calendar ID: %s', $diagnostics->calendarId ?? 'unknown'),
            sprintf('Token acquired: %s', $diagnostics->tokenAcquired ? 'yes' : 'no'),
            sprintf('Allowed conference types: [%s]', implode(', ', $diagnostics->allowedConferenceTypes) ?: 'none'),
        ];

        $this->notifyCheck('Google configuration', $status, $lines, $reason);
    }

    public function validateZoomConfiguration(): void
    {
        $service = app(ZoomConfigurationService::class);
        $status = $service->check();
        $diagnostics = $service->lastDiagnostics();
        $reason = $service->lastDiagnostic();

        $this->mount();

        $lines = $diagnostics === null ? [] : [
            sprintf('Account ID: %s', $diagnostics->accountId ?? 'unknown'),
            sprintf('Client ID: %s', $diagnostics->clientId ?? 'unknown'),
            sprintf('Host user: %s', $diagnostics->hostUser ?? 'unknown'),
            sprintf('Token acquired: %s', $diagnostics->tokenAcquired ? 'yes' : 'no'),
            sprintf('Meeting creation verified: %s', $diagnostics->meetingCreationVerified ? 'yes' : 'no'),
        ];

        $this->notifyCheck('Zoom configuration', $status, $lines, $reason);
    }

    /** @param  list<string>  $lines */
    private function notifyCheck(string $subject, string $status, array $lines, ?string $reason): void
    {
        if ($reason !== null) {
            $lines[] = sprintf('Reason: %s', $reason);
        }

        Notification::make()
            ->title($subject.': '.$status)
            ->body($lines === [] ? null : implode("\n", $lines))
            ->{$status === 'ready' ? 'success' : 'warning'}()
            ->send();
    }

    // ── Helpers ───────────────────────────────────────────────────────

    private function integerInput(string $name, string $label, int $min, int $max): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->integer()
            ->minValue($min)
            ->maxValue($max)
            ->required();
    }

    /** @param  array<string, mixed>  $data */
    private function bool(array $data, string $key): bool
    {
        return (bool) ($data[$key] ?? false);
    }

    /** @param  array<string, mixed>  $data */
    private function nullableString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return filled($value) ? trim((string) $value) : null;
    }
}
