<?php

declare(strict_types=1);

namespace App\Filament\Resources\ReferralCodes\Tables;

use App\Models\ReferralCode;
use App\Referral\Contracts\ReferralCodeServiceInterface;
use App\Referral\Enums\ReferralCodeStatus;
use App\Referral\Exceptions\ReferralException;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;

class ReferralCodesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->fontFamily('mono')
                    ->searchable()
                    ->copyable(),
                TextColumn::make('user.name')
                    ->label('Student')
                    ->searchable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (ReferralCodeStatus $state): string => $state->label())
                    ->color(fn (ReferralCodeStatus $state): string => $state === ReferralCodeStatus::Active ? 'success' : 'danger'),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('disabled_at')
                    ->dateTime()
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('disabledBy.name')
                    ->label('Disabled by')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('disable_reason')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->modifyQueryUsing(fn ($query) => $query->with(['user:id,name', 'disabledBy:id,name']))
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(ReferralCodeStatus::cases())
                        ->mapWithKeys(fn (ReferralCodeStatus $s) => [$s->value => $s->label()])
                        ->toArray()),
            ])
            ->recordActions([
                Action::make('disable')
                    ->icon('heroicon-m-no-symbol')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('Disables this code permanently — no new attributions can use it. Existing attributions and rewards are untouched. Disabled codes are never re-enabled or replaced.')
                    ->authorize(fn (): bool => auth()->user()?->can('DisableReferralCodes') ?? false)
                    ->visible(fn (ReferralCode $record): bool => $record->status === ReferralCodeStatus::Active)
                    ->form([
                        Textarea::make('reason')
                            ->label('Internal reason')
                            ->required()
                            ->maxLength(1000)
                            ->helperText('Recorded in the audit trail. Never shown to students.'),
                    ])
                    ->action(function (ReferralCode $record, array $data): void {
                        try {
                            app(ReferralCodeServiceInterface::class)->disable($record, auth()->user(), $data['reason']);
                            Notification::make()->title('Referral code disabled')->success()->send();
                        } catch (ReferralException $e) {
                            Notification::make()->title('Disable failed')->body($e->getMessage())->danger()->send();
                        } catch (AuthorizationException) {
                            Notification::make()->title('Not authorized')->danger()->send();
                        }
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
