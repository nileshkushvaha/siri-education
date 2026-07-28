<?php

declare(strict_types=1);

namespace App\Filament\Resources\Redirects\Schemas;

use App\Content\Redirects\Enums\RedirectType;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class RedirectForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Redirect')
                ->schema([
                    TextInput::make('source_path')
                        ->label('Source path')
                        ->placeholder('/old-page')
                        ->required()
                        ->maxLength(191)
                        ->helperText('The old public URL path. Normalized automatically (lowercase, no trailing slash).'),
                    TextInput::make('target_path')
                        ->label('Target path')
                        ->placeholder('/new-page')
                        ->required()
                        ->maxLength(191)
                        ->helperText('Must be an internal path on this site — admin, API, auth, storage, and download routes are not allowed.'),
                    Select::make('type')
                        ->options(collect(RedirectType::cases())->mapWithKeys(fn (RedirectType $t) => [$t->value => $t->label()])->all())
                        ->default(RedirectType::Permanent->value)
                        ->required(),
                    Textarea::make('description')
                        ->label('Description (optional)')
                        ->maxLength(1000),
                ])
                ->columnSpanFull(),
        ]);
    }
}
