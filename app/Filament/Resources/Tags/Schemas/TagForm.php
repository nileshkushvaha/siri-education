<?php

namespace App\Filament\Resources\Tags\Schemas;

use App\Actions\GeneratePageSlugAction;
use App\Models\Tag;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class TagForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Tag Information')
                ->schema([
                    TextInput::make('name')
                        ->required()
                        ->maxLength(255)
                        ->live(onBlur: true)
                        ->afterStateUpdated(function ($state, $set): void {
                            if (blank($state)) {
                                return;
                            }

                            $set('slug', app(GeneratePageSlugAction::class)->execute($state, null, Tag::class));
                        }),
                    TextInput::make('slug')
                        ->required()
                        ->unique('tags', 'slug', ignoreRecord: true)
                        ->maxLength(255)
                        ->regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/'),
                    Textarea::make('description')
                        ->rows(3)
                        ->maxLength(500),
                    TextInput::make('sort_order')
                        ->numeric()
                        ->minValue(0)
                        ->default(0)
                        ->required(),
                    Toggle::make('is_active')
                        ->default(true),
                ])
                ->columnSpanFull(),
        ]);
    }
}
