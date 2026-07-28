<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\RelationManagers;

use App\Enums\EmploymentType;
use App\Models\Country;
use App\Models\State;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

/**
 * Full CRUD, unlike ActivityLogRelationManager/LoginHistoryRelationManager
 * (read-only) — authorization is delegated to UserExperiencePolicy, which
 * Filament resolves automatically for the UserExperience model.
 */
class ExperiencesRelationManager extends RelationManager
{
    protected static string $relationship = 'experiences';

    protected static ?string $title = 'Experience';

    protected static string|BackedEnum|null $icon = Heroicon::OutlinedBriefcase;

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(2)->schema([
                TextInput::make('organization_name')
                    ->label('Organization')
                    ->required()
                    ->maxLength(255),

                TextInput::make('designation')
                    ->label('Designation')
                    ->required()
                    ->maxLength(255),
            ]),

            Grid::make(2)->schema([
                Select::make('employment_type')
                    ->label('Employment Type')
                    ->options(EmploymentType::class)
                    ->native(false),

                TextInput::make('industry')
                    ->maxLength(255),
            ]),

            Grid::make(3)->schema([
                Select::make('country_id')
                    ->label('Country')
                    ->options(fn () => Country::query()->active()->orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->native(false)
                    ->live()
                    ->afterStateUpdated(fn ($set) => $set('state_id', null)),

                Select::make('state_id')
                    ->label('State')
                    ->options(function ($get) {
                        $countryId = $get('country_id');

                        if (! $countryId) {
                            return [];
                        }

                        return State::query()->active()->where('country_id', $countryId)->orderBy('name')->pluck('name', 'id');
                    })
                    ->searchable()
                    ->native(false),

                TextInput::make('city')
                    ->maxLength(100),
            ]),

            TextInput::make('location')
                ->maxLength(255)
                ->columnSpanFull(),

            Textarea::make('description')
                ->rows(3)
                ->columnSpanFull(),

            TextInput::make('website')
                ->url()
                ->maxLength(255),

            Toggle::make('is_current')
                ->label('I currently work here')
                ->live()
                ->default(false),

            Grid::make(2)->schema([
                DatePicker::make('start_date')
                    ->required()
                    ->native(false),

                DatePicker::make('end_date')
                    ->native(false)
                    ->disabled(fn ($get): bool => (bool) $get('is_current'))
                    ->dehydrated(fn ($get): bool => ! $get('is_current'))
                    ->afterOrEqual('start_date'),
            ]),

            SpatieMediaLibraryFileUpload::make('company_logo')
                ->collection('company_logo')
                ->image()
                ->imageEditor()
                ->maxSize(2048),

            SpatieMediaLibraryFileUpload::make('supporting_documents')
                ->collection('supporting_documents')
                ->multiple()
                ->maxSize(4096),

            Select::make('status')
                ->options([
                    'active' => 'Active',
                    'inactive' => 'Inactive',
                ])
                ->default('active')
                ->required()
                ->native(false),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('organization_name')
            ->columns([
                TextColumn::make('organization_name')
                    ->label('Organization')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('designation')
                    ->searchable(),

                TextColumn::make('employment_type')
                    ->badge()
                    ->formatStateUsing(fn (EmploymentType $state): string => $state->label())
                    ->color(fn (EmploymentType $state): string => $state->color()),

                TextColumn::make('start_date')
                    ->date('M Y')
                    ->sortable(),

                TextColumn::make('end_date')
                    ->date('M Y')
                    ->placeholder('Present')
                    ->sortable(),

                TextColumn::make('is_current')
                    ->label('Current')
                    ->badge()
                    ->color(fn (bool $state): string => $state ? 'success' : 'gray')
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Yes' : 'No'),

                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                    ->color(fn (string $state): string => $state === 'active' ? 'success' : 'danger'),
            ])
            ->filters([
                TrashedFilter::make(),

                SelectFilter::make('employment_type')
                    ->options(EmploymentType::class),

                SelectFilter::make('status')
                    ->options([
                        'active' => 'Active',
                        'inactive' => 'Inactive',
                    ]),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),
                RestoreAction::make(),
                DeleteAction::make(),
                ForceDeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                ]),
            ])
            ->reorderable('display_order')
            ->defaultSort('display_order')
            ->emptyStateHeading('No experience added yet')
            ->emptyStateDescription('Add this user\'s work experience.');
    }
}
