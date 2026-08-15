<?php

declare(strict_types=1);

namespace App\Filament\Resources\PromotionalCreditCampaigns\Tables;

use App\Models\PromotionalCreditCampaign;
use App\PromotionalCredits\Enums\PromotionalCreditCampaignStatus;
use App\PromotionalCredits\Exceptions\PromotionalCreditException;
use App\PromotionalCredits\Services\PromotionalCreditService;
use App\Support\MoneyFormatter;
use App\Support\Timezone\ViewerDateTime;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * No status dropdown, no delete. Every lifecycle mutation is a
 * dedicated action that requires ManagePromotionalCreditCampaigns
 * (via the policy's update ability), takes a mandatory reason, and
 * calls PromotionalCreditService — mirroring ReferralCampaignsTable.
 */
class PromotionalCreditCampaignsTable
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
                    ->formatStateUsing(fn (PromotionalCreditCampaignStatus $state): string => $state->label())
                    ->color(fn (PromotionalCreditCampaignStatus $state): string => $state->color()),
                TextColumn::make('starts_at')
                    ->label('Window (UTC)')
                    ->state(fn (PromotionalCreditCampaign $record): string => sprintf(
                        '%s → %s',
                        ViewerDateTime::dateTime($record->starts_at),
                        ViewerDateTime::dateTime($record->ends_at),
                    ))
                    ->sortable(),
                TextColumn::make('amount_minor')
                    ->label('Amount')
                    ->state(fn (PromotionalCreditCampaign $record): string => MoneyFormatter::format($record->amount_minor, $record->currency_code))
                    ->sortable(),
                TextColumn::make('per_student_limit')
                    ->label('Per-student limit')
                    ->sortable(),
                TextColumn::make('total_budget_minor')
                    ->label('Budget')
                    ->state(fn (PromotionalCreditCampaign $record): string => $record->total_budget_minor !== null
                        ? MoneyFormatter::format($record->total_budget_minor, $record->currency_code)
                        : 'No cap'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(PromotionalCreditCampaignStatus::cases())
                        ->mapWithKeys(fn (PromotionalCreditCampaignStatus $s) => [$s->value => $s->label()])
                        ->toArray()),
            ])
            ->recordActions([
                ViewAction::make(),

                EditAction::make()
                    ->visible(fn (PromotionalCreditCampaign $record): bool => $record->status->isEditable()),

                Action::make('activate')
                    ->icon('heroicon-m-play')
                    ->color('success')
                    ->requiresConfirmation()
                    ->authorize(fn (PromotionalCreditCampaign $record): bool => auth()->user()?->can('update', $record) ?? false)
                    ->visible(fn (PromotionalCreditCampaign $record): bool => $record->status === PromotionalCreditCampaignStatus::Draft)
                    ->form([self::reasonField()])
                    ->action(fn (PromotionalCreditCampaign $record, array $data) => self::callService(
                        fn (PromotionalCreditService $service) => $service->activateCampaign($record, auth()->user(), $data['reason']),
                        'Campaign activated',
                    )),

                Action::make('pause')
                    ->icon('heroicon-m-pause')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->authorize(fn (PromotionalCreditCampaign $record): bool => auth()->user()?->can('update', $record) ?? false)
                    ->visible(fn (PromotionalCreditCampaign $record): bool => $record->status === PromotionalCreditCampaignStatus::Active)
                    ->form([self::reasonField()])
                    ->action(fn (PromotionalCreditCampaign $record, array $data) => self::callService(
                        fn (PromotionalCreditService $service) => $service->pauseCampaign($record, auth()->user(), $data['reason']),
                        'Campaign paused',
                    )),

                Action::make('resume')
                    ->icon('heroicon-m-play')
                    ->color('success')
                    ->requiresConfirmation()
                    ->authorize(fn (PromotionalCreditCampaign $record): bool => auth()->user()?->can('update', $record) ?? false)
                    ->visible(fn (PromotionalCreditCampaign $record): bool => $record->status === PromotionalCreditCampaignStatus::Paused)
                    ->form([self::reasonField()])
                    ->action(fn (PromotionalCreditCampaign $record, array $data) => self::callService(
                        fn (PromotionalCreditService $service) => $service->resumeCampaign($record, auth()->user(), $data['reason']),
                        'Campaign resumed',
                    )),

                Action::make('complete')
                    ->icon('heroicon-m-flag')
                    ->color('info')
                    ->requiresConfirmation()
                    ->authorize(fn (PromotionalCreditCampaign $record): bool => auth()->user()?->can('update', $record) ?? false)
                    ->visible(fn (PromotionalCreditCampaign $record): bool => in_array($record->status, [PromotionalCreditCampaignStatus::Active, PromotionalCreditCampaignStatus::Paused], true))
                    ->form([self::reasonField()])
                    ->action(fn (PromotionalCreditCampaign $record, array $data) => self::callService(
                        fn (PromotionalCreditService $service) => $service->completeCampaign($record, auth()->user(), $data['reason']),
                        'Campaign completed',
                    )),

                Action::make('archive')
                    ->icon('heroicon-m-archive-box')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalDescription('Archives the campaign permanently. Archived campaigns stay available for history and reporting but can never issue credits again.')
                    ->authorize(fn (PromotionalCreditCampaign $record): bool => auth()->user()?->can('update', $record) ?? false)
                    ->visible(fn (PromotionalCreditCampaign $record): bool => in_array($record->status, [PromotionalCreditCampaignStatus::Draft, PromotionalCreditCampaignStatus::Paused, PromotionalCreditCampaignStatus::Completed], true))
                    ->form([self::reasonField()])
                    ->action(fn (PromotionalCreditCampaign $record, array $data) => self::callService(
                        fn (PromotionalCreditService $service) => $service->archiveCampaign($record, auth()->user(), $data['reason']),
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
            $callback(app(PromotionalCreditService::class));
            Notification::make()->title($successTitle)->success()->send();
        } catch (PromotionalCreditException $e) {
            Notification::make()->title('Action failed')->body($e->getMessage())->danger()->send();
        }
    }
}
