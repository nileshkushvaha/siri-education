<?php

declare(strict_types=1);

namespace App\Filament\Pages\Settings;

use App\Ai\Contracts\AiProviderRegistryInterface;
use App\Ai\Contracts\AiRunRepositoryInterface;
use App\Ai\DTOs\AiProviderHealth;
use App\Ai\Enums\AiFeature;
use App\Ai\Services\AiBudgetGuard;
use App\Ai\Services\AiCostEstimator;
use App\Ai\Services\AiCredentialStore;
use App\Ai\Services\AiProviderResolver;
use App\Filament\Navigation\Concerns\HasCentralizedNavigation;
use App\Filament\Navigation\Concerns\HasSettingsSectionBreadcrumb;
use App\Filament\Pages\AiEvaluationDashboard;
use App\Settings\AiSettings;
use App\Settings\FeatureSettings;
use App\Support\Timezone\ViewerDateTime;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\KeyValue;
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
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * AI platform configuration — provider, credentials, model roles,
 * capability flags and spend ceilings.
 *
 * THE API KEY IS NEVER RE-DISPLAYED. It starts blank on mount, a blank
 * submission means "keep the stored value", and the only readback is a
 * yes/no "configured" indicator — so the secret never enters the
 * Livewire payload that a browser (or a browser extension, or a
 * screenshot) can see. Same rule as the Payment Gateway, Meeting and
 * RazorpayX pages.
 *
 * The capability flags shown here are P1-P4 switches. In P0 they are
 * inert by design: no prompt is registered for those features, so
 * turning one on changes nothing until its phase ships.
 */
class AiSettingsPage extends Page
{
    use HasCentralizedNavigation;
    use HasSettingsAccess;
    use HasSettingsSectionBreadcrumb;
    use LogsSettingsUpdates;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static ?string $navigationLabel = 'AI Platform';

    protected static string|\UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $slug = 'settings/ai';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function getLabel(): string
    {
        return 'AI Platform Settings';
    }

    public function getTitle(): string|Htmlable
    {
        return 'AI Platform Settings';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Provider, credentials, models and spend limits for the AI platform layer. AI never approves, pays, refunds or suspends anything — it only summarizes, classifies and suggests.';
    }

    public function mount(): void
    {
        $settings = app(AiSettings::class);
        $features = app(FeatureSettings::class);

        $this->form->fill([
            'ai_enabled' => $features->ai_enabled,
            'provider' => $settings->provider,
            // Never re-displayed — left blank on mount.
            'openai_api_key' => null,
            'openai_organization' => $settings->openai_organization,
            'generation_model' => $settings->generation_model,
            'fast_model' => $settings->fast_model,
            'embedding_model' => $settings->embedding_model,
            'moderation_model' => $settings->moderation_model,
            'request_timeout_seconds' => $settings->request_timeout_seconds,
            'quality_insights_enabled' => $settings->quality_insights_enabled,
            'homework_assistant_enabled' => $settings->homework_assistant_enabled,
            'lesson_summary_enabled' => $settings->lesson_summary_enabled,
            'communication_moderation_enabled' => $settings->communication_moderation_enabled,
            'daily_cost_limit' => $settings->daily_cost_limit,
            'monthly_cost_limit' => $settings->monthly_cost_limit,
            'cost_currency' => $settings->cost_currency,
            // Stored as a fraction, edited as a percentage — operators
            // think in "warn me at 80%", not 0.8.
            'budget_alert_threshold_percent' => $settings->budget_alert_threshold === null
                ? null
                : (int) round($settings->budget_alert_threshold * 100),
            'model_pricing' => $settings->model_pricing,
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
            Grid::make(1)->schema([
                Section::make('Current state')
                    ->description('What is saved and in force right now.')
                    ->icon('heroicon-o-signal')
                    ->columnSpanFull()
                    ->schema([
                        Placeholder::make('status_overview')
                            ->hiddenLabel()
                            ->content(fn (): View => view(
                                'filament.pages.settings.partials.ai-status',
                                ['status' => $this->statusOverview()],
                            )),
                        ActionsComponent::make([
                            Action::make('check_health')
                                ->label('Test connection')
                                ->icon('heroicon-o-bolt')
                                ->color('gray')
                                ->modalHeading('Test the AI provider connection')
                                ->modalDescription('Verifies the stored credential against the saved provider. Sends no prompt and no platform content. Unsaved changes on this page are not used.')
                                ->modalSubmitActionLabel('Run test')
                                ->requiresConfirmation()
                                ->visible(fn (): bool => auth()->user()?->can('TestConnection:AiPlatform') ?? false)
                                ->action('checkHealth'),
                            Action::make('open_evaluation')
                                ->label('View AI evaluation')
                                ->icon('heroicon-o-chart-bar-square')
                                ->color('gray')
                                ->url(fn (): string => AiEvaluationDashboard::getUrl())
                                ->visible(fn (): bool => AiEvaluationDashboard::canAccess()),
                        ])->key('status-actions'),
                    ]),

                Section::make('Provider & credentials')
                    ->description('Secrets are never redisplayed. Leave the API key blank to keep the stored value.')
                    ->icon('heroicon-o-key')
                    ->columnSpanFull()
                    ->schema([
                        Grid::make(2)->schema([
                            Toggle::make('ai_enabled')
                                ->label('AI platform enabled')
                                ->live()
                                ->helperText('Master switch. While off, no AI provider call can be made whatever the capability flags say.')
                                ->hintIcon('heroicon-o-exclamation-triangle')
                                ->hintIconTooltip('Enabling this allows platform data to be sent to the configured provider and starts incurring cost.')
                                ->hintColor('warning'),
                            Select::make('provider')
                                ->label('Primary provider')
                                ->options(fn (): array => app(AiProviderRegistryInterface::class)->options())
                                ->required()
                                ->live()
                                ->native(false)
                                ->helperText('"Fake" makes no external call and costs nothing — the safe choice for staging.'),
                        ]),

                        // Shown the moment the two settings contradict
                        // each other, rather than only on save: switching
                        // to OpenAI without a key used to look fine here
                        // and fail silently on every AI request.
                        Placeholder::make('credential_warning')
                            ->hiddenLabel()
                            ->columnSpanFull()
                            ->visible(fn (Get $get): bool => $get('provider') === 'openai' && ! $this->hasStoredKey() && blank($get('openai_api_key')))
                            ->content(new HtmlString(
                                '<p class="text-sm text-amber-600 dark:text-amber-400">OpenAI is selected but no API key is stored. '
                                .'Add one below, or the platform will refuse every AI request.</p>',
                            )),

                        Grid::make(2)->schema([
                            TextInput::make('openai_api_key')
                                ->label('OpenAI API key')
                                ->password()
                                ->revealable()
                                ->live(onBlur: true)
                                ->autocomplete('new-password')
                                ->placeholder($this->hasStoredKey() ? 'A key is stored — leave blank to keep it' : 'sk-…')
                                ->helperText('Encrypted at rest and never shown again after saving.')
                                ->hint($this->hasStoredKey() ? 'Configured' : 'Not configured')
                                ->hintColor($this->hasStoredKey() ? 'success' : 'danger')
                                // A pasted key with stray whitespace is a
                                // classic silent 401; trim rather than let
                                // the provider reject it later.
                                ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? trim($state) : null)
                                ->maxLength(255),
                            TextInput::make('openai_organization')
                                ->label('OpenAI organization')
                                ->placeholder('org-… (optional)')
                                ->helperText('Only needed if your OpenAI account bills through a specific organization.')
                                ->maxLength(191),
                        ]),
                    ]),

                Section::make('Models')
                    ->description('Business code asks for a model ROLE, never a name — changing a model here changes it everywhere at once.')
                    ->icon('heroicon-o-cpu-chip')
                    ->columnSpanFull()
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('generation_model')
                                ->label('Generation model')
                                ->helperText('Highest quality. Used for insights, feedback drafts and lesson summaries.')
                                ->required()
                                ->rules(['regex:/^[A-Za-z0-9._:-]+$/'])
                                ->validationMessages(['regex' => 'A model name contains no spaces — check for a stray character.'])
                                ->maxLength(64),
                            TextInput::make('fast_model')
                                ->label('Fast model')
                                ->helperText('Cheaper. Used for high-volume classification such as message risk.')
                                ->required()
                                ->rules(['regex:/^[A-Za-z0-9._:-]+$/'])
                                ->validationMessages(['regex' => 'A model name contains no spaces — check for a stray character.'])
                                ->maxLength(64),
                            TextInput::make('embedding_model')
                                ->label('Embedding model')
                                ->helperText('Reserved. Nothing stores embeddings today.')
                                ->required()
                                ->rules(['regex:/^[A-Za-z0-9._:-]+$/'])
                                ->maxLength(64),
                            TextInput::make('moderation_model')
                                ->label('Moderation model')
                                ->helperText('Safety classifier for reported messages.')
                                ->required()
                                ->rules(['regex:/^[A-Za-z0-9._:-]+$/'])
                                ->maxLength(64),
                        ]),
                        TextInput::make('request_timeout_seconds')
                            ->label('Request timeout')
                            ->suffix('seconds')
                            ->numeric()
                            ->required()
                            ->minValue(5)
                            ->maxValue(120)
                            ->helperText('How long to wait for a provider response before failing the run. Queued jobs allow 120s in total.'),
                    ]),

                Section::make('Capabilities')
                    ->description('Which AI features may run. Each still requires the master switch above.')
                    ->icon('heroicon-o-squares-2x2')
                    ->columnSpanFull()
                    ->schema([
                        Placeholder::make('capabilities_inert')
                            ->hiddenLabel()
                            ->columnSpanFull()
                            ->visible(fn (Get $get): bool => ! $get('ai_enabled') && $this->anyCapabilityOn($get))
                            ->content(new HtmlString(
                                '<p class="text-sm text-amber-600 dark:text-amber-400">These capabilities are switched on but inert: '
                                .'the master switch above is off, so nothing will run.</p>',
                            )),
                        Grid::make(2)->schema([
                            Toggle::make('quality_insights_enabled')
                                ->label('Admin quality insights')
                                ->helperText('Advisory briefings on instructor quality signals. Admin-only.'),
                            Toggle::make('homework_assistant_enabled')
                                ->label('Homework feedback copilot')
                                ->helperText('Feedback drafts an instructor edits and publishes. Never grades.'),
                            Toggle::make('lesson_summary_enabled')
                                ->label('Lesson summaries')
                                ->helperText('Draft lesson write-ups an instructor approves. Never touches progress.'),
                            Toggle::make('communication_moderation_enabled')
                                ->label('Communication safety')
                                ->helperText('Flags risky messages for review. Never blocks or restricts anyone.'),
                        ]),
                    ]),

                Section::make('Cost controls')
                    ->description('Ceilings apply to ESTIMATED spend across every AI feature.')
                    ->icon('heroicon-o-banknotes')
                    ->columnSpanFull()
                    ->schema([
                        Grid::make(3)->schema([
                            TextInput::make('daily_cost_limit')
                                ->label('Daily limit')
                                ->numeric()
                                ->minValue(0)
                                ->step(0.01)
                                ->placeholder('Blank = no limit')
                                ->helperText('Blank means unlimited. 0 stops all AI spend immediately.'),
                            TextInput::make('monthly_cost_limit')
                                ->label('Monthly limit')
                                ->numeric()
                                ->minValue(0)
                                ->step(0.01)
                                ->placeholder('Blank = no limit')
                                // A monthly ceiling below the daily one
                                // can never be reached in the order an
                                // operator expects; catch the typo here.
                                ->rules([
                                    fn (Get $get): Closure => function (string $attribute, $value, Closure $fail) use ($get): void {
                                        $daily = $get('daily_cost_limit');

                                        if (filled($value) && filled($daily) && (float) $value < (float) $daily) {
                                            $fail('The monthly limit cannot be lower than the daily limit.');
                                        }
                                    },
                                ])
                                ->helperText('Blank means unlimited.'),
                            TextInput::make('cost_currency')
                                ->label('Cost currency')
                                ->required()
                                ->length(3)
                                ->rules(['alpha'])
                                ->extraInputAttributes(['style' => 'text-transform: uppercase'])
                                ->helperText('The currency your price table is written in.'),
                        ]),

                        TextInput::make('budget_alert_threshold_percent')
                            ->label('Warn at')
                            ->suffix('% of a limit')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(100)
                            ->placeholder('Blank = no alerts')
                            ->helperText('Raises an operational alert before the ceiling starts blocking requests. Checked hourly.'),

                        KeyValue::make('model_pricing')
                            ->label('Model pricing')
                            ->keyLabel('Model name')
                            ->valueLabel('Input/output price per 1M tokens')
                            ->addActionLabel('Add a model price')
                            ->helperText('Estimation only, never billing. Write the input and output price separated by a slash, e.g. "2.0/8.0". A model missing from this list is estimated at zero, which understates spend and can stop the budget ceiling ever being reached.')
                            // The most dangerous silent failure on this
                            // page: an unparseable price estimates at
                            // zero, so spend looks free and no ceiling is
                            // ever hit. Rejected at save instead.
                            ->rules([
                                fn (): Closure => function (string $attribute, $value, Closure $fail): void {
                                    foreach ((array) $value as $model => $price) {
                                        if (blank($model)) {
                                            $fail('Every price needs a model name.');

                                            return;
                                        }

                                        if (preg_match('/^\\s*\\d+(\\.\\d+)?\\s*\\/\\s*\\d+(\\.\\d+)?\\s*$/', (string) $price) !== 1) {
                                            $fail(sprintf('The price for "%s" must be two numbers separated by a slash, e.g. "2.0/8.0".', $model));

                                            return;
                                        }
                                    }
                                },
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

        // The one combination that cannot be allowed to save: the module
        // switched on against a provider with no usable credential. It
        // used to save happily and then fail every AI request at run
        // time, which an operator only discovers from an empty feature.
        if ((bool) $data['ai_enabled'] && $data['provider'] === 'openai' && ! $this->hasStoredKey() && blank($data['openai_api_key'] ?? null)) {
            Notification::make()
                ->title('AI not enabled')
                ->body('OpenAI is selected but no API key is stored. Add a key, or switch the provider to "Fake", before enabling the platform.')
                ->danger()
                ->persistent()
                ->send();

            return;
        }

        $featuresSaved = $this->saveSettingsWithAudit(FeatureSettings::class, 'ai', function (FeatureSettings $settings) use ($data): void {
            $settings->ai_enabled = (bool) $data['ai_enabled'];
        });

        if (! $featuresSaved) {
            return;
        }

        $saved = $this->saveSettingsWithAudit(AiSettings::class, 'ai', function (AiSettings $settings) use ($data): void {
            $settings->provider = $data['provider'];
            $settings->openai_organization = filled($data['openai_organization']) ? $data['openai_organization'] : null;
            $settings->generation_model = $data['generation_model'];
            $settings->fast_model = $data['fast_model'];
            $settings->embedding_model = $data['embedding_model'];
            $settings->moderation_model = $data['moderation_model'];
            $settings->request_timeout_seconds = (int) $data['request_timeout_seconds'];
            $settings->quality_insights_enabled = (bool) $data['quality_insights_enabled'];
            $settings->homework_assistant_enabled = (bool) $data['homework_assistant_enabled'];
            $settings->lesson_summary_enabled = (bool) $data['lesson_summary_enabled'];
            $settings->communication_moderation_enabled = (bool) $data['communication_moderation_enabled'];
            $settings->daily_cost_limit = $this->nullableFloat($data['daily_cost_limit'] ?? null);
            $settings->monthly_cost_limit = $this->nullableFloat($data['monthly_cost_limit'] ?? null);
            $settings->cost_currency = strtoupper((string) $data['cost_currency']);
            $settings->budget_alert_threshold = filled($data['budget_alert_threshold_percent'] ?? null)
                ? round(((float) $data['budget_alert_threshold_percent']) / 100, 4)
                : null;
            $settings->model_pricing = $this->normalizePricing($data['model_pricing'] ?? []);

            // A blank submission keeps the stored secret — the field is
            // never populated on mount, so "empty" can only mean
            // "unchanged", never "clear it".
            if (filled($data['openai_api_key'] ?? null)) {
                $settings->openai_api_key = Crypt::encryptString((string) $data['openai_api_key']);
            }
        });

        if (! $saved) {
            return;
        }

        Notification::make()->title('AI settings saved')->success()->send();

        $this->mount();
    }

    public function checkHealth(): void
    {
        abort_unless(auth()->user()?->can('TestConnection:AiPlatform') ?? false, 403);

        try {
            $health = app(AiProviderResolver::class)->active()->healthCheck();
        } catch (Throwable) {
            $health = new AiProviderHealth(healthy: false, safeMessage: 'The AI provider is not configured.');
        }

        $this->saveSettingsWithAudit(AiSettings::class, 'ai', function (AiSettings $settings) use ($health): void {
            $settings->last_health_status = $health->healthy ? 'healthy' : 'unhealthy';
            $settings->last_health_check_at = now()->toIso8601String();
        });

        $usingFake = app(AiSettings::class)->provider === 'fake';

        Notification::make()
            ->title(match (true) {
                // A green tick from the fake provider means nothing about
                // a real one, and reading it as "we are live" is exactly
                // the mistake this wording prevents.
                $usingFake => 'Fake provider — nothing was tested',
                $health->healthy => 'AI provider reachable',
                default => 'AI provider check failed',
            })
            ->body($usingFake
                ? 'The saved provider is "Fake", which makes no external call. Switch to a real provider to test credentials.'
                : $health->safeMessage)
            ->status(match (true) {
                $usingFake => 'warning',
                $health->healthy => 'success',
                default => 'danger',
            })
            ->send();
    }

    /**
     * The saved-state view model. Reads STORED settings only — the panel
     * is explicit that unsaved edits are not reflected, because a status
     * line that silently disagreed with the toggle above it was the most
     * confusing thing about the previous version of this page.
     *
     * @return array<string, mixed>
     */
    private function statusOverview(): array
    {
        $settings = app(AiSettings::class);
        $features = app(FeatureSettings::class);
        $budget = app(AiBudgetGuard::class);

        $enabled = $features->ai_enabled;
        $hasKey = $this->hasStoredKey();
        $usingFake = $settings->provider === 'fake';

        return [
            'currency' => $settings->cost_currency,
            'module' => $enabled
                ? ['label' => 'AI platform enabled', 'color' => 'success']
                : ['label' => 'AI platform disabled', 'color' => 'gray'],
            'provider' => [
                'label' => 'Provider: '.(app(AiProviderRegistryInterface::class)->options()[$settings->provider] ?? $settings->provider),
                'color' => $usingFake ? 'info' : 'primary',
            ],
            'credential' => match (true) {
                $usingFake => ['label' => 'No credential needed', 'color' => 'gray'],
                $hasKey => ['label' => 'Credential stored', 'color' => 'success'],
                default => ['label' => 'No credential', 'color' => 'danger'],
            },
            'warnings' => $this->warnings($settings, $features),
            'budgets' => [
                $this->budgetCard('Spent today', $budget->spentToday(), $settings->daily_cost_limit, $settings),
                $this->budgetCard('Spent this month', $budget->spentThisMonth(), $settings->monthly_cost_limit, $settings),
            ],
            'runsToday' => number_format(app(AiRunRepositoryInterface::class)->countSince(Carbon::now()->startOfDay())),
            'health' => $this->healthCard($settings),
            'capabilities' => $this->capabilityBadges($settings, $features),
        ];
    }

    /**
     * Conditions an operator needs to act on, stated plainly. Each one
     * describes a configuration that looks fine field-by-field but does
     * nothing (or spends wrongly) in practice.
     *
     * @return list<string>
     */
    private function warnings(AiSettings $settings, FeatureSettings $features): array
    {
        $warnings = [];

        if ($features->ai_enabled && $settings->provider === 'openai' && ! $this->hasStoredKey()) {
            $warnings[] = 'OpenAI is selected with no stored API key — every AI request will be refused.';
        }

        if ($features->ai_enabled && $this->enabledCapabilityLabels($settings) === []) {
            $warnings[] = 'The platform is enabled but no capability is switched on, so nothing will run.';
        }

        if (! $features->ai_enabled && $this->enabledCapabilityLabels($settings) !== []) {
            $warnings[] = 'Capabilities are switched on but the master switch is off — they are inert.';
        }

        if ($settings->model_pricing === []) {
            $warnings[] = 'No model prices are configured, so all spend estimates as zero and the cost ceilings can never be reached.';
        } else {
            $unpriced = $this->unpricedModels($settings);

            if ($unpriced !== []) {
                $warnings[] = 'No price configured for '.implode(', ', $unpriced).' — runs on those models estimate as free.';
            }
        }

        if ($settings->daily_cost_limit === null && $settings->monthly_cost_limit === null) {
            $warnings[] = 'No spend ceiling is set. AI cost is unbounded.';
        }

        return $warnings;
    }

    /**
     * Models selected for a role but absent from the price table — the
     * quiet failure that makes spend look free.
     *
     * @return list<string>
     */
    private function unpricedModels(AiSettings $settings): array
    {
        $estimator = app(AiCostEstimator::class);

        $models = array_unique(array_filter([
            $settings->generation_model,
            $settings->fast_model,
            $settings->moderation_model,
            $settings->embedding_model,
        ]));

        return array_values(array_filter($models, fn (string $model): bool => ! $estimator->isPriced($model)));
    }

    /** @return array<string, mixed> */
    private function budgetCard(string $label, float $spent, ?float $limit, AiSettings $settings): array
    {
        $ratio = $limit === null || $limit <= 0.0 ? null : $spent / $limit;
        $threshold = $settings->budget_alert_threshold ?? 0.8;

        return [
            'label' => $label,
            'spent' => number_format($spent, 4),
            'limit' => $limit === null ? null : number_format($limit, 2),
            'ratio' => $ratio,
            'barClass' => match (true) {
                $ratio === null => 'bg-gray-400',
                $ratio >= 1.0 => 'bg-danger-500',
                $ratio >= $threshold => 'bg-warning-500',
                default => 'bg-success-500',
            },
            'textClass' => match (true) {
                $ratio === null => 'text-gray-500 dark:text-gray-400',
                $ratio >= 1.0 => 'text-danger-600 dark:text-danger-400',
                $ratio >= $threshold => 'text-warning-600 dark:text-warning-400',
                default => 'text-gray-500 dark:text-gray-400',
            },
        ];
    }

    /** @return array<string, string> */
    private function healthCard(AiSettings $settings): array
    {
        $when = filled($settings->last_health_check_at)
            ? (ViewerDateTime::dateTime($settings->last_health_check_at) ?? 'never tested')
            : 'never tested';

        return match ($settings->last_health_status) {
            'healthy' => ['label' => 'Reachable', 'class' => 'text-success-600 dark:text-success-400', 'when' => $when],
            'unhealthy' => ['label' => 'Failed', 'class' => 'text-danger-600 dark:text-danger-400', 'when' => $when],
            default => ['label' => 'Not tested', 'class' => 'text-gray-500 dark:text-gray-400', 'when' => $when],
        };
    }

    /**
     * Capability badges, greyed out when the master switch makes them
     * inert — an operator should never have to cross-reference two
     * sections to work out whether a feature can actually run.
     *
     * @return list<array{label: string, color: string}>
     */
    private function capabilityBadges(AiSettings $settings, FeatureSettings $features): array
    {
        $badges = [];

        foreach ($this->enabledCapabilityLabels($settings) as $label) {
            $badges[] = [
                'label' => $features->ai_enabled ? $label : $label.' (inert)',
                'color' => $features->ai_enabled ? 'success' : 'gray',
            ];
        }

        return $badges;
    }

    /** @return list<string> */
    private function enabledCapabilityLabels(AiSettings $settings): array
    {
        $labels = [];

        foreach (AiFeature::cases() as $feature) {
            $flag = $feature->settingsFlag();

            if ($flag !== null && (bool) $settings->{$flag}) {
                $labels[] = $feature->label();
            }
        }

        return $labels;
    }

    /** Whether a credential is already stored — never exposes the value itself. */
    private function hasStoredKey(): bool
    {
        return app(AiCredentialStore::class)->hasOpenAiApiKey();
    }

    private function anyCapabilityOn(Get $get): bool
    {
        foreach (['quality_insights_enabled', 'homework_assistant_enabled', 'lesson_summary_enabled', 'communication_moderation_enabled'] as $flag) {
            if ((bool) $get($flag)) {
                return true;
            }
        }

        return false;
    }

    private function nullableFloat(mixed $value): ?float
    {
        return $value === null || $value === '' ? null : (float) $value;
    }

    /**
     * Stored exactly as edited — `model => "input/output"` — with blank
     * model names dropped and whitespace trimmed. No structural
     * conversion happens here on purpose: the admin field, the stored
     * setting and AiCostEstimator all speak the same one format.
     *
     * @param  array<string, string>  $pricing
     * @return array<string, string>
     */
    private function normalizePricing(array $pricing): array
    {
        $clean = [];

        foreach ($pricing as $model => $value) {
            if (blank($model)) {
                continue;
            }

            $clean[trim((string) $model)] = trim((string) $value);
        }

        return $clean;
    }
}
