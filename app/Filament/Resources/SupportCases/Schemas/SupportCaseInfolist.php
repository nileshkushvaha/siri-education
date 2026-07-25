<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupportCases\Schemas;

use App\Models\SupportCase;
use App\SupportCases\Enums\SupportCasePriority;
use App\SupportCases\Enums\SupportCaseStatus;
use App\SupportCases\Enums\SupportCaseType;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SupportCaseInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Case')
                ->schema([
                    Grid::make(3)->schema([
                        TextEntry::make('case_number')
                            ->label('Reference')
                            ->copyable(),
                        TextEntry::make('type')
                            ->formatStateUsing(fn (SupportCaseType $state): string => $state->label()),
                        TextEntry::make('category')
                            ->formatStateUsing(fn ($state): string => $state->label()),
                    ]),
                    Grid::make(3)->schema([
                        TextEntry::make('priority')
                            ->badge()
                            ->formatStateUsing(fn (SupportCasePriority $state): string => $state->label())
                            ->color(fn (SupportCasePriority $state): string => $state->color()),
                        TextEntry::make('status')
                            ->badge()
                            ->formatStateUsing(fn (SupportCaseStatus $state): string => $state->label())
                            ->color(fn (SupportCaseStatus $state): string => $state->color()),
                        TextEntry::make('assignee.name')
                            ->label('Assigned to')
                            ->placeholder('Unassigned'),
                    ]),
                    TextEntry::make('subject'),
                    TextEntry::make('description')->columnSpanFull(),
                ]),

            Section::make('Requester')
                ->schema([
                    Grid::make(3)->schema([
                        TextEntry::make('creator.name')->label('Created by'),
                        TextEntry::make('student.name')->label('Student')->placeholder('—'),
                        TextEntry::make('instructor.name')->label('Instructor')->placeholder('—'),
                    ]),
                ]),

            Section::make('Linked record')
                ->schema([
                    Grid::make(2)->schema([
                        TextEntry::make('linked_record_type')
                            ->label('Type')
                            ->formatStateUsing(fn (?string $state): string => $state !== null ? class_basename($state) : '—')
                            ->placeholder('—'),
                        TextEntry::make('linked_record_id')
                            ->label('ID')
                            ->placeholder('—'),
                    ]),
                ])
                ->visible(fn (SupportCase $record): bool => $record->linked_record_type !== null),

            Section::make('Resolution')
                ->schema([
                    Grid::make(2)->schema([
                        TextEntry::make('resolution_type')
                            ->formatStateUsing(fn ($state): string => $state?->label() ?? '—'),
                        TextEntry::make('resolution_summary')
                            ->placeholder('—'),
                    ]),
                ])
                ->visible(fn (SupportCase $record): bool => $record->resolution_summary !== null),

            Section::make('Timeline')
                ->schema([
                    Grid::make(4)->schema([
                        TextEntry::make('opened_at')->dateTime(),
                        TextEntry::make('assigned_at')->dateTime()->placeholder('—'),
                        TextEntry::make('resolved_at')->dateTime()->placeholder('—'),
                        TextEntry::make('closed_at')->dateTime()->placeholder('—'),
                    ]),
                ]),
        ]);
    }
}
