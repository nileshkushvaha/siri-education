<?php

declare(strict_types=1);

namespace App\Filament\Pages\Settings;

use App\Ai\Contracts\AiProviderRegistryInterface;
use App\Ai\Contracts\AiRunRepositoryInterface;
use App\Ai\DTOs\AiProviderHealth;
use App\Ai\Enums\AiFeature;
use App\Ai\Services\AiBudgetGuard;
use App\Ai\Services\AiCredentialStore;
use App\Ai\Services\AiProviderResolver;
use App\Filament\Navigation\Concerns\HasCentralizedNavigation;
use App\Filament\Navigation\Concerns\HasSettingsSectionBreadcrumb;
use App\Settings\AiSettings;
use App\Settings\FeatureSettings;
use App\Support\Timezone\ViewerDateTime;
use BackedEnum;
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
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
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
            Grid::make(2)->schema([
                Section::make('Status')
                    ->columnSpanFull()
                    ->schema([
                        Placeholder::make('status_overview')
                            ->hiddenLabel()
                            ->content(fn (): HtmlString => $this->statusOverview()),
                        ActionsComponent::make([
                            Action::make('check_health')
                                ->label('Test connection')
                                ->color('gray')
                                ->modalDescription('Verifies the stored credential against the active provider. Sends no prompt and no platform content.')
                                ->requiresConfirmation()
                                ->visible(fn (): bool => auth()->user()?->can('TestConnection:AiPlatform') ?? false)
                                ->action('checkHealth'),
                        ])->key('status-actions'),
                    ]),

                Section::make('Provider & Credentials')
                    ->description('Secrets are never redisplayed. Leave the API key blank to keep the current stored value.')
                    ->columnSpanFull()
                    ->schema([
                        Grid::make(2)->schema([
                            Toggle::make('ai_enabled')
                                ->label('AI platform enabled')
                                ->helperText('Master switch. Off means no AI provider call can be made, whatever the capability flags below say.'),
                            Select::make('provider')
                                ->label('Primary provider')
                                ->options(fn (): array => app(AiProviderRegistryInterface::class)->options())
                                ->required()
                                ->native(false)
                                ->helperText('"Fake" performs no external call — safe for staging.'),
                        ]),
                        Grid::make(2)->schema([
                            TextInput::make('openai_api_key')
                                ->label('OpenAI API key')
                                ->password()
                                ->revealable()
                                ->helperText('Encrypted at rest. Never shown again after saving.'),
                            TextInput::make('openai_organization')
                                ->label('OpenAI organization (optional)')
                                ->maxLength(191),
                        ]),
                    ]),

                Section::make('Models')
                    ->description('Business code asks for a model ROLE, never a model name — changing a model here changes it everywhere.')
                    ->columnSpanFull()
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('generation_model')->label('Generation model')->required()->maxLength(64),
                            TextInput::make('fast_model')->label('Fast model')->required()->maxLength(64),
                            TextInput::make('embedding_model')->label('Embedding model')->required()->maxLength(64),
                            TextInput::make('moderation_model')->label('Moderation model')->required()->maxLength(64),
                        ]),
                        TextInput::make('request_timeout_seconds')
                            ->label('Request timeout (seconds)')
                            ->numeric()
                            ->required()
                            ->minValue(1)
                            ->maxValue(120),
                    ]),

                Section::make('Capability Flags')
                    ->description('Each future AI capability, off until its phase ships. Turning one on has no effect until that phase registers its prompt.')
                    ->columnSpanFull()
                    ->schema([
                        Grid::make(2)->schema([
                            Toggle::make('quality_insights_enabled')
                                ->label('Quality insights (P1)')
                                ->helperText('Admin quality intelligence. Not implemented yet.'),
                            Toggle::make('homework_assistant_enabled')
                                ->label('Homework assistant (P2)')
                                ->helperText('Instructor homework copilot. Not implemented yet.'),
                            Toggle::make('lesson_summary_enabled')
                                ->label('Lesson summaries (P3)')
                                ->helperText('Lesson summary generation. Not implemented yet.'),
                            Toggle::make('communication_moderation_enabled')
                                ->label('Communication moderation (P4)')
                                ->helperText('Message safety classification. Not implemented yet.'),
                        ]),
                    ]),

                Section::make('Cost Controls')
                    ->description('Ceilings apply to ESTIMATED spend across all AI features. Leave a limit blank for no ceiling; set 0 to stop all AI spend immediately.')
                    ->columnSpanFull()
                    ->schema([
                        Grid::make(3)->schema([
                            TextInput::make('daily_cost_limit')->label('Daily limit')->numeric()->minValue(0),
                            TextInput::make('monthly_cost_limit')->label('Monthly limit')->numeric()->minValue(0),
                            TextInput::make('cost_currency')->label('Cost currency')->required()->maxLength(3),
                        ]),
                        KeyValue::make('model_pricing')
                            ->label('Model pricing (per 1M tokens)')
                            ->keyLabel('Model')
                            ->valueLabel('input/output')
                            ->helperText('Used for cost estimation only, never billing. Format: "2.0/8.0" — input price, then output price. An unlisted model estimates as zero.')
                            ->addActionLabel('Add model price'),
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

        Notification::make()
            ->title($health->healthy ? 'AI provider reachable' : 'AI provider check failed')
            ->body($health->safeMessage)
            ->status($health->healthy ? 'success' : 'danger')
            ->send();
    }

    private function statusOverview(): HtmlString
    {
        $settings = app(AiSettings::class);
        $features = app(FeatureSettings::class);
        $budget = app(AiBudgetGuard::class);
        $runs = app(AiRunRepositoryInterface::class);
        $currency = $settings->cost_currency;

        $checkedAt = filled($settings->last_health_check_at)
            ? (ViewerDateTime::dateTime($settings->last_health_check_at) ?? 'never')
            : 'never';

        $rows = [
            'Module' => $features->ai_enabled ? 'Enabled' : 'Disabled',
            'Provider' => $settings->provider,
            'Credential' => app(AiCredentialStore::class)->hasOpenAiApiKey() ? 'Configured' : 'Not configured',
            'Last connection test' => sprintf('%s (%s)', $settings->last_health_status, $checkedAt),
            'Spend today' => sprintf('%s %s of %s', number_format($budget->spentToday(), 4), $currency, $settings->daily_cost_limit === null ? 'no limit' : number_format($settings->daily_cost_limit, 2)),
            'Spend this month' => sprintf('%s %s of %s', number_format($budget->spentThisMonth(), 4), $currency, $settings->monthly_cost_limit === null ? 'no limit' : number_format($settings->monthly_cost_limit, 2)),
            'Runs today' => (string) $runs->countSince(Carbon::now()->startOfDay()),
        ];

        $html = '<dl class="text-sm space-y-1">';

        foreach ($rows as $label => $value) {
            $html .= sprintf('<div><span class="font-medium">%s:</span> %s</div>', e($label), e($value));
        }

        $enabledFeatures = array_values(array_filter(
            AiFeature::cases(),
            fn (AiFeature $feature): bool => $feature->settingsFlag() !== null && (bool) $settings->{$feature->settingsFlag()},
        ));

        $html .= sprintf(
            '<div><span class="font-medium">Capabilities on:</span> %s</div></dl>',
            e($enabledFeatures === [] ? 'none' : implode(', ', array_map(fn (AiFeature $f): string => $f->label(), $enabledFeatures))),
        );

        return new HtmlString($html);
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
