<?php

declare(strict_types=1);

namespace App\Filament\Pages\Settings;

use App\Filament\Navigation\Concerns\HasCentralizedNavigation;
use App\Filament\Navigation\Concerns\HasSettingsSectionBreadcrumb;
use App\Models\Country;
use App\Models\Subject;
use App\Settings\DemoConversionIncentiveSettings;
use BackedEnum;
use Filament\Actions\Action;
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

/**
 * SRS §15.18 — the sole runtime-configuration surface for
 * DemoConversionIncentiveSettings. A single global rule (not a
 * multi-row campaign framework, deliberately excluded) — country/
 * subject applicability are simple "empty = applies to all" ID
 * allowlists, mirroring ReferralCampaign's own eligible-countries
 * convention without needing a new pivot table for a singleton rule.
 */
class DemoConversionIncentiveSettingsPage extends Page
{
    use HasCentralizedNavigation;
    use HasSettingsAccess;
    use HasSettingsSectionBreadcrumb;
    use LogsSettingsUpdates;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGift;

    protected static ?string $navigationLabel = 'Demo Conversion Incentive';

    protected static string|\UnitEnum|null $navigationGroup = 'Platform';

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'settings/demo-conversion-incentive';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function getLabel(): string
    {
        return 'Demo Conversion Incentive';
    }

    public function getTitle(): string|Htmlable
    {
        return 'Demo Conversion Incentive';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Configure the bonus instructors earn when a student converts from a completed demo lesson to a completed paid lesson.';
    }

    public function mount(): void
    {
        $settings = app(DemoConversionIncentiveSettings::class);

        $this->form->fill([
            'enabled' => $settings->enabled,
            'conversion_window_days' => $settings->conversion_window_days,
            'min_completed_paid_lessons' => $settings->min_completed_paid_lessons,
            'bonus_amount_minor' => $settings->bonus_amount_minor,
            'bonus_currency_code' => $settings->bonus_currency_code,
            'max_awards_per_pair' => $settings->max_awards_per_pair,
            'applicable_country_ids' => $settings->applicable_country_ids,
            'applicable_subject_ids' => $settings->applicable_subject_ids,
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
                            ->label('Save Incentive Settings')
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
            Section::make('Rule')
                ->description('Configure the one active bonus rule. Bonuses are a fixed amount — this cannot be set as a percentage of the lesson price.')
                ->columnSpanFull()
                ->schema([
                    Toggle::make('enabled')
                        ->label('Enable incentive')
                        ->helperText('When disabled, no new incentive awards are created.'),
                    Grid::make(2)->schema([
                        TextInput::make('conversion_window_days')
                            ->label('Conversion window (days)')
                            ->helperText('Maximum number of days between the demo lesson and the qualifying paid lesson.')
                            ->numeric()->integer()->minValue(1)->maxValue(365)->required(),
                        TextInput::make('min_completed_paid_lessons')
                            ->label('Minimum completed paid lessons')
                            ->helperText('The student must complete at least this many paid lessons with the instructor before the bonus can be awarded.')
                            ->numeric()->integer()->minValue(1)->maxValue(100)->required(),
                    ]),
                    Grid::make(3)->schema([
                        TextInput::make('bonus_amount_minor')
                            ->label('Bonus amount (smallest currency unit)')
                            ->helperText('Enter the amount in the smallest unit of the currency below — e.g. paise for INR, cents for USD. For example, 20000 equals 200.00 in that currency\'s main unit.')
                            ->numeric()->integer()->minValue(1)->required(),
                        TextInput::make('bonus_currency_code')
                            ->label('Currency')
                            ->helperText('3-letter currency code for the bonus, e.g. INR or USD.')
                            ->maxLength(3)->required(),
                        TextInput::make('max_awards_per_pair')
                            ->label('Maximum awards per student-instructor pair')
                            ->helperText('No further bonus is awarded to the same student and instructor once this many have been given.')
                            ->numeric()->integer()->minValue(1)->maxValue(100)->required(),
                    ]),
                ]),

            Section::make('Applicability')
                ->description('Leave blank to make the rule available for all countries and subjects.')
                ->columnSpanFull()
                ->schema([
                    Select::make('applicable_country_ids')
                        ->label('Countries')
                        ->multiple()
                        ->options(fn (): array => Country::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->searchable(),
                    Select::make('applicable_subject_ids')
                        ->label('Subjects')
                        ->multiple()
                        ->options(fn (): array => Subject::query()->active()->orderBy('name')->pluck('name', 'id')->all())
                        ->searchable(),
                ]),
        ]);
    }

    public function save(): void
    {
        try {
            $data = $this->form->getState();
        } catch (Halt) {
            Notification::make()
                ->title('Demo conversion incentive settings were not saved')
                ->body('Please correct the highlighted fields.')
                ->danger()
                ->send();

            return;
        }

        $saved = $this->saveSettingsWithAudit(DemoConversionIncentiveSettings::class, 'demo_conversion_incentive', function (DemoConversionIncentiveSettings $settings) use ($data): void {
            $settings->enabled = (bool) $data['enabled'];
            $settings->conversion_window_days = (int) $data['conversion_window_days'];
            $settings->min_completed_paid_lessons = (int) $data['min_completed_paid_lessons'];
            $settings->bonus_amount_minor = (int) $data['bonus_amount_minor'];
            $settings->bonus_currency_code = strtoupper((string) $data['bonus_currency_code']);
            $settings->max_awards_per_pair = (int) $data['max_awards_per_pair'];
            $settings->applicable_country_ids = array_values(array_map(intval(...), $data['applicable_country_ids'] ?? []));
            $settings->applicable_subject_ids = array_values(array_map(intval(...), $data['applicable_subject_ids'] ?? []));
        });

        if (! $saved) {
            return;
        }

        $this->mount();

        Notification::make()
            ->title('Demo conversion incentive settings saved')
            ->success()
            ->send();
    }
}
