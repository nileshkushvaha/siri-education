<?php

declare(strict_types=1);

namespace App\Filament\Resources\OperationalAlerts\Schemas;

use App\Alerts\Enums\OperationalAlertCategory;
use App\Alerts\Enums\OperationalAlertSeverity;
use App\Alerts\Enums\OperationalAlertStatus;
use App\Alerts\Enums\OperationalAlertType;
use App\Models\OperationalAlert;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class OperationalAlertInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Operational alert')
                ->description('Read-only — written only by OperationalAlertService.')
                ->schema([
                    Grid::make(3)->schema([
                        TextEntry::make('reference')->fontFamily('mono')->copyable(),
                        TextEntry::make('type')
                            ->formatStateUsing(fn (OperationalAlertType $state): string => $state->label()),
                        TextEntry::make('category')
                            ->badge()
                            ->formatStateUsing(fn (OperationalAlertCategory $state): string => $state->label()),
                        TextEntry::make('severity')
                            ->badge()
                            ->formatStateUsing(fn (OperationalAlertSeverity $state): string => $state->label())
                            ->color(fn (OperationalAlertSeverity $state): string => $state->color()),
                        TextEntry::make('status')
                            ->badge()
                            ->formatStateUsing(fn (OperationalAlertStatus $state): string => $state->label())
                            ->color(fn (OperationalAlertStatus $state): string => $state->color()),
                        TextEntry::make('occurrence_count')->label('Occurrences'),
                    ]),
                    TextEntry::make('title')->columnSpanFull(),
                    TextEntry::make('summary')->columnSpanFull(),
                    TextEntry::make('subjectUrl')
                        ->label('Related record')
                        ->state(fn (OperationalAlert $record): string => $record->subjectUrl() ?? '—')
                        ->url(fn (OperationalAlert $record): ?string => $record->subjectUrl())
                        ->visible(fn (OperationalAlert $record): bool => $record->subjectUrl() !== null),
                ]),

            Section::make('Timeline')
                ->schema([
                    Grid::make(2)->schema([
                        TextEntry::make('first_observed_at')->dateTime(),
                        TextEntry::make('last_observed_at')->dateTime(),
                    ]),
                ]),

            Section::make('Safe metadata')
                ->description('Safe summary/reference data only — never credentials, provider payloads, bank details, or private user content.')
                ->schema([
                    KeyValueEntry::make('metadata'),
                ]),

            Section::make('Lifecycle')
                ->schema([
                    Grid::make(2)->schema([
                        TextEntry::make('acknowledgedByUser.name')->label('Acknowledged by')->placeholder('—'),
                        TextEntry::make('acknowledged_at')->dateTime()->placeholder('—'),
                        TextEntry::make('resolvedByUser.name')->label('Resolved by')->placeholder('—'),
                        TextEntry::make('resolved_at')->dateTime()->placeholder('—'),
                        TextEntry::make('resolution_reason')->label('Resolution reason')->placeholder('—')->columnSpanFull(),
                    ]),
                ]),
        ]);
    }
}
