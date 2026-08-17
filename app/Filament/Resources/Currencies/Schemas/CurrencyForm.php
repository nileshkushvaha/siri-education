<?php

namespace App\Filament\Resources\Currencies\Schemas;

use App\Support\MoneyFormatter;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use InvalidArgumentException;

class CurrencyForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Currency Details')
                ->description('ISO 4217 currency metadata used for country defaults.')
                ->icon('heroicon-o-banknotes')
                ->schema([
                    Grid::make(3)->schema([
                        TextInput::make('code')
                            ->label('ISO Code')
                            ->required()
                            ->minLength(3)
                            ->maxLength(3)
                            ->unique(ignoreRecord: true)
                            ->placeholder('USD'),

                        TextInput::make('name')
                            ->required()
                            ->maxLength(100)
                            ->placeholder('US Dollar'),

                        TextInput::make('symbol')
                            ->maxLength(10)
                            ->nullable()
                            ->placeholder('$'),
                    ]),

                    Grid::make(3)->schema([
                        TextInput::make('numeric_code')
                            ->label('Numeric Code')
                            ->minLength(3)
                            ->maxLength(3)
                            ->regex('/^\d{3}$/')
                            ->unique(ignoreRecord: true)
                            ->nullable()
                            ->placeholder('840')
                            ->helperText('ISO 4217 numeric code, e.g. 840. Leading zeros are significant (e.g. 008) — kept as text, not a number field.'),

                        TextInput::make('minor_units')
                            ->label('Decimal places')
                            ->helperText('Number of decimal places this currency uses, e.g. 2 for USD, 0 for JPY.')
                            ->integer()
                            ->minValue(0)
                            ->maxValue(4)
                            ->default(2)
                            ->required(),

                        Select::make('status')
                            ->options([
                                'active' => 'Active',
                                'inactive' => 'Inactive',
                            ])
                            ->default('active')
                            ->required()
                            ->native(false),
                    ]),

                    Grid::make(2)->schema([
                        TextInput::make('sort_order')
                            ->integer()
                            ->minValue(0)
                            ->default(0),

                        Textarea::make('remarks')
                            ->maxLength(500)
                            ->nullable()
                            ->rows(2),
                    ]),
                ])
                ->columnSpanFull(),

            // SRS §13.12. These live per currency rather than in
            // WalletSettings because a recharge limit is an amount of
            // money: one platform-wide number cannot mean ₹500 and $10
            // at once, and this application has no exchange rate.
            Section::make('Wallet Recharge Limits')
                ->description('Optional minimum and maximum wallet recharge for this currency. Leave blank for no limit — an empty minimum still requires a positive amount.')
                ->icon('heroicon-o-wallet')
                ->schema([
                    Grid::make(2)->schema([
                        self::limitInput('minimum_recharge_minor', 'Minimum recharge'),
                        self::limitInput('maximum_recharge_minor', 'Maximum recharge'),
                    ]),
                ])
                ->columnSpanFull(),
        ]);
    }

    /**
     * A major-unit money input over a minor-unit column. Admins type
     * "500.00"; the column stores 50000. Conversion goes through
     * MoneyFormatter in both directions using THIS currency's own
     * decimal places, so nothing here assumes a two-decimal currency
     * and no float ever touches the value.
     *
     * Blank stays NULL — "unconfigured", which is not the same as a
     * limit of zero.
     */
    private static function limitInput(string $name, string $label): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->nullable()
            ->helperText('In this currency\'s own units, e.g. 500 or 500.00.')
            ->formatStateUsing(fn (?int $state, Get $get): ?string => $state === null
                ? null
                : MoneyFormatter::toMajor($state, self::exponent($get)))
            ->rule(fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                if (blank($value)) {
                    return;
                }

                try {
                    MoneyFormatter::toMinor((string) $value, self::exponent($get));
                } catch (InvalidArgumentException $e) {
                    // Surfaced as a field error rather than a 500: the
                    // decimal-places rule is currency-specific, so
                    // "1500.5" is valid for USD and invalid for JPY.
                    $fail($e->getMessage());
                }
            })
            ->dehydrateStateUsing(fn (?string $state, Get $get): ?int => blank($state)
                ? null
                : MoneyFormatter::toMinor($state, self::exponent($get)));
    }

    /** The decimal places currently entered on this same form — never a hardcoded 2. */
    private static function exponent(Get $get): int
    {
        return (int) ($get('minor_units') ?? 2);
    }
}
