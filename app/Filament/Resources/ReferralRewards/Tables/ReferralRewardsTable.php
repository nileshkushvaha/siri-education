<?php

declare(strict_types=1);

namespace App\Filament\Resources\ReferralRewards\Tables;

use App\Filament\Support\Tables\AdminListTable;
use App\Models\ReferralReward;
use App\Referral\Contracts\ReferralRewardServiceInterface;
use App\Referral\Enums\ReferralRewardStatus;
use App\Referral\Exceptions\ReferralException;
use App\Referral\Support\ReferredStudentMask;
use App\Support\MoneyFormatter;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Every mutation is a dedicated, permission-authorized, reason-taking
 * action that calls ReferralRewardService — the same single credit and
 * reversal paths the listeners and sweep use. No status dropdown, no
 * edit, no delete. Referred students appear only through the mask rule.
 */
class ReferralRewardsTable
{
    public static function configure(Table $table): Table
    {
        $table
            ->columns([
                TextColumn::make('id')
                    ->label('#')
                    ->sortable(),
                TextColumn::make('referrer.name')
                    ->label('Referrer')
                    ->searchable(),
                TextColumn::make('referred_masked')
                    ->label('Referred student')
                    ->state(fn (ReferralReward $record): string => ReferredStudentMask::mask($record->referredStudent)),
                TextColumn::make('campaign.name')
                    ->label('Campaign')
                    ->toggleable(),
                TextColumn::make('class_sequence')
                    ->label('Class #')
                    ->sortable(),
                TextColumn::make('reward_amount_minor')
                    ->label('Reward')
                    ->state(fn (ReferralReward $record): string => MoneyFormatter::format($record->reward_amount_minor, $record->reward_currency_code)),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (ReferralRewardStatus $state): string => $state->label())
                    ->color(fn (ReferralRewardStatus $state): string => $state->color()),
                TextColumn::make('hold_reason')
                    ->label('Operational reason')
                    ->placeholder('—'),
                TextColumn::make('eligible_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('credited_at')
                    ->dateTime()
                    ->sortable()
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('reversed_at')
                    ->dateTime()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->modifyQueryUsing(fn ($query) => $query->with(['referrer:id,name', 'referredStudent:id,first_name,last_name', 'campaign:id,name']))
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(ReferralRewardStatus::cases())
                        ->mapWithKeys(fn (ReferralRewardStatus $s) => [$s->value => $s->label()])
                        ->toArray()),
                SelectFilter::make('hold_reason')
                    ->label('Operational reason')
                    ->options([
                        'fraud_review' => 'Fraud review',
                        'currency_mismatch' => 'Currency mismatch',
                        'wallet_unusable' => 'Wallet unusable',
                        'reversal_required' => 'Reversal required',
                    ]),
            ])
            ->recordActions([
                Action::make('approve')
                    ->icon('heroicon-m-check-badge')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription("Re-checks eligibility and currency, then credits the reward to the referrer's wallet.")
                    ->authorize(fn (): bool => auth()->user()?->can('ApproveReferralRewards') ?? false)
                    ->visible(fn (ReferralReward $record): bool => $record->status === ReferralRewardStatus::Held)
                    ->form([self::reasonField()])
                    ->action(fn (ReferralReward $record, array $data) => self::callService(
                        fn (ReferralRewardServiceInterface $service) => $service->approveHeldReward($record, auth()->user(), $data['reason']),
                        'Reward approved',
                        'Approval failed',
                    )),

                Action::make('reject')
                    ->icon('heroicon-m-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->authorize(fn (): bool => auth()->user()?->can('RejectReferralRewards') ?? false)
                    ->visible(fn (ReferralReward $record): bool => in_array($record->status, [ReferralRewardStatus::Held, ReferralRewardStatus::CreditFailed], true))
                    ->form([self::reasonField()])
                    ->action(fn (ReferralReward $record, array $data) => self::callService(
                        fn (ReferralRewardServiceInterface $service) => $service->rejectHeldReward($record, auth()->user(), $data['reason']),
                        'Reward rejected',
                        'Rejection failed',
                    )),

                Action::make('retry_credit')
                    ->label('Retry credit')
                    ->icon('heroicon-m-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->authorize(fn (): bool => auth()->user()?->can('RetryReferralRewardCredits') ?? false)
                    ->visible(fn (ReferralReward $record): bool => $record->status === ReferralRewardStatus::CreditFailed)
                    ->form([self::reasonField()])
                    ->action(fn (ReferralReward $record, array $data) => self::callService(
                        fn (ReferralRewardServiceInterface $service) => $service->retryFailedCredit($record, auth()->user(), $data['reason']),
                        'Credit retried',
                        'Retry failed',
                    )),

                Action::make('complete_reversal')
                    ->label('Complete reversal')
                    ->icon('heroicon-m-arrow-uturn-left')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription("Reverses the credited reward by posting an offsetting entry to the referrer's wallet. Requires wallet-management permission.")
                    ->authorize(fn (): bool => auth()->user()?->can('ReverseReferralRewards') ?? false)
                    ->visible(fn (ReferralReward $record): bool => $record->status === ReferralRewardStatus::Credited && $record->hold_reason === 'reversal_required')
                    ->form([self::reasonField()])
                    ->action(fn (ReferralReward $record, array $data) => self::callService(
                        fn (ReferralRewardServiceInterface $service) => $service->completeRequiredReversal($record, auth()->user(), $data['reason']),
                        'Reversal completed',
                        'Reversal failed',
                    )),
            ])
            ->defaultSort('id', 'desc');

        return AdminListTable::apply($table);
    }

    private static function reasonField(): Textarea
    {
        return Textarea::make('reason')
            ->label('Internal reason')
            ->required()
            ->maxLength(1000)
            ->helperText('Recorded in the audit trail. Never shown to students.');
    }

    private static function callService(callable $callback, string $successTitle, string $failureTitle): void
    {
        try {
            $callback(app(ReferralRewardServiceInterface::class));
            Notification::make()->title($successTitle)->success()->send();
        } catch (ReferralException $e) {
            Notification::make()->title($failureTitle)->body($e->getMessage())->danger()->send();
        } catch (AuthorizationException) {
            Notification::make()->title('Not authorized')->danger()->send();
        }
    }
}
