<?php

declare(strict_types=1);

namespace App\Filament\Resources\PackageBenefitRules\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PackageBenefitRuleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Package Offer')
                ->description('A reusable offer template an instructor can propose to a student. It defines lesson quantities only — the price is always calculated from the student\'s standard lesson price when a proposal is created.')
                ->columnSpanFull()
                ->schema([
                    TextInput::make('name')
                        ->label('Offer Name')
                        ->required()
                        ->maxLength(150)
                        ->placeholder('e.g. 14 paid lessons + 1 bonus lesson')
                        ->helperText('Shown to instructors when they choose an offer.')
                        ->columnSpanFull(),
                    Grid::make(3)->schema([
                        TextInput::make('paid_quantity')
                            ->label('Paid Lessons')
                            ->numeric()
                            ->required()
                            ->minValue(1)
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn ($state, $get, $set) => $set('total_quantity', (int) $state + (int) $get('bonus_quantity')))
                            ->helperText('Number of lessons the student pays for.'),
                        TextInput::make('bonus_quantity')
                            ->label('Bonus Lessons')
                            ->numeric()
                            ->required()
                            ->default(0)
                            ->minValue(0)
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn ($state, $get, $set) => $set('total_quantity', (int) $get('paid_quantity') + (int) $state))
                            ->helperText('Additional free lessons included in this offer. Bonus lessons add to what the student receives and never reduce instructor pay.'),
                        TextInput::make('total_quantity')
                            ->label('Total Lessons')
                            ->numeric()
                            ->required()
                            ->readOnly()
                            ->helperText('Total lessons available to the student — paid + bonus, calculated automatically.'),
                    ]),
                    Toggle::make('is_active')
                        ->label('Active')
                        ->default(true)
                        ->helperText('Inactive offers are hidden from instructors when they create a package proposal.'),
                ]),
        ]);
    }
}
