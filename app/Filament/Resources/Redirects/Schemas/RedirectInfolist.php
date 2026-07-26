<?php

declare(strict_types=1);

namespace App\Filament\Resources\Redirects\Schemas;

use App\Content\Redirects\Enums\RedirectType;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class RedirectInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Redirect')
                ->schema([
                    Grid::make(2)->schema([
                        TextEntry::make('source_path')->label('Source'),
                        TextEntry::make('target_path')->label('Target'),
                    ]),
                    Grid::make(2)->schema([
                        TextEntry::make('type')
                            ->badge()
                            ->formatStateUsing(fn (RedirectType $state): string => $state->label()),
                        IconEntry::make('is_active')->label('Active')->boolean(),
                    ]),
                    TextEntry::make('description')->placeholder('—')->columnSpanFull(),
                    Grid::make(2)->schema([
                        TextEntry::make('creator.name')->label('Created by')->placeholder('—'),
                        TextEntry::make('updater.name')->label('Last updated by')->placeholder('—'),
                    ]),
                ]),
        ]);
    }
}
