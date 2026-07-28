<?php

declare(strict_types=1);

namespace App\Filament\Resources\Navigation\Schemas;

use App\Actions\GeneratePageSlugAction;
use App\Enums\Navigation\NavigationLayoutType;
use App\Enums\Navigation\NavigationLocation;
use App\Enums\Navigation\NavigationStatus;
use App\Models\NavigationMenu;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Schema;

class NavigationMenuForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make('navigation_tabs')
                    ->tabs([
                        Tabs\Tab::make('General')
                            ->schema([
                                Section::make('Menu Information')
                                    ->schema([
                                        TextInput::make('name')
                                            ->required()
                                            ->maxLength(255)
                                            ->live(onBlur: true)
                                            ->afterStateUpdated(function ($state, $set, $record): void {
                                                if (blank($state)) {
                                                    return;
                                                }

                                                $set('slug', app(GeneratePageSlugAction::class)->execute(
                                                    $state,
                                                    $record?->id,
                                                    NavigationMenu::class,
                                                ));
                                            }),
                                        TextInput::make('slug')
                                            ->required()
                                            ->unique('navigations', 'slug', ignoreRecord: true)
                                            ->maxLength(255)
                                            ->regex('/^[a-z0-9]+(?:[_-][a-z0-9]+)*$/')
                                            ->helperText('URL-friendly identifier. Auto-generated from name.'),
                                        Textarea::make('description')
                                            ->rows(3)
                                            ->maxLength(500)
                                            ->nullable(),
                                    ]),
                            ]),

                        Tabs\Tab::make('Settings')
                            ->schema([
                                Section::make('Display Settings')
                                    ->schema([
                                        Select::make('location')
                                            ->options(collect(NavigationLocation::cases())->mapWithKeys(
                                                fn (NavigationLocation $case) => [$case->value => $case->label()],
                                            ))
                                            ->required()
                                            ->native(false)
                                            ->helperText('Where this menu will appear on the site.'),
                                        Select::make('layout_type')
                                            ->label('Layout Type')
                                            ->options(collect(NavigationLayoutType::cases())->mapWithKeys(
                                                fn (NavigationLayoutType $case) => [$case->value => $case->label()],
                                            ))
                                            ->default(NavigationLayoutType::Standard->value)
                                            ->required()
                                            ->native(false),
                                        TextInput::make('locale')
                                            ->maxLength(10)
                                            ->nullable()
                                            ->placeholder('e.g. en, fr, de')
                                            ->helperText('Leave blank to apply to all locales.'),
                                    ]),
                                Section::make('Advanced Settings')
                                    ->schema([
                                        KeyValue::make('settings')
                                            ->nullable()
                                            ->helperText('Optional custom settings for this menu, used only by templates built to read them.'),
                                    ])
                                    ->collapsible()
                                    ->collapsed(),
                            ]),

                        Tabs\Tab::make('Publishing')
                            ->schema([
                                Section::make('Publication Settings')
                                    ->schema([
                                        Select::make('status')
                                            ->options(collect(NavigationStatus::cases())->mapWithKeys(
                                                fn (NavigationStatus $case) => [$case->value => $case->label()],
                                            ))
                                            ->default(NavigationStatus::Draft->value)
                                            ->required()
                                            ->native(false),
                                    ]),
                            ]),
                    ])
                    ->columnSpanFull(),
            ]);
    }
}
