<?php

declare(strict_types=1);

namespace App\Filament\Pages\Settings;

use App\Booking\Services\GoogleCalendarConfigurationService;
use App\Booking\Services\RecordingAvailabilityResolver;
use App\Booking\Services\ZoomConfigurationService;
use App\Settings\MeetingSettings;
use BackedEnum;
use Filament\Actions\Action;
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
    use HasSettingsAccess;
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

    public function getBreadcrumbs(): array
    {
        return [
            '/admin' => 'Dashboard',
            '/admin/settings/general' => 'Settings',
            '#' => 'Meetings',
        ];
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
            'create_after_demo_booking_confirmation' => $meeting->create_after_demo_booking_confirmation,
            'create_after_paid_booking_confirmation' => $meeting->create_after_paid_booking_confirmation,
            'manual_provider_enabled' => $meeting->manual_provider_enabled,
            'student_join_url_visible' => $meeting->student_join_url_visible,
            'instructor_join_url_visible' => $meeting->instructor_join_url_visible,
            'google_meet_enabled' => $meeting->google_meet_enabled,
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
                ->description('Platform switches, provider selection, auto-creation rules, and per-session recording defaults.')
                ->columnSpanFull()
                ->schema([
                    Grid::make(2)->schema([
                        Toggle::make('meetings_enabled')
                            ->label('Meetings Enabled')
                            ->helperText('Platform-wide kill switch — off blocks every provider, including Manual.'),
                        Select::make('default_provider')
                            ->options([
                                'manual' => 'Manual',
                                'google_meet' => 'Google Meet',
                                'zoom' => 'Zoom',
                            ])
                            ->helperText('Automatic creation on booking confirmation uses this provider unless an admin explicitly picks another from the Booking action. Selecting an unconfigured provider makes creation fail safely (status: failed) — it never silently falls back.')
                            ->required()
                            ->native(false),
                        Toggle::make('manual_provider_enabled')->label('Manual Provider Enabled'),
                        TextInput::make('platform_meeting_account')->maxLength(255),
                        $this->integerInput('meeting_link_visible_before_minutes', 'Visible Before', 0, 10080),
                        $this->integerInput('meeting_link_visible_after_minutes', 'Visible After', 0, 10080),
                        Toggle::make('meeting_recording_enabled')
                            ->label('Record Sessions by Default')
                            ->helperText('Never takes effect on its own — the platform-wide Recording feature flag (Settings → Platform Foundation) must also be enabled. This setting cannot override a disabled global switch.'),
                        TextInput::make('effective_recording_availability')
                            ->label('Effective Recording Availability')
                            ->helperText('Computed live from the platform-wide Recording flag AND this meeting-level default — both must be enabled for recording to be considered available.')
                            ->disabled()
                            ->dehydrated(false),
                        $this->integerInput('recording_retention_days', 'Retention Days', 0, 3650),
                        Toggle::make('create_after_demo_booking_confirmation')
                            ->label('Auto-create for Demo/Free Bookings'),
                        Toggle::make('create_after_paid_booking_confirmation')
                            ->label('Auto-create for Paid Bookings'),
                        Toggle::make('student_join_url_visible')->label('Student Can See Join Link'),
                        Toggle::make('instructor_join_url_visible')->label('Instructor Can See Join Link'),
                    ]),
                ]),

            Section::make('Google Calendar + Meet')
                ->description('Service-account credentials for GoogleCalendarMeetProvider. The JSON is never displayed after saving — leave it blank to keep the existing value.')
                ->columnSpanFull()
                ->schema([
                    Grid::make(2)->schema([
                        Toggle::make('google_meet_enabled')->label('Google Meet Enabled'),
                        Select::make('google_auth_type')
                            ->options([
                                'service_account' => 'Service Account',
                                'oauth_user' => 'OAuth User (not yet supported)',
                            ])
                            ->helperText('Only Service Account is implemented — OAuth User is reserved for a future connection screen.')
                            ->required()
                            ->native(false),
                        TextInput::make('google_calendar_id')
                            ->label('Calendar ID')
                            ->maxLength(255),
                        TextInput::make('google_credentials_configured')
                            ->label('Credentials Stored')
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('google_config_status')
                            ->label('Config Status')
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('google_last_checked_at')
                            ->label('Last Checked')
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('google_credentials_updated_at')
                            ->label('Credentials Last Replaced')
                            ->helperText('Set only when a new service account JSON is pasted and saved — proves a key rotation actually took effect.')
                            ->disabled()
                            ->dehydrated(false),
                    ]),
                    Textarea::make('google_credentials_json')
                        ->label('Service Account JSON')
                        ->rows(4)
                        ->helperText('Paste the downloaded service-account JSON to replace the stored credential. Leave blank to keep the current one — it is never re-displayed here.')
                        ->columnSpanFull(),
                ]),

            Section::make('Zoom')
                ->description('Server-to-Server OAuth credentials for ZoomMeetingProvider (platform-owned host account). The client secret is never displayed after saving — leave it blank to keep the existing value.')
                ->columnSpanFull()
                ->schema([
                    Grid::make(2)->schema([
                        Toggle::make('zoom_enabled')->label('Zoom Enabled'),
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
                            ->helperText('Leave blank to keep the current secret — it is never re-displayed here.'),
                        TextInput::make('zoom_host_user_id')
                            ->label('Host User ID')
                            ->maxLength(255)
                            ->helperText('Zoom user id the platform schedules meetings under. Takes precedence over host email.'),
                        TextInput::make('zoom_host_email')
                            ->label('Host Email')
                            ->email()
                            ->maxLength(255),
                        TextInput::make('zoom_default_timezone')
                            ->label('Default Timezone')
                            ->maxLength(64)
                            ->placeholder('e.g. Asia/Kolkata'),
                        TextInput::make('zoom_config_status')
                            ->label('Config Status')
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('zoom_last_checked_at')
                            ->label('Last Checked')
                            ->disabled()
                            ->dehydrated(false),
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
            $settings->create_after_demo_booking_confirmation = (bool) ($data['create_after_demo_booking_confirmation'] ?? false);
            $settings->create_after_paid_booking_confirmation = (bool) ($data['create_after_paid_booking_confirmation'] ?? false);
            $settings->student_join_url_visible = (bool) ($data['student_join_url_visible'] ?? false);
            $settings->instructor_join_url_visible = (bool) ($data['instructor_join_url_visible'] ?? false);

            $settings->google_meet_enabled = (bool) ($data['google_meet_enabled'] ?? false);
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
            $settings->zoom_account_id = filled($data['zoom_account_id'] ?? null) ? $data['zoom_account_id'] : null;
            $settings->zoom_client_id = filled($data['zoom_client_id'] ?? null) ? $data['zoom_client_id'] : null;
            $settings->zoom_host_user_id = filled($data['zoom_host_user_id'] ?? null) ? $data['zoom_host_user_id'] : null;
            $settings->zoom_host_email = filled($data['zoom_host_email'] ?? null) ? $data['zoom_host_email'] : null;
            $settings->zoom_default_timezone = filled($data['zoom_default_timezone'] ?? null) ? $data['zoom_default_timezone'] : null;

            // Same blank-preserves rule as the Google credential above.
            if (filled($data['zoom_client_secret'] ?? null)) {
                $settings->zoom_client_secret = Crypt::encryptString((string) $data['zoom_client_secret']);
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
