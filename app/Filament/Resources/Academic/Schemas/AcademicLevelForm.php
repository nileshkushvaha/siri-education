<?php

namespace App\Filament\Resources\Academic\Schemas;

use App\Actions\GeneratePageSlugAction;
use App\Enums\AcademicStatus;
use App\Models\AcademicLevel;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AcademicLevelForm
{
    public static function configure(Schema $schema): Schema
    {
        $isCreate = $schema->getLivewire() instanceof CreateRecord;

        return $schema
            ->components([
                Section::make('Level Information')
                    ->columnSpanFull()
                    ->schema([
                        // Identity — name drives the auto-generated slug, so they
                        // stay paired. The slug itself is hidden on create (it's
                        // generated silently from the name) and only exposed for
                        // correction once the record exists — editing it later is
                        // a deliberate action since it changes the public URL.
                        Grid::make(2)->schema([
                            TextInput::make('name')
                                ->required()
                                ->maxLength(100)
                                ->placeholder('e.g. Middle School')
                                ->live(onBlur: true)
                                ->afterStateUpdated(function ($state, $set): void {
                                    if (blank($state)) {
                                        return;
                                    }

                                    $set('slug', app(GeneratePageSlugAction::class)->execute($state, null, AcademicLevel::class));
                                }),
                            TextInput::make('slug')
                                ->required()
                                ->unique('academic_levels', 'slug', ignoreRecord: true)
                                ->maxLength(100)
                                ->regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')
                                ->placeholder('e.g. middle-school')
                                ->helperText('Changing this changes the public URL.')
                                ->hidden($isCreate)
                                ->dehydratedWhenHidden()
                                ->dehydrateStateUsing(fn (?string $state, $get): string => filled($state)
                                    ? $state
                                    : app(GeneratePageSlugAction::class)->execute((string) $get('name'), null, AcademicLevel::class)),
                        ]),

                        // Content — long-form text always gets the full row.
                        Textarea::make('description')
                            ->rows(3)
                            ->maxLength(1000)
                            ->placeholder('Brief overview of this academic level…')
                            ->columnSpanFull(),

                        // Internal normalized grade-band metadata only
                        // (Phase 3.1 §17) — no longer the source of
                        // truth for the country-aware booking wizard's
                        // selectable Class/Grade/Year options, which
                        // come from EducationSystemLevel instead (see
                        // EducationSystemResource's "Levels" tab). Kept
                        // for internal reporting/broad-band metadata;
                        // no hardcoded 1-12 ceiling so future bands
                        // (e.g. spanning Year 13) remain representable.
                        Grid::make(2)->schema([
                            TextInput::make('min_grade')
                                ->label('Min Grade (internal)')
                                ->numeric()
                                ->minValue(1)
                                ->placeholder('e.g. 6')
                                ->helperText('Internal grade-band metadata, not the booking wizard\'s level options. Leave blank for levels not bound to a grade range (e.g. Undergraduate).'),
                            TextInput::make('max_grade')
                                ->label('Max Grade (internal)')
                                ->numeric()
                                ->minValue(1)
                                ->placeholder('e.g. 8'),
                        ]),

                        // Education-system scoping: UK "Year 10"
                        // and US "Grade 9" can coexist as country-scoped rows;
                        // blank = global. min/max grade above keep every level
                        // bridged to the universal 1-12 ints booking matches on.
                        Select::make('country_id')
                            ->label('Education System (Country)')
                            ->relationship('country', 'name')
                            ->searchable()
                            ->preload()
                            ->placeholder('Global (all countries)')
                            ->helperText('Scope this level to one country\'s education system (e.g. UK Year groups, US Grades). Leave blank for global levels.'),

                        // Display settings.
                        Grid::make(2)->schema([
                            Select::make('status')
                                ->options(
                                    collect(AcademicStatus::cases())
                                        ->mapWithKeys(fn (AcademicStatus $s) => [$s->value => $s->label()])
                                        ->toArray()
                                )
                                ->default(AcademicStatus::Active->value)
                                ->required()
                                ->placeholder('Select status'),
                            TextInput::make('display_order')
                                ->numeric()
                                ->default(0)
                                ->minValue(0)
                                ->placeholder('0'),
                        ]),
                    ]),
            ]);
    }
}
