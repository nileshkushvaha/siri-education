<?php

namespace App\Forms\Blocks;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;

class FeaturedTeachersBlockForm
{
    public static function schema(): array
    {
        return [
            Section::make('Featured Teachers')
                ->description('Content comes from the CMS; instructor records come from the public instructor service.')
                ->collapsible(false)
                ->schema([
                    Select::make('section')
                        ->label('Recommendation Section')
                        ->options([
                            'featured' => 'Featured Instructors (admin-curated)',
                            'popular' => 'Popular Instructors (highest rated)',
                            'new' => 'New Instructors (recently joined)',
                            'recommended_for_you' => 'Recommended for You (personalized, falls back to Popular)',
                        ])
                        ->default('featured')
                        ->required()
                        ->helperText('GAP-025: which recommendation strategy this block displays. Eligibility, pricing, and ranking are identical across sections — only the selection changes.'),
                    TextInput::make('eyebrow')->label('Eyebrow')->maxLength(120),
                    TextInput::make('title')->label('Title')->maxLength(255),
                    Textarea::make('description')->label('Description')->rows(3)->maxLength(700),
                    Select::make('limit')
                        ->label('Number of Teachers')
                        ->options([3 => '3', 4 => '4', 6 => '6', 8 => '8', 12 => '12'])
                        ->default(4),
                    Select::make('columns')
                        ->label('Grid Columns')
                        ->options([2 => '2 Columns', 3 => '3 Columns', 4 => '4 Columns'])
                        ->default(4),
                    TextInput::make('link_label')->label('Section Link Label')->maxLength(120)->columnSpan(6),
                    TextInput::make('link_url')->label('Section Link URL')->maxLength(500)->columnSpan(6),
                ])
                ->columns(12),
        ];
    }
}
