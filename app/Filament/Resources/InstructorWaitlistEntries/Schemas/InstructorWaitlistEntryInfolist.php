<?php

declare(strict_types=1);

namespace App\Filament\Resources\InstructorWaitlistEntries\Schemas;

use App\Enums\WaitlistEntryStatus;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class InstructorWaitlistEntryInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Waitlist entry')
                ->description('Read-only — written only by WaitlistService, never edited here.')
                ->schema([
                    Grid::make(3)->schema([
                        TextEntry::make('instructor.name')
                            ->label('Instructor'),
                        TextEntry::make('student.name')
                            ->label('Student'),
                        TextEntry::make('status')
                            ->badge()
                            ->formatStateUsing(fn (WaitlistEntryStatus $state): string => $state->label())
                            ->color(fn (WaitlistEntryStatus $state): string => $state->color()),
                    ]),
                ]),

            Section::make('Timeline')
                ->schema([
                    Grid::make(2)->schema([
                        TextEntry::make('joined_at')->dateTime(),
                        TextEntry::make('notified_at')->dateTime()->placeholder('Never'),
                        TextEntry::make('fulfilled_at')->dateTime()->placeholder('—'),
                        TextEntry::make('withdrawn_at')->dateTime()->placeholder('—'),
                        TextEntry::make('ineligible_at')->dateTime()->placeholder('—'),
                    ]),
                ]),
        ]);
    }
}
