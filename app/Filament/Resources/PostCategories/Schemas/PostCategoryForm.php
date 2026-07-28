<?php

namespace App\Filament\Resources\PostCategories\Schemas;

use App\Actions\GeneratePageSlugAction;
use App\Models\PostCategory;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PostCategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Category Information')
                ->schema([
                    TextInput::make('name')
                        ->required()
                        ->maxLength(255)
                        ->live(onBlur: true)
                        ->afterStateUpdated(function ($state, $set): void {
                            if (blank($state)) {
                                return;
                            }

                            $set('slug', app(GeneratePageSlugAction::class)->execute($state, null, PostCategory::class));
                        }),
                    TextInput::make('slug')
                        ->required()
                        ->unique('post_categories', 'slug', ignoreRecord: true)
                        ->maxLength(255)
                        ->regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/'),
                    Select::make('parent_id')
                        ->label('Parent Category')
                        ->relationship('parent', 'name')
                        ->searchable()
                        ->preload()
                        ->nullable(),
                    Textarea::make('description')
                        ->rows(3)
                        ->maxLength(500),
                    TextInput::make('sort_order')
                        ->numeric()
                        ->default(0)
                        ->required(),
                    Toggle::make('is_active')
                        ->default(true),
                ])
                ->columnSpanFull(),
        ]);
    }
}
