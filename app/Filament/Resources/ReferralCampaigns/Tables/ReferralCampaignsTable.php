<?php

declare(strict_types=1);

namespace App\Filament\Resources\ReferralCampaigns\Tables;

use App\Models\ReferralCampaign;
use App\Referral\Contracts\ReferralCampaignServiceInterface;
use App\Referral\Enums\ReferralCampaignStatus;
use App\Referral\Enums\ReferralRewardType;
use App\Referral\Exceptions\ReferralException;
use App\Support\MoneyFormatter;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * No status dropdown, no delete. Every lifecycle mutation is a
 * dedicated action that requires ManageReferralCampaigns (via the
 * policy's update ability), takes a mandatory reason, and calls
 * ReferralCampaignService — mirroring the compensation-agreement
 * surface.
 */
class ReferralCampaignsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (ReferralCampaignStatus $state): string => $state->label())
                    ->color(fn (ReferralCampaignStatus $state): string => $state->color()),
                TextColumn::make('starts_at')
                    ->label('Window (UTC)')
                    ->state(fn (ReferralCampaign $record): string => sprintf(
                        '%s → %s',
                        $record->starts_at->format('M j, Y H:i'),
                        $record->ends_at->format('M j, Y H:i'),
                    ))
                    ->sortable(),
                TextColumn::make('reward_type')
                    ->label('Reward')
                    ->state(fn (ReferralCampaign $record): string => $record->reward_type === ReferralRewardType::Percentage
                        ? sprintf('%s bp (%s%%)', $record->reward_value, rtrim(rtrim(number_format($record->reward_value / 100, 2), '0'), '.'))
                        : MoneyFormatter::format($record->reward_value, (string) $record->reward_currency_code)),
                TextColumn::make('min_completed_paid_lessons')
                    ->label('Min lessons')
                    ->sortable(),
                TextColumn::make('max_rewarded_classes')
                    ->label('Max classes')
                    ->sortable(),
                TextColumn::make('eligible_countries_summary')
                    ->label('Countries')
                    ->state(function (ReferralCampaign $record): string {
                        $codes = $record->eligibleCountries->pluck('iso2')->all();

                        if ($codes === []) {
                            return 'All countries';
                        }

                        return count($codes) <= 4
                            ? implode(', ', $codes)
                            : sprintf('%s +%d more', implode(', ', array_slice($codes, 0, 4)), count($codes) - 4);
                    }),
                IconColumn::make('requires_fraud_review')
                    ->label('Fraud review')
                    ->boolean(),
            ])
            ->modifyQueryUsing(fn ($query) => $query->with('eligibleCountries'))
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(ReferralCampaignStatus::cases())
                        ->mapWithKeys(fn (ReferralCampaignStatus $s) => [$s->value => $s->label()])
                        ->toArray()),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn (ReferralCampaign $record): bool => $record->status->isEditable()),

                Action::make('activate')
                    ->icon('heroicon-m-play')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription('Activates this campaign. Its window must not overlap another active campaign for an intersecting country scope.')
                    ->authorize(fn (ReferralCampaign $record): bool => auth()->user()?->can('update', $record) ?? false)
                    ->visible(fn (ReferralCampaign $record): bool => $record->status === ReferralCampaignStatus::Draft)
                    ->form([self::reasonField()])
                    ->action(fn (ReferralCampaign $record, array $data) => self::callService(
                        fn (ReferralCampaignServiceInterface $service) => $service->activate($record, auth()->user(), $data['reason']),
                        'Campaign activated',
                    )),

                Action::make('pause')
                    ->icon('heroicon-m-pause')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->authorize(fn (ReferralCampaign $record): bool => auth()->user()?->can('update', $record) ?? false)
                    ->visible(fn (ReferralCampaign $record): bool => $record->status === ReferralCampaignStatus::Active)
                    ->form([self::reasonField()])
                    ->action(fn (ReferralCampaign $record, array $data) => self::callService(
                        fn (ReferralCampaignServiceInterface $service) => $service->pause($record, auth()->user(), $data['reason']),
                        'Campaign paused',
                    )),

                Action::make('resume')
                    ->icon('heroicon-m-play')
                    ->color('success')
                    ->requiresConfirmation()
                    ->authorize(fn (ReferralCampaign $record): bool => auth()->user()?->can('update', $record) ?? false)
                    ->visible(fn (ReferralCampaign $record): bool => $record->status === ReferralCampaignStatus::Paused)
                    ->form([self::reasonField()])
                    ->action(fn (ReferralCampaign $record, array $data) => self::callService(
                        fn (ReferralCampaignServiceInterface $service) => $service->resume($record, auth()->user(), $data['reason']),
                        'Campaign resumed',
                    )),

                Action::make('complete')
                    ->icon('heroicon-m-flag')
                    ->color('info')
                    ->requiresConfirmation()
                    ->modalDescription('Marks the campaign completed. A completed campaign can never become active again.')
                    ->authorize(fn (ReferralCampaign $record): bool => auth()->user()?->can('update', $record) ?? false)
                    ->visible(fn (ReferralCampaign $record): bool => in_array($record->status, [ReferralCampaignStatus::Active, ReferralCampaignStatus::Paused], true))
                    ->form([self::reasonField()])
                    ->action(fn (ReferralCampaign $record, array $data) => self::callService(
                        fn (ReferralCampaignServiceInterface $service) => $service->complete($record, auth()->user(), $data['reason']),
                        'Campaign completed',
                    )),

                Action::make('archive')
                    ->icon('heroicon-m-archive-box')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalDescription('Archives the campaign permanently. Archived campaigns stay available for history and reporting but can never generate rewards again.')
                    ->authorize(fn (ReferralCampaign $record): bool => auth()->user()?->can('update', $record) ?? false)
                    ->visible(fn (ReferralCampaign $record): bool => in_array($record->status, [ReferralCampaignStatus::Draft, ReferralCampaignStatus::Paused, ReferralCampaignStatus::Completed], true))
                    ->form([self::reasonField()])
                    ->action(fn (ReferralCampaign $record, array $data) => self::callService(
                        fn (ReferralCampaignServiceInterface $service) => $service->archive($record, auth()->user(), $data['reason']),
                        'Campaign archived',
                    )),
            ])
            ->defaultSort('starts_at', 'desc');
    }

    private static function reasonField(): Textarea
    {
        return Textarea::make('reason')
            ->label('Internal reason')
            ->required()
            ->maxLength(1000)
            ->helperText('Recorded in the audit trail. Never shown to students.');
    }

    private static function callService(callable $callback, string $successTitle): void
    {
        try {
            $callback(app(ReferralCampaignServiceInterface::class));
            Notification::make()->title($successTitle)->success()->send();
        } catch (ReferralException $e) {
            Notification::make()->title('Action failed')->body($e->getMessage())->danger()->send();
        }
    }
}
