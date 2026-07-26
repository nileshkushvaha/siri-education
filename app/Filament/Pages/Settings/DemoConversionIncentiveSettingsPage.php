<?php

declare(strict_types=1);

namespace App\Filament\Pages\Settings;

use App\Filament\Navigation\Concerns\HasCentralizedNavigation;
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
 * GAP-008 (SRS §15.18) — the sole runtime-configuration surface for
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
        return 'Demo-to-Paid Conversion Incentive Settings';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'GAP-008 — SRS §15.18: instructor bonus rules for students who convert from a completed demo to a completed paid lesson.';
    }

    public function getBreadcrumbs(): array
    {
        return [
            '/admin' => 'Dashboard',
            '/admin/settings/general' => 'Settings',
            '#' => 'Demo Conversion Incentive',
        ];
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
                ->description('A single global Version 1 rule — fixed bonus only, never a percentage (SRS §15.18 recommended approach).')
                ->columnSpanFull()
                ->schema([
                    Toggle::make('enabled')
                        ->label('Incentive Enabled')
                        ->helperText('Off stops every award from being created — a real financial cost, off by default.'),
                    Grid::make(2)->schema([
                        TextInput::make('conversion_window_days')
                            ->label('Conversion Window (days)')
                            ->numeric()->integer()->minValue(1)->maxValue(365)->required(),
                        TextInput::make('min_completed_paid_lessons')
                            ->label('Minimum Completed Paid Lessons')
                            ->helperText('The converting lesson must be at least the Nth completed paid lesson for the pair.')
                            ->numeric()->integer()->minValue(1)->maxValue(100)->required(),
                    ]),
                    Grid::make(3)->schema([
                        TextInput::make('bonus_amount_minor')
                            ->label('Bonus Amount (minor units)')
                            ->helperText('e.g. 20000 = ₹200.00.')
                            ->numeric()->integer()->minValue(1)->required(),
                        TextInput::make('bonus_currency_code')
                            ->label('Currency')
                            ->helperText('Version 1: primarily INR (SRS §15.16).')
                            ->maxLength(3)->required(),
                        TextInput::make('max_awards_per_pair')
                            ->label('Max Awards per Student/Instructor Pair')
                            ->numeric()->integer()->minValue(1)->maxValue(100)->required(),
                    ]),
                ]),

            Section::make('Applicability (optional)')
                ->description('Leave empty to apply to every country/subject.')
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
