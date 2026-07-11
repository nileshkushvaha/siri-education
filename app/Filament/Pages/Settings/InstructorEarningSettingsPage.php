<?php

declare(strict_types=1);

namespace App\Filament\Pages\Settings;

use App\Settings\InstructorEarningSettings;
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
            'default_calculation_type' => $settings->default_calculation_type,
            'default_percentage' => $settings->default_percentage,
            'default_fixed_amount_minor' => $settings->default_fixed_amount_minor,
            'default_currency_code' => $settings->default_currency_code,
            'hold_days' => $settings->hold_days,
            'auto_release_enabled' => $settings->auto_release_enabled,
            'minimum_settlement_amount_minor' => $settings->minimum_settlement_amount_minor,
            'settlement_frequency' => $settings->settlement_frequency,
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
                Section::make('Calculation')
                    ->description('Instructor earnings are calculated from these platform rules — never shown to students, and the student price is never shown to instructors.')
                    ->columnSpanFull()
                    ->schema([
                        Grid::make(3)->schema([
                            Toggle::make('earnings_enabled')
                                ->label('Earnings enabled')
                                ->helperText('Off = no automatic earning creation.'),
                            Select::make('default_calculation_type')
                                ->label('Default calculation')
                                ->options([
                                    'percentage' => 'Percentage of student price',
                                    'fixed' => 'Fixed amount per lesson',
                                ])
                                ->required()
                                ->native(false),
                            TextInput::make('default_percentage')
                                ->label('Instructor percentage')
                                ->numeric()
                                ->minValue(0)
                                ->maxValue(100)
                                ->suffix('%'),
                        ]),
                        Grid::make(2)->schema([
                            TextInput::make('default_fixed_amount_minor')
                                ->label('Fixed amount (minor units)')
                                ->helperText('e.g. 35000 = 350.00. Also the free/demo lesson rate.')
                                ->numeric()
                                ->minValue(0),
                            TextInput::make('default_currency_code')
                                ->label('Fixed-rate currency')
                                ->helperText('Required for fixed earnings on free/demo lessons (e.g. INR).')
                                ->maxLength(3),
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

        $settings->earnings_enabled = (bool) $data['earnings_enabled'];
        $settings->default_calculation_type = $data['default_calculation_type'];
        $settings->default_percentage = (float) $data['default_percentage'];
        $settings->default_fixed_amount_minor = $data['default_fixed_amount_minor'] !== null && $data['default_fixed_amount_minor'] !== ''
            ? (int) $data['default_fixed_amount_minor']
            : null;
        $settings->default_currency_code = $data['default_currency_code'] !== null && $data['default_currency_code'] !== ''
            ? strtoupper($data['default_currency_code'])
            : null;
        $settings->hold_days = (int) $data['hold_days'];
        $settings->auto_release_enabled = (bool) $data['auto_release_enabled'];
        $settings->minimum_settlement_amount_minor = $data['minimum_settlement_amount_minor'] !== null && $data['minimum_settlement_amount_minor'] !== ''
            ? (int) $data['minimum_settlement_amount_minor']
            : null;
        $settings->settlement_frequency = $data['settlement_frequency'];

        $settings->save();

        $this->logSettingsUpdate('settings', $settings, $before);

        Notification::make()->title('Instructor earning settings saved')->success()->send();
    }
}
