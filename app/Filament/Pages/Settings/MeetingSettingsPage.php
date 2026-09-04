<?php

declare(strict_types=1);

namespace App\Filament\Pages\Settings;

use App\Booking\Services\GoogleCalendarConfigurationService;
use App\Booking\Services\RecordingAvailabilityResolver;
use App\Booking\Services\ZoomConfigurationService;
use App\Filament\Navigation\Concerns\HasCentralizedNavigation;
use App\Filament\Navigation\Concerns\HasSettingsSectionBreadcrumb;
use App\Settings\MeetingSettings;
use BackedEnum;
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
use InvalidArgumentException;
use JsonException;

/**
 * Dedicated admin home for everything meeting-related: platform
 * switches, provider selection, and per-provider (Google Meet, Zoom)
 * credentials/readiness. Extracted from PlatformFoundationSettingsPage
 * once real provider integrations made the meeting surface too large
 * to live inside a general foundation page.
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
        return 'Meeting Settings';
    }

    public function getTitle(): string|Htmlable
    {
        return 'Meeting Settings';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Meeting providers (Manual, Google Meet, Zoom), credentials, auto-creation rules, and join-link visibility.';
    }

    public function mount(): void
    {
        $meeting = app(MeetingSettings::class);

        $this->form->fill([
            'meetings_enabled' => $meeting->meetings_enabled,
            'default_provider' => $meeting->default_provider,
            'platform_meeting_account' => $meeting->platform_meeting_account,
            'meeting_link_visible_before_minutes' => $meeting->meeting_link_visible_before_minutes,
            'meeting_link_visible_after_minutes' => $meeting->meeting_link_visible_after_minutes,
            'meeting_recording_enabled' => $meeting->recording_enabled,
            'effective_recording_availability' => app(RecordingAvailabilityResolver::class)->isAvailable() ? 'Available' : 'Unavailable',
            'recording_retention_days' => $meeting->recording_retention_days,
            'recording_student_playback_enabled' => $meeting->recording_student_playback_enabled,
            'create_after_demo_booking_confirmation' => $meeting->create_after_demo_booking_confirmation,
            'create_after_paid_booking_confirmation' => $meeting->create_after_paid_booking_confirmation,
            'manual_provider_enabled' => $meeting->manual_provider_enabled,
            'student_join_url_visible' => $meeting->student_join_url_visible,
            'instructor_join_url_visible' => $meeting->instructor_join_url_visible,
            'google_meet_enabled' => $meeting->google_meet_enabled,
            'google_meet_recording_enabled' => $meeting->google_meet_recording_enabled,
            'google_calendar_id' => $meeting->google_calendar_id,
            'google_auth_type' => $meeting->google_auth_type,
            // Never render stored credentials back to the admin — these
            // fields start blank; a blank submit means "keep existing".
            'google_credentials_json' => null,
            'google_credentials_configured' => $meeting->google_credentials_configured ? 'Yes' : 'No',
            'google_config_status' => $meeting->google_config_status,
            'google_last_checked_at' => $meeting->google_last_checked_at,
            'google_credentials_updated_at' => $meeting->google_credentials_updated_at,
            'zoom_enabled' => $meeting->zoom_enabled,
            'zoom_recording_enabled' => $meeting->zoom_recording_enabled,
            'zoom_recording_webhooks_enabled' => $meeting->zoom_recording_webhooks_enabled,
            // Secrets are never re-displayed; a blank field keeps the stored value.
            'zoom_webhook_secret' => null,
            'zoom_account_id' => $meeting->zoom_account_id,
            'zoom_client_id' => $meeting->zoom_client_id,
            'zoom_client_secret' => null,
            'zoom_host_user_id' => $meeting->zoom_host_user_id,
            'zoom_host_email' => $meeting->zoom_host_email,
            'zoom_default_timezone' => $meeting->zoom_default_timezone,
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
                            ->label('Save Meeting Settings')
                            ->submit('save')
                            ->keyBindings(['mod+s']),
                        Action::make('test_google_configuration')
                            ->label('Test Google Configuration')
                            ->color('gray')
                            ->action('testGoogleConfiguration'),
                        Action::make('validate_zoom_configuration')
                            ->label('Validate Zoom Configuration')
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
            Section::make('Meeting')
                ->description('Which provider creates lesson links, when links are shown, and whether lessons are recorded.')
                ->columnSpanFull()
                ->schema([
                    Grid::make(2)->schema([
                        Toggle::make('meetings_enabled')
                            ->label('Meetings Enabled')
                            ->helperText('Off: no lesson gets a meeting link from any provider.'),
                        Select::make('default_provider')
                            ->options([
                                'manual' => 'Manual',
                                'google_meet' => 'Google Meet',
                                'zoom' => 'Zoom',
                            ])
                            ->helperText('Used for every automatically created meeting. The provider must be configured below, or meeting creation fails and the booking shows no link.')
                            ->required()
                            ->native(false),
                        Toggle::make('manual_provider_enabled')
                            ->label('Manual Provider Enabled')
                            ->helperText('Lets an admin paste a meeting link by hand on a booking.'),
                        TextInput::make('platform_meeting_account')
                            ->label('Platform meeting account')
                            ->maxLength(255)
                            ->helperText('The account meetings are created under. Shown for reference only.'),
                        $this->integerInput('meeting_link_visible_before_minutes', 'Visible Before (minutes)', 0, 10080)
                            ->helperText('How long before the start the join link appears to participants.'),
                        $this->integerInput('meeting_link_visible_after_minutes', 'Visible After (minutes)', 0, 10080)
                            ->helperText('How long after the start the join link stays available.'),
                        Toggle::make('meeting_recording_enabled')
                            ->label('Record Sessions by Default')
                            ->helperText('Records new lessons when the provider supports it. Also requires the Recording feature flag in Platform Foundation.'),
                        TextInput::make('effective_recording_availability')
                            ->label('Effective Recording Availability')
                            ->helperText('Whether new lessons will actually be recorded, given both switches.')
                            ->disabled()
                            ->dehydrated(false),
                        $this->integerInput('recording_retention_days', 'Retention Days', 0, 3650)
                            ->helperText('Days a recording stays available to students before it is removed.'),
                        Toggle::make('recording_student_playback_enabled')
                            ->label('Students Can Watch Their Recordings')
                            ->helperText('Shows a "Watch recording" link on the student\'s completed lessons. Off hides all recordings from students, including existing ones. Individual recordings can still be withheld from the Recordings screen.'),
                        Toggle::make('create_after_demo_booking_confirmation')
                            ->label('Auto-create for Demo/Free Bookings')
                            ->helperText('Create the meeting link as soon as a free demo is confirmed.'),
                        Toggle::make('create_after_paid_booking_confirmation')
                            ->label('Auto-create for Paid Bookings')
                            ->helperText('Create the meeting link as soon as a paid lesson is confirmed. Off means an admin must create it.'),
                        Toggle::make('student_join_url_visible')
                            ->label('Student Can See Join Link')
                            ->helperText('Off: students never see the link, even inside the visibility window.'),
                        Toggle::make('instructor_join_url_visible')
                            ->label('Instructor Can See Join Link')
                            ->helperText('Off: instructors never see the link, even inside the visibility window.'),
                    ]),
                ]),

            Section::make('Google Calendar + Meet')
                ->description('Google Workspace service account used to create Meet links and fetch recordings. Saved credentials are never shown again.')
                ->columnSpanFull()
                ->schema([
                    Grid::make(2)->schema([
                        Toggle::make('google_meet_enabled')->label('Google Meet Enabled'),
                        Toggle::make('google_meet_recording_enabled')
                            ->label('Google Meet Recording')
                            ->helperText('Fetch Meet recordings from Drive after each lesson. The service account needs the Meet and Drive read scopes.'),
                        Select::make('google_auth_type')
                            ->label('Authentication type')
                            ->options([
                                'service_account' => 'Service Account',
                                'oauth_user' => 'OAuth User (not yet available)',
                            ])
                            // Selecting OAuth User silently stops Google Meet
                            // creation, because the provider only treats a
                            // service account as configured. Showing the option
                            // explains the roadmap; disabling it prevents an
                            // admin from breaking meetings to find that out.
                            ->disableOptionWhen(fn (string $value): bool => $value === 'oauth_user')
                            ->helperText('Service Account is the only supported type at present.')
                            ->required()
                            ->native(false),
                        TextInput::make('google_calendar_id')
                            ->label('Calendar ID')
                            ->maxLength(255),
                        Placeholder::make('google_credentials_configured_display')
                            ->label('Credentials stored')
                            ->content(fn (): string => $this->yesNo($this->data['google_credentials_configured'] ?? null)),
                        Placeholder::make('google_config_status_display')
                            ->label('Configuration status')
                            ->content(fn (): string => $this->configStatusLabel($this->data['google_config_status'] ?? null)),
                        Placeholder::make('google_last_checked_at_display')
                            ->label('Last checked')
                            ->content(fn (): string => $this->timestampLabel($this->data['google_last_checked_at'] ?? null)),
                        Placeholder::make('google_credentials_updated_at_display')
                            ->label('Credentials last replaced')
                            ->content(fn (): string => $this->timestampLabel($this->data['google_credentials_updated_at'] ?? null))
                            ->helperText('Changes only when a new JSON key is saved.'),
                    ]),
                    Textarea::make('google_credentials_json')
                        ->label('Service Account JSON')
                        ->rows(4)
                        ->helperText('Paste a new key to replace the stored one. Leave blank to keep the current key.')
                        ->columnSpanFull(),
                ]),

            Section::make('Zoom')
                ->description('Server-to-Server OAuth app for the platform\'s Zoom host account. Saved secrets are never shown again.')
                ->columnSpanFull()
                ->schema([
                    Grid::make(2)->schema([
                        Toggle::make('zoom_enabled')->label('Zoom Enabled'),
                        Toggle::make('zoom_recording_enabled')
                            ->label('Zoom Recording')
                            ->helperText('Fetch Zoom cloud recordings after each lesson. Needs a licensed Zoom account with cloud recording.'),
                        TextInput::make('zoom_account_id')
                            ->label('Account ID')
                            ->maxLength(255),
                        TextInput::make('zoom_client_id')
                            ->label('Client ID')
                            ->maxLength(255),
                        TextInput::make('zoom_client_secret')
                            ->label('Client Secret')
                            ->password()
                            ->maxLength(255)
                            ->helperText('Leave blank to keep the current secret.'),
                        TextInput::make('zoom_host_user_id')
                            ->label('Host User ID')
                            ->maxLength(255)
                            ->helperText('The Zoom user meetings are scheduled under. Used instead of Host Email when both are set.'),
                        TextInput::make('zoom_host_email')
                            ->label('Host Email')
                            ->email()
                            ->maxLength(255),
                        TextInput::make('zoom_default_timezone')
                            ->label('Default Timezone')
                            ->maxLength(64)
                            ->placeholder('e.g. Asia/Kolkata'),
                        TextInput::make('zoom_webhook_secret')
                            ->label('Webhook Secret Token')
                            ->password()
                            ->maxLength(255)
                            ->helperText('From the Zoom app\'s Event Subscriptions page. Leave blank to keep the current value.'),
                        Toggle::make('zoom_recording_webhooks_enabled')
                            ->label('Accept Zoom Recording Webhooks')
                            ->helperText('Lets Zoom notify us the moment a recording is ready. Off still works, recordings are just picked up later by the scheduled check.'),
                        Placeholder::make('zoom_config_status_display')
                            ->label('Configuration status')
                            ->content(fn (): string => $this->configStatusLabel($this->data['zoom_config_status'] ?? null)),
                        Placeholder::make('zoom_last_checked_at_display')
                            ->label('Last checked')
                            ->content(fn (): string => $this->timestampLabel($this->data['zoom_last_checked_at'] ?? null)),
                    ]),
                ]),
        ]);
    }

    /**
     * These three exist because the raw stored values are not admin-facing
     * text. `google_credentials_configured` is a boolean, which a text
     * input rendered as "1"; the status fields hold internal keys like
     * `not_configured`; and the timestamps are stored as ISO-8601
     * strings. Read-only readouts, so a Placeholder is the right
     * component — a disabled input suggests something editable.
     */
    protected function yesNo(mixed $value): string
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 'Yes' : 'No';
    }

    protected function configStatusLabel(mixed $status): string
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

    protected function timestampLabel(mixed $value): string
    {
        if (blank($value)) {
            return 'Never';
        }

        try {
            $moment = Carbon::parse((string) $value);
        } catch (\Throwable) {
            // A stored value we cannot parse is still worth showing
            // verbatim rather than swallowing into "Never".
            return (string) $value;
        }

        return $moment->timezone(config('app.timezone'))->format('j M Y, H:i').' ('.$moment->diffForHumans().')';
    }

    public function save(): void
    {
        try {
            $data = $this->form->getState();
        } catch (Halt) {
            return;
        }

        // A new (non-blank) Google credential is validated *before* any
        // field is written — malformed JSON or a service-account JSON
        // missing client_id/client_email/private_key must never reach
        // the database, and must never take down the rest of this save
        // (meetings_enabled, provider selection, Zoom fields, …).
        $newGoogleCredentials = null;

        if (filled($data['google_credentials_json'] ?? null)) {
            try {
                $newGoogleCredentials = $this->validateGoogleCredentialsJson((string) $data['google_credentials_json']);
            } catch (InvalidArgumentException $e) {
                Notification::make()
                    ->title('Google credentials not saved')
                    ->body($e->getMessage())
                    ->danger()
                    ->send();

                return;
            }
        }

        $saved = $this->saveSettingsWithAudit(MeetingSettings::class, 'settings', function (MeetingSettings $settings) use ($data, $newGoogleCredentials): void {
            $settings->meetings_enabled = (bool) ($data['meetings_enabled'] ?? false);
            $settings->default_provider = $data['default_provider'];
            $settings->manual_provider_enabled = (bool) ($data['manual_provider_enabled'] ?? false);
            $settings->platform_meeting_account = $data['platform_meeting_account'] ?? null;
            $settings->meeting_link_visible_before_minutes = (int) $data['meeting_link_visible_before_minutes'];
            $settings->meeting_link_visible_after_minutes = (int) $data['meeting_link_visible_after_minutes'];
            $settings->recording_enabled = (bool) ($data['meeting_recording_enabled'] ?? false);
            $settings->recording_retention_days = (int) $data['recording_retention_days'];
            $settings->recording_student_playback_enabled = (bool) ($data['recording_student_playback_enabled'] ?? false);
            $settings->create_after_demo_booking_confirmation = (bool) ($data['create_after_demo_booking_confirmation'] ?? false);
            $settings->create_after_paid_booking_confirmation = (bool) ($data['create_after_paid_booking_confirmation'] ?? false);
            $settings->student_join_url_visible = (bool) ($data['student_join_url_visible'] ?? false);
            $settings->instructor_join_url_visible = (bool) ($data['instructor_join_url_visible'] ?? false);

            $settings->google_meet_enabled = (bool) ($data['google_meet_enabled'] ?? false);
            $settings->google_meet_recording_enabled = (bool) ($data['google_meet_recording_enabled'] ?? false);
            $settings->google_auth_type = $data['google_auth_type'];
            $settings->google_calendar_id = filled($data['google_calendar_id'] ?? null) ? $data['google_calendar_id'] : null;

            // Blank submit keeps the existing encrypted credential — never
            // overwrite it with null just because the (deliberately never
            // re-displayed) field was left empty on this save.
            if ($newGoogleCredentials !== null) {
                $settings->google_credentials_json = Crypt::encryptString((string) $data['google_credentials_json']);
                $settings->google_credentials_updated_at = Carbon::now()->toIso8601String();
            }

            $settings->zoom_enabled = (bool) ($data['zoom_enabled'] ?? false);
            // A provider that cannot create meetings cannot record them
            // either — normalized on save so the stored state can never
            // express "recording on, provider off", which would read as
            // enabled in the UI while doing nothing.
            $settings->zoom_recording_enabled = (bool) ($data['zoom_enabled'] ?? false) && (bool) ($data['zoom_recording_enabled'] ?? false);
            $settings->zoom_recording_webhooks_enabled = $settings->zoom_recording_enabled && (bool) ($data['zoom_recording_webhooks_enabled'] ?? false);
            $settings->zoom_account_id = filled($data['zoom_account_id'] ?? null) ? $data['zoom_account_id'] : null;
            $settings->zoom_client_id = filled($data['zoom_client_id'] ?? null) ? $data['zoom_client_id'] : null;
            $settings->zoom_host_user_id = filled($data['zoom_host_user_id'] ?? null) ? $data['zoom_host_user_id'] : null;
            $settings->zoom_host_email = filled($data['zoom_host_email'] ?? null) ? $data['zoom_host_email'] : null;
            $settings->zoom_default_timezone = filled($data['zoom_default_timezone'] ?? null) ? $data['zoom_default_timezone'] : null;

            // Same blank-preserves rule as the Google credential above.
            if (filled($data['zoom_client_secret'] ?? null)) {
                $settings->zoom_client_secret = Crypt::encryptString((string) $data['zoom_client_secret']);
            }

            if (filled($data['zoom_webhook_secret'] ?? null)) {
                $settings->zoom_webhook_secret = Crypt::encryptString((string) $data['zoom_webhook_secret']);
            }
        });

        if (! $saved) {
            return;
        }

        $this->mount();

        Notification::make()
            ->title('Meeting settings saved')
            ->body($newGoogleCredentials !== null
                ? sprintf(
                    'Google credentials replaced — client_email: %s, client_id: %s',
                    $newGoogleCredentials['client_email'],
                    $newGoogleCredentials['client_id'],
                )
                : null)
            ->success()
            ->send();
    }

    /**
     * Fails closed on anything that isn't a well-formed service-account
     * JSON — never partially trusts it, never logs it.
     *
     * @return array{client_id: string, client_email: string}
     *
     * @throws InvalidArgumentException with a message safe to show the admin (no JSON/key content)
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

    public function testGoogleConfiguration(): void
    {
        $service = app(GoogleCalendarConfigurationService::class);
        $status = $service->check();
        $diagnostics = $service->lastDiagnostics();
        $reason = $service->lastDiagnostic();

        $this->mount();

        $body = $diagnostics !== null
            ? implode("\n", array_filter([
                sprintf('Client ID: %s', $diagnostics->clientId ?? 'unknown'),
                sprintf('Client email: %s', $diagnostics->clientEmail ?? 'unknown'),
                sprintf('Delegated subject: %s', $diagnostics->delegatedSubject ?? 'unknown'),
                sprintf('Requested scopes: %s', implode(', ', $diagnostics->requestedScopes)),
                sprintf('Calendar ID: %s', $diagnostics->calendarId ?? 'unknown'),
                sprintf('Token acquired: %s', $diagnostics->tokenAcquired ? 'yes' : 'no'),
                sprintf('Allowed conference types: [%s]', implode(', ', $diagnostics->allowedConferenceTypes) ?: 'none'),
                $reason !== null ? sprintf('Reason: %s', $reason) : null,
            ]))
            : $reason;

        Notification::make()
            ->title('Google configuration: '.$status)
            ->body($body)
            ->{$status === 'ready' ? 'success' : 'warning'}()
            ->send();
    }

    public function validateZoomConfiguration(): void
    {
        $service = app(ZoomConfigurationService::class);
        $status = $service->check();
        $diagnostics = $service->lastDiagnostics();
        $reason = $service->lastDiagnostic();

        $this->mount();

        $body = $diagnostics !== null
            ? implode("\n", array_filter([
                sprintf('Account ID: %s', $diagnostics->accountId ?? 'unknown'),
                sprintf('Client ID: %s', $diagnostics->clientId ?? 'unknown'),
                sprintf('Host user: %s', $diagnostics->hostUser ?? 'unknown'),
                sprintf('Token acquired: %s', $diagnostics->tokenAcquired ? 'yes' : 'no'),
                sprintf('Meeting creation verified: %s', $diagnostics->meetingCreationVerified ? 'yes' : 'no'),
                $reason !== null ? sprintf('Reason: %s', $reason) : null,
            ]))
            : $reason;

        Notification::make()
            ->title('Zoom configuration: '.$status)
            ->body($body)
            ->{$status === 'ready' ? 'success' : 'warning'}()
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
}
