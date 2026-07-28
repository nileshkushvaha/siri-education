<?php

declare(strict_types=1);

namespace App\Filament\Resources\SuspiciousActivityFlags\Schemas;

use App\Compliance\Enums\SuspiciousActivityCategory;
use App\Compliance\Enums\SuspiciousActivityFlagDecision;
use App\Compliance\Enums\SuspiciousActivityFlagSeverity;
use App\Compliance\Enums\SuspiciousActivityFlagStatus;
use App\Compliance\Enums\SuspiciousActivityRuleCode;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SuspiciousActivityFlagInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Compliance flag')
                ->description("This flag is generated automatically by the compliance monitoring system and can't be edited here. It's evidence for human review, not proof of fraud.")
                ->schema([
                    Grid::make(3)->schema([
                        TextEntry::make('reference')->fontFamily('mono')->copyable(),
                        TextEntry::make('rule_code')
                            ->label('Rule')
                            ->formatStateUsing(fn (SuspiciousActivityRuleCode $state): string => $state->label()),
                        TextEntry::make('category')
                            ->badge()
                            ->formatStateUsing(fn (SuspiciousActivityCategory $state): string => $state->label()),
                        TextEntry::make('severity')
                            ->badge()
                            ->formatStateUsing(fn (SuspiciousActivityFlagSeverity $state): string => $state->label())
                            ->color(fn (SuspiciousActivityFlagSeverity $state): string => $state->color()),
                        TextEntry::make('status')
                            ->badge()
                            ->formatStateUsing(fn (SuspiciousActivityFlagStatus $state): string => $state->label())
                            ->color(fn (SuspiciousActivityFlagStatus $state): string => $state->color()),
                        TextEntry::make('occurrence_count')->label('Occurrences'),
                        TextEntry::make('subject.name')->label('Subject'),
                        TextEntry::make('actor.name')->label('Actor')->placeholder('—'),
                    ]),
                ]),

            Section::make('Timeline')
                ->schema([
                    Grid::make(2)->schema([
                        TextEntry::make('first_observed_at')->dateTime(),
                        TextEntry::make('last_observed_at')->dateTime(),
                    ]),
                ]),

            Section::make('Evidence')
                ->description('Safe, masked, aggregate data only — never raw payment, KYC, or narrative content.')
                ->schema([
                    KeyValueEntry::make('evidence'),
                ]),

            Section::make('Review')
                ->schema([
                    Grid::make(2)->schema([
                        TextEntry::make('reviewer.name')->label('Reviewer')->placeholder('—'),
                        TextEntry::make('decision')
                            ->formatStateUsing(fn (?SuspiciousActivityFlagDecision $state): string => $state?->label() ?? '—'),
                        TextEntry::make('decision_reason')->label('Reason')->placeholder('—')->columnSpanFull(),
                        TextEntry::make('reviewed_at')->dateTime()->placeholder('—'),
                    ]),
                ]),
        ]);
    }
}
