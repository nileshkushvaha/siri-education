<?php

declare(strict_types=1);

namespace App\Filament\Pages\Settings;

use App\Filament\Navigation\Concerns\HasCentralizedNavigation;
use App\Settings\HomeworkSettings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TagsInput;
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

/**
 * Phase 24K — GAP-020: the sole runtime-configuration surface for
 * HomeworkSettings' reminder fields. Offsets are validated here
 * (bounded, positive, deduplicated, at least one when enabled) since
 * Spatie Settings has no native array-content validation.
 */
class HomeworkReminderSettingsPage extends Page
{
    use HasCentralizedNavigation;
    use HasSettingsAccess;
    use LogsSettingsUpdates;

    private const int MAX_OFFSET_HOURS = 336; // 14 days — a generous but bounded ceiling

    private const int MAX_OFFSETS = 5;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBellAlert;

    protected static ?string $navigationLabel = 'Homework Reminders';

    protected static string|\UnitEnum|null $navigationGroup = 'Platform';

    protected static ?int $navigationSort = 9;

    protected static ?string $slug = 'settings/homework-reminders';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function getLabel(): string
    {
        return 'Homework Reminders';
    }

    public function getTitle(): string|Htmlable
    {
        return 'Homework Due-Date Reminder Settings';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'GAP-020 — SRS §7.11: administrator-configured reminder schedule and channel routing.';
    }

    public function getBreadcrumbs(): array
    {
        return [
            '/admin' => 'Dashboard',
            '/admin/settings/general' => 'Settings',
            '#' => 'Homework Reminders',
        ];
    }

    public function mount(): void
    {
        $settings = app(HomeworkSettings::class);

        $this->form->fill([
            'homework_due_reminders_enabled' => $settings->homework_due_reminders_enabled,
            'homework_due_reminder_offset_hours' => array_map(strval(...), $settings->homework_due_reminder_offset_hours),
            'homework_reminder_channel_email_enabled' => $settings->homework_reminder_channel_email_enabled,
            'homework_reminder_channel_whatsapp_enabled' => $settings->homework_reminder_channel_whatsapp_enabled,
            'homework_reminder_channel_sms_enabled' => $settings->homework_reminder_channel_sms_enabled,
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
                            ->label('Save Reminder Settings')
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
            Section::make('Schedule')
                ->description('Reminders are claimed once each offset threshold is reached and the assignment is still incomplete.')
                ->columnSpanFull()
                ->schema([
                    Toggle::make('homework_due_reminders_enabled')
                        ->label('Due-Date Reminders Enabled')
                        ->helperText('Off stops the scheduler from claiming or sending any reminder.'),
                    TagsInput::make('homework_due_reminder_offset_hours')
                        ->label('Reminder Offsets (hours before due time)')
                        ->helperText(sprintf(
                            'Whole hours, 1–%d, up to %d offsets. Example: 24 sends one reminder a day before the due time.',
                            self::MAX_OFFSET_HOURS,
                            self::MAX_OFFSETS,
                        ))
                        ->placeholder('e.g. 24'),
                ]),

            Section::make('Notification Channel Routing')
                ->description('In-app delivery is always on for real users.')
                ->columnSpanFull()
                ->schema([
                    Grid::make(3)->schema([
                        Toggle::make('homework_reminder_channel_email_enabled')->label('Email'),
                        Toggle::make('homework_reminder_channel_whatsapp_enabled')
                            ->label('WhatsApp')
                            ->helperText('No gateway is configured yet — messages log and skip until one is wired in.'),
                        Toggle::make('homework_reminder_channel_sms_enabled')
                            ->label('SMS')
                            ->helperText('No gateway is configured yet — messages log and skip until one is wired in.'),
                    ]),
                ]),
        ]);
    }

    public function save(): void
    {
        try {
            $data = $this->form->getState();
        } catch (Halt) {
            Notification::make()
                ->title('Homework reminder settings were not saved')
                ->body('Please correct the highlighted fields.')
                ->danger()
                ->send();

            return;
        }

        $errors = $this->validateOffsets($data);

        if ($errors !== []) {
            Notification::make()
                ->title('Homework reminder settings were not saved')
                ->body(implode("\n", $errors))
                ->danger()
                ->send();

            return;
        }

        $offsets = $this->normalizedOffsets($data['homework_due_reminder_offset_hours']);

        $saved = $this->saveSettingsWithAudit(HomeworkSettings::class, 'homework', function (HomeworkSettings $settings) use ($data, $offsets): void {
            $settings->homework_due_reminders_enabled = (bool) $data['homework_due_reminders_enabled'];
            $settings->homework_due_reminder_offset_hours = $offsets;
            $settings->homework_reminder_channel_email_enabled = (bool) $data['homework_reminder_channel_email_enabled'];
            $settings->homework_reminder_channel_whatsapp_enabled = (bool) $data['homework_reminder_channel_whatsapp_enabled'];
            $settings->homework_reminder_channel_sms_enabled = (bool) $data['homework_reminder_channel_sms_enabled'];
        });

        if (! $saved) {
            return;
        }

        $this->mount();

        Notification::make()
            ->title('Homework reminder settings saved')
            ->success()
            ->send();
    }

    /** @param array<string, mixed> $data @return list<string> */
    private function validateOffsets(array $data): array
    {
        $errors = [];
        $raw = $data['homework_due_reminder_offset_hours'] ?? [];

        foreach ($raw as $value) {
            if (! is_numeric($value) || (int) $value != $value || (int) $value <= 0) {
                $errors[] = 'Reminder offsets must be positive whole numbers of hours.';

                break;
            }

            if ((int) $value > self::MAX_OFFSET_HOURS) {
                $errors[] = sprintf('Reminder offsets must not exceed %d hours.', self::MAX_OFFSET_HOURS);

                break;
            }
        }

        if (count(array_unique(array_map(intval(...), $raw))) > self::MAX_OFFSETS) {
            $errors[] = sprintf('No more than %d distinct reminder offsets are allowed.', self::MAX_OFFSETS);
        }

        if (($data['homework_due_reminders_enabled'] ?? false) && $this->normalizedOffsets($raw) === []) {
            $errors[] = 'At least one reminder offset is required while reminders are enabled.';
        }

        return $errors;
    }

    /** @param list<mixed> $raw @return list<int> */
    private function normalizedOffsets(array $raw): array
    {
        $offsets = array_values(array_unique(array_map(intval(...), array_filter(
            $raw,
            fn (mixed $value): bool => is_numeric($value) && (int) $value > 0,
        ))));

        sort($offsets);

        return $offsets;
    }
}
