<?php

declare(strict_types=1);

namespace App\Filament\Resources\AiQualityInsights\Pages;

use App\Ai\Evaluation\Contracts\AiFeedbackRecorderInterface;
use App\Ai\Evaluation\Enums\AiFeedbackAction;
use App\Ai\Evaluation\Enums\AiFeedbackReason;
use App\Filament\Resources\AiQualityInsights\AiQualityInsightResource;
use App\Models\AiQualityInsight;
use App\Quality\Intelligence\Contracts\QualityInsightServiceInterface;
use App\Quality\Intelligence\Enums\QualityInsightStatus;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Utilities\Get;

class ViewAiQualityInsight extends ViewRecord
{
    protected static string $resource = AiQualityInsightResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('markReviewed')
                ->label('Mark reviewed')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn (AiQualityInsight $record): bool => $record->status === QualityInsightStatus::Ready
                    && (auth()->user()?->can('review', $record) ?? false))
                ->modalHeading('Mark this insight reviewed')
                ->modalDescription('This records that you have read the briefing and that any action taken from here is your decision, not the model\'s.')
                ->schema([
                    // AI-E0 evaluation signal. "Reviewed" records that
                    // someone looked; it says nothing about whether the
                    // briefing was worth their time, which is the one
                    // thing that predicts whether this feature should
                    // survive. Optional — a reviewer who skips it still
                    // completes their review.
                    ToggleButtons::make('was_helpful')
                        ->label('Was this briefing useful?')
                        ->helperText('Optional. Used only to measure and improve the prompt — never shown to instructors.')
                        ->inline()
                        ->options([
                            AiFeedbackAction::Helpful->value => 'Yes, useful',
                            AiFeedbackAction::NotHelpful->value => 'Not useful',
                        ])
                        ->colors([
                            AiFeedbackAction::Helpful->value => 'success',
                            AiFeedbackAction::NotHelpful->value => 'danger',
                        ]),
                    Select::make('not_helpful_reason')
                        ->label('What was wrong with it?')
                        ->options(AiFeedbackReason::options())
                        ->native(false)
                        ->visible(fn (Get $get): bool => $get('was_helpful') === AiFeedbackAction::NotHelpful->value),
                    Textarea::make('note')
                        ->label('Note (optional)')
                        ->helperText('What you checked, or what you decided. Visible to other administrators.')
                        ->maxLength(2000)
                        ->rows(3),
                ])
                ->action(function (array $data, AiQualityInsight $record): void {
                    abort_unless(auth()->user()?->can('review', $record) ?? false, 403);

                    app(QualityInsightServiceInterface::class)->markReviewed(
                        $record,
                        auth()->user(),
                        filled($data['note'] ?? null) ? (string) $data['note'] : null,
                    );

                    // Recorded against the AI RUN, not the insight, so
                    // the verdict can be compared across prompt versions
                    // and carries no reference to the instructor it was
                    // about.
                    if (filled($data['was_helpful'] ?? null)) {
                        app(AiFeedbackRecorderInterface::class)->record(
                            aiRunId: $record->ai_run_id,
                            action: AiFeedbackAction::from((string) $data['was_helpful']),
                            reason: filled($data['not_helpful_reason'] ?? null)
                                ? AiFeedbackReason::from((string) $data['not_helpful_reason'])
                                : null,
                            actorId: (int) auth()->id(),
                        );
                    }

                    Notification::make()->title('Marked reviewed')->success()->send();

                    $this->refreshFormData(['status', 'reviewed_at', 'review_note']);
                }),
            Action::make('back')
                ->label('Back to AI Quality Insights')
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(AiQualityInsightResource::getUrl('index')),
        ];
    }
}
