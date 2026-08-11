<?php

declare(strict_types=1);

namespace App\Filament\Resources\Academic\Schemas;

use App\Curriculum\Enums\CurriculumVersionStatus;
use App\Models\CurriculumVersion;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Read-only identity/status display + notes. Every other field
 * (status, published/archived/retired timestamps) is lifecycle state
 * owned exclusively by CurriculumService — never directly editable
 * here, matching StudentLearningPlanResource's pattern of disabling
 * lifecycle-relevant fields and routing changes through header
 * actions instead.
 */
class CurriculumVersionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Version')
                ->columnSpanFull()
                ->schema([
                    Grid::make(3)->schema([
                        Placeholder::make('curriculum_name')
                            ->label('Curriculum')
                            ->content(fn (?CurriculumVersion $record): string => $record?->curriculum?->name ?? '—'),
                        Placeholder::make('version_number')
                            ->label('Version')
                            ->content(fn (?CurriculumVersion $record): string => $record !== null ? "v{$record->version_number}" : '—'),
                        Placeholder::make('status')
                            ->label('Status')
                            ->content(fn (?CurriculumVersion $record): string => $record?->status instanceof CurriculumVersionStatus ? $record->status->label() : '—'),
                    ]),
                    Textarea::make('notes')
                        ->rows(3)
                        ->maxLength(2000)
                        ->columnSpanFull()
                        ->placeholder('Internal notes about this version…')
                        ->disabled(fn (?CurriculumVersion $record): bool => $record !== null && ! $record->status->isEditable())
                        ->dehydrated(fn (?CurriculumVersion $record): bool => $record !== null && $record->status->isEditable())
                        ->helperText('Only editable while this version is a Draft.'),
                ]),
        ]);
    }
}
