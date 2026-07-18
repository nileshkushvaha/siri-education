<?php

declare(strict_types=1);

namespace App\Filament\Resources\ReferralAttributions\Tables;

use App\Models\ReferralAttribution;
use App\Models\User;
use App\Referral\Contracts\ReferralAttributionServiceInterface;
use App\Referral\Enums\ReferralAttributionSource;
use App\Referral\Exceptions\ReferralException;
use App\Referral\Support\ReferredStudentMask;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;

class ReferralAttributionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('#')
                    ->sortable(),
                TextColumn::make('referrer.name')
                    ->label('Referrer')
                    ->searchable(),
                TextColumn::make('referred_masked')
                    ->label('Referred student')
                    ->state(fn (ReferralAttribution $record): string => ReferredStudentMask::mask($record->referredStudent)),
                TextColumn::make('referralCode.code')
                    ->label('Code')
                    ->fontFamily('mono'),
                TextColumn::make('source')
                    ->formatStateUsing(fn (ReferralAttributionSource $state): string => ucfirst($state->value)),
                TextColumn::make('attributed_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('rewards_count')
                    ->label('Rewards')
                    ->counts('rewards'),
            ])
            ->modifyQueryUsing(fn ($query) => $query->with(['referrer:id,name', 'referredStudent:id,first_name,last_name', 'referralCode:id,code']))
            ->recordActions([
                Action::make('correct')
                    ->label('Correct referrer')
                    ->icon('heroicon-m-pencil-square')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('Exceptional, audited correction. Impossible once any reward exists — reward ownership is permanent financial history.')
                    ->authorize(fn (): bool => auth()->user()?->can('CorrectReferralAttribution') ?? false)
                    ->visible(fn (ReferralAttribution $record): bool => $record->rewards()->doesntExist())
                    ->form([
                        Select::make('new_referrer_id')
                            ->label('New referrer (student)')
                            ->getSearchResultsUsing(fn (string $search): array => User::query()
                                ->whereHas('roles', fn ($q) => $q->where('name', 'student'))
                                ->where('name', 'like', "%{$search}%")
                                ->limit(20)
                                ->pluck('name', 'id')
                                ->toArray())
                            ->searchable()
                            ->required(),
                        Textarea::make('reason')
                            ->label('Internal reason')
                            ->required()
                            ->maxLength(1000)
                            ->helperText('Recorded in the audit trail. Never shown to students.'),
                    ])
                    ->action(function (ReferralAttribution $record, array $data): void {
                        try {
                            app(ReferralAttributionServiceInterface::class)->correctAttribution(
                                $record,
                                User::query()->findOrFail((int) $data['new_referrer_id']),
                                auth()->user(),
                                $data['reason'],
                            );
                            Notification::make()->title('Attribution corrected')->success()->send();
                        } catch (ReferralException $e) {
                            Notification::make()->title('Correction refused')->body($e->getMessage())->danger()->send();
                        } catch (AuthorizationException) {
                            Notification::make()->title('Not authorized')->danger()->send();
                        }
                    }),
            ])
            ->defaultSort('attributed_at', 'desc');
    }
}
