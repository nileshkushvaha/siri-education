<?php

declare(strict_types=1);

namespace App\Filament\Resources\AiQualityInsights\Pages;

use App\Filament\Resources\AiQualityInsights\AiQualityInsightResource;
use App\Models\AiQualityInsight;
use App\Models\User;
use App\Quality\Intelligence\Contracts\QualityInsightServiceInterface;
use App\Quality\Intelligence\Exceptions\QualityInsightException;
use App\Reporting\Enums\ReportingPeriodPreset;
use App\Reporting\ValueObjects\ReportingPeriod;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;
use Throwable;

/**
 * The generate surface. Everything it does is queue a run — no provider
 * call happens in this request, so the page cannot hang or fail because
 * OpenAI is slow or down.
 *
 * Errors are always shown as a notification with an actionable
 * sentence ("AI is turned off", "the budget is spent"); the page itself
 * never breaks when AI is unavailable, which is the whole point of
 * treating an unavailable model as a normal condition rather than an
 * exception.
 */
class ListAiQualityInsights extends ListRecords
{
    protected static string $resource = AiQualityInsightResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        return 'AI-generated, advisory briefings on instructor quality signals. Nothing here changes an instructor\'s status, pay, or ranking — a person decides what happens next.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('generate')
                ->label('Generate insight')
                ->icon('heroicon-o-sparkles')
                ->visible(fn (): bool => auth()->user()?->can('generate', AiQualityInsight::class) ?? false)
                ->modalHeading('Generate an AI quality insight')
                ->modalDescription('Anonymized statistics and review excerpts for this instructor and period are sent to the configured AI provider. Student names and contact details are never included. The result is advisory and must be reviewed by a person.')
                ->modalSubmitActionLabel('Generate')
                ->schema([
                    Select::make('instructor_id')
                        ->label('Instructor')
                        ->required()
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search): array => User::query()
                            ->role('instructor')
                            ->where(fn ($query) => $query
                                ->where('first_name', 'like', "%{$search}%")
                                ->orWhere('last_name', 'like', "%{$search}%")
                                ->orWhere('name', 'like', "%{$search}%"))
                            ->limit(20)
                            ->get()
                            ->mapWithKeys(fn (User $user): array => [$user->id => $user->full_name])
                            ->all())
                        ->getOptionLabelUsing(fn ($value): ?string => User::query()->find($value)?->full_name),
                    Select::make('period')
                        ->label('Reporting period')
                        ->required()
                        ->default(ReportingPeriodPreset::Last30Days->value)
                        ->native(false)
                        // Presets only: they reuse the platform's
                        // canonical ReportingPeriod definitions, so an
                        // insight always covers exactly the same window
                        // the Instructor Performance report would show
                        // for that period — an arbitrary range would
                        // silently drift from the reports an admin
                        // checks the insight against.
                        ->options([
                            ReportingPeriodPreset::Last7Days->value => ReportingPeriodPreset::Last7Days->label(),
                            ReportingPeriodPreset::Last30Days->value => ReportingPeriodPreset::Last30Days->label(),
                            ReportingPeriodPreset::ThisMonth->value => ReportingPeriodPreset::ThisMonth->label(),
                            ReportingPeriodPreset::PreviousMonth->value => ReportingPeriodPreset::PreviousMonth->label(),
                        ]),
                ])
                ->action(function (array $data): void {
                    $this->generate((int) $data['instructor_id'], (string) $data['period']);
                }),
        ];
    }

    private function generate(int $instructorId, string $preset): void
    {
        $actor = auth()->user();

        abort_unless($actor?->can('generate', AiQualityInsight::class) ?? false, 403);

        $instructor = User::query()->find($instructorId);

        if ($instructor === null) {
            Notification::make()->title('Instructor not found')->danger()->send();

            return;
        }

        try {
            app(QualityInsightServiceInterface::class)->request(
                $instructor,
                ReportingPeriod::forPreset(ReportingPeriodPreset::from($preset)),
                $actor,
            );
        } catch (QualityInsightException $e) {
            Notification::make()->title('Insight not generated')->body($e->getMessage())->warning()->send();

            return;
        } catch (Throwable) {
            // Never surface a raw exception message on an admin screen —
            // it can carry query fragments or provider text.
            Notification::make()->title('Insight not generated')->body('Something went wrong starting this insight. Please try again.')->danger()->send();

            return;
        }

        Notification::make()
            ->title('Generating insight')
            ->body('It will appear here shortly. Refresh the page to see the result.')
            ->success()
            ->send();
    }
}
