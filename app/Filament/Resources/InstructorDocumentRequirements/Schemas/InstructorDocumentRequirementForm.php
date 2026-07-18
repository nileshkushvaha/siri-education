<?php

declare(strict_types=1);

namespace App\Filament\Resources\InstructorDocumentRequirements\Schemas;

use App\Enums\InstructorEvidenceCollection;
use App\Models\InstructorDocumentRequirement;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class InstructorDocumentRequirementForm
{
    private const array COMMON_MIME_TYPES = [
        'image/jpeg', 'image/png', 'image/webp', 'application/pdf',
        'video/mp4', 'video/webm', 'video/quicktime',
    ];

    public static function configure(Schema $schema): Schema
    {
        $isCreate = $schema->getLivewire() instanceof CreateRecord;

        return $schema
            ->components([
                Section::make('Requirement')
                    ->columnSpanFull()
                    ->schema([
                        Grid::make(2)->schema([
                            Select::make('collection_name')
                                ->label('Media Collection')
                                ->options(InstructorEvidenceCollection::options())
                                ->native(false)
                                ->searchable()
                                ->preload()
                                ->live()
                                ->required()
                                ->placeholder('Select the evidence instructors must upload')
                                ->helperText('Choose the matching collection from Verification Evidence. Collections already configured must be edited or reactivated instead of added again.')
                                ->unique('instructor_document_requirements', 'collection_name', ignoreRecord: true)
                                ->disableOptionWhen(fn (string $value): bool => $isCreate
                                    && InstructorDocumentRequirement::query()->where('collection_name', $value)->exists())
                                ->afterStateUpdated(function (Set $set, ?string $state): void {
                                    if ($collection = InstructorEvidenceCollection::tryFrom($state ?? '')) {
                                        $set('label', $collection->label());
                                    }
                                })
                                ->disabled(! $isCreate)
                                ->dehydrated(),
                            TextInput::make('label')
                                ->required()
                                ->maxLength(150)
                                ->placeholder('e.g. Government ID'),
                        ]),

                        Textarea::make('description')
                            ->rows(2)
                            ->maxLength(1000)
                            ->columnSpanFull(),

                        TagsInput::make('accepted_mime_types')
                            ->label('Accepted File Types')
                            ->required()
                            ->suggestions(self::COMMON_MIME_TYPES)
                            ->placeholder('e.g. application/pdf')
                            ->columnSpanFull(),

                        Grid::make(3)->schema([
                            Toggle::make('required')
                                ->label('Required')
                                ->default(true)
                                ->helperText('Off = optional document.'),
                            Toggle::make('active')
                                ->default(true)
                                ->helperText('Off = hidden from new applicants.'),
                            TextInput::make('max_size_kb')
                                ->label('Max Size (KB)')
                                ->numeric()
                                ->required()
                                ->minValue(1)
                                ->default(4096),
                        ]),

                        TextInput::make('sort_order')
                            ->numeric()
                            ->default(0)
                            ->minValue(0),
                    ]),
            ]);
    }
}
