<?php

declare(strict_types=1);

namespace App\Filament\Resources\Academic\RelationManagers;

use App\Curriculum\Exceptions\AcademicContextException;
use App\Curriculum\Services\EducationSystemService;
use App\Models\Country;
use App\Models\CountryEducationSystem;
use App\Models\EducationSystem;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Exceptions\Halt;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Mutations always go through EducationSystemService — never a raw
 * Filament attach/detach — so duplicate-mapping prevention and audit
 * logging cannot be bypassed (see CLAUDE.md: "logic belongs in
 * Services").
 */
class CountryMappingsRelationManager extends RelationManager
{
    protected static string $relationship = 'countryMappings';

    protected static ?string $title = 'Countries';

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('country'))
            ->recordTitleAttribute('id')
            ->columns([
                TextColumn::make('country.name')
                    ->label('Country')
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([
                Action::make('addCountry')
                    ->label('Add Country')
                    ->icon('heroicon-m-plus-circle')
                    ->form([
                        Select::make('country_id')
                            ->label('Country')
                            ->options(fn (): array => Country::query()->active()->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()
                            ->required(),
                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true),
                    ])
                    ->action(function (array $data): void {
                        /** @var EducationSystem $system */
                        $system = $this->getOwnerRecord();
                        $country = Country::query()->findOrFail($data['country_id']);

                        try {
                            $nextOrder = (int) $system->countryMappings()->max('display_order') + 1;
                            app(EducationSystemService::class)->mapToCountry(auth()->user(), $system, $country, (bool) $data['is_active'], $nextOrder);
                        } catch (AcademicContextException $e) {
                            Notification::make()->title('Mapping not added')->body($e->getMessage())->danger()->send();

                            throw new Halt;
                        }

                        Notification::make()->title('Country mapped')->success()->send();
                    }),
            ])
            ->recordActions([
                Action::make('remove')
                    ->label('Remove')
                    ->icon('heroicon-m-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function (CountryEducationSystem $record): void {
                        app(EducationSystemService::class)->unmapFromCountry(auth()->user(), $record);

                        Notification::make()->title('Country unmapped')->success()->send();
                    }),
            ])
            ->reorderable('display_order')
            ->authorizeReorder(fn (): bool => auth()->user()?->can('update', $this->getOwnerRecord()) ?? false)
            ->defaultSort('display_order');
    }
}
