<?php

declare(strict_types=1);

namespace App\Filament\Resources\AiQualityInsights\Tables;

use App\Filament\Resources\AiQualityInsights\AiQualityInsightResource;
use App\Filament\Support\Tables\AdminListTable;
use App\Quality\Intelligence\Enums\QualityInsightStatus;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Deliberately NOT sortable by confidence. Confidence is the model's
 * own stated certainty about its own output; a column an admin could
 * rank instructors by would turn an advisory briefing into a league
 * table, which is exactly what this feature must not become. It is
 * shown on the row for context and nothing more.
 */
class AiQualityInsightsTable
{
    public static function configure(Table $table): Table
    {
        $table
            ->columns([
                TextColumn::make('instructor.full_name')
                    ->label('Instructor')
                    ->searchable(['first_name', 'last_name'])
                    ->sortable(),
                TextColumn::make('period_label')
                    ->label('Period')
                    ->wrap(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (QualityInsightStatus $state): string => $state->label())
                    ->color(fn (QualityInsightStatus $state): string => $state->color()),
                TextColumn::make('confidence')
                    ->label('AI confidence')
                    ->formatStateUsing(fn (?float $state): string => $state === null ? '—' : round($state * 100).'%')
                    ->tooltip('The model\'s own stated certainty. Never a quality score, and never a basis for comparing instructors.'),
                IconColumn::make('requires_human_review')
                    ->label('Needs review')
                    ->boolean(),
                TextColumn::make('created_at')
                    ->label('Generated')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('reviewedBy.full_name')
                    ->label('Reviewed by')
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options(fn (): array => collect(QualityInsightStatus::cases())
                        ->mapWithKeys(fn (QualityInsightStatus $s): array => [$s->value => $s->label()])
                        ->all()),
            ])
            ->recordUrl(fn ($record): string => AiQualityInsightResource::getUrl('view', ['record' => $record]))
            ->emptyStateHeading('No AI quality insights yet')
            ->emptyStateDescription('Generate one for an instructor and reporting period. Insights are advisory and always reviewed by a person.');

        return AdminListTable::apply($table);
    }
}
