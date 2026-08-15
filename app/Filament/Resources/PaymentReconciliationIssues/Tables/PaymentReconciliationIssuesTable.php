<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentReconciliationIssues\Tables;

use App\Models\PaymentReconciliationIssue;
use App\Models\StudentPackagePurchase;
use App\Payments\Enums\PaymentReconciliationIssueStatus;
use App\Payments\Enums\PaymentReconciliationIssueType;
use App\Payments\Services\PaymentReconciliationIssueService;
use App\Support\MoneyFormatter;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Read-mostly by design.
 *
 * There is no status select, no inline editing, no delete, and — most
 * deliberately — no "mark paid" action. An operator looking at a
 * discrepancy must not be able to resolve it by asserting the money
 * arrived; only verified provider evidence may settle a payment, and
 * that path runs through the webhook and the reconciliation sweep, not
 * through this screen.
 *
 * "Resolve" therefore closes the OPERATIONAL row only, requires a
 * mandatory note, and is gated behind its own narrow permission. If the
 * provider later confirms payment properly, the issue closes itself and
 * nobody needs to touch this page at all.
 */
class PaymentReconciliationIssuesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('payment.idempotency_key')
                    ->label('Payment Ref')
                    ->fontFamily('mono')
                    ->searchable()
                    ->copyable()
                    ->placeholder('—'),
                TextColumn::make('issue_type')
                    ->label('Issue')
                    ->badge()
                    ->formatStateUsing(fn (PaymentReconciliationIssueType $state): string => $state->label())
                    ->color(fn (PaymentReconciliationIssueType $state): string => $state->color())
                    // Product language under the badge: an operator
                    // triaging money should not have to translate
                    // `settlement_failed` in their head.
                    ->description(fn (PaymentReconciliationIssueType $state): string => $state->description())
                    ->wrap(),
                // The first question in any payment incident: is the
                // money ours, and did the customer get what they paid
                // for? Surfaced as its own column so "provider confirmed,
                // access never granted" cannot read like an ordinary
                // retryable glitch.
                TextColumn::make('money_state')
                    ->label('Money')
                    ->badge()
                    ->state(fn (PaymentReconciliationIssue $record): string => $record->issue_type->moneyState())
                    ->color(fn (PaymentReconciliationIssue $record): string => $record->issue_type->moneyStateColor())
                    ->description(fn (PaymentReconciliationIssue $record): string => $record->issue_type->deliveryState()),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (PaymentReconciliationIssueStatus $state): string => $state->label())
                    ->color(fn (PaymentReconciliationIssueStatus $state): string => $state->color()),
                TextColumn::make('provider')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => ucfirst((string) $state)),
                // Resolved through the payment's payable reference rather
                // than a stored copy — the issue table stays generic and
                // does not grow package-specific columns.
                TextColumn::make('purchase_reference')
                    ->label('Purchase')
                    ->state(fn (PaymentReconciliationIssue $record): string => self::purchaseReference($record))
                    ->fontFamily('mono')
                    ->placeholder('—'),
                TextColumn::make('student')
                    ->label('Student')
                    ->state(fn (PaymentReconciliationIssue $record): string => $record->payment?->user?->name ?? '—'),
                TextColumn::make('expected')
                    ->label('Expected')
                    ->state(fn (PaymentReconciliationIssue $record): string => self::money(
                        $record->expected_amount_minor,
                        $record->expected_currency ?? $record->payment?->currency_code,
                    )),
                TextColumn::make('observed')
                    ->label('Provider Reported')
                    ->state(fn (PaymentReconciliationIssue $record): string => self::money(
                        $record->observed_amount_minor,
                        $record->observed_currency ?? $record->expected_currency ?? $record->payment?->currency_code,
                    ))
                    ->color('danger'),
                TextColumn::make('occurrence_count')
                    ->label('Seen')
                    ->numeric()
                    ->alignRight()
                    ->tooltip('How many times this same discrepancy has been reported.'),
                TextColumn::make('first_seen_at')
                    ->label('First Seen')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('last_seen_at')
                    ->label('Last Seen')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('resolver.name')
                    ->label('Resolved By')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('last_seen_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(PaymentReconciliationIssueStatus::cases())
                        ->mapWithKeys(fn (PaymentReconciliationIssueStatus $s): array => [$s->value => $s->label()])
                        ->all())
                    ->default(PaymentReconciliationIssueStatus::Open->value),
                SelectFilter::make('issue_type')
                    ->label('Issue Type')
                    ->options(collect(PaymentReconciliationIssueType::cases())
                        ->mapWithKeys(fn (PaymentReconciliationIssueType $t): array => [$t->value => $t->label()])
                        ->all()),
                SelectFilter::make('provider')
                    ->options(['razorpay' => 'Razorpay', 'stripe' => 'Stripe']),
            ])
            ->recordActions([
                Action::make('resolve')
                    // "Mark Resolved" closes the OPERATIONAL INCIDENT. It
                    // does not settle the payment, grant access, or move
                    // money — settlement stays evidence-driven. The
                    // wording and confirmation exist so an operator can
                    // never believe otherwise.
                    ->label('Close incident')
                    ->requiresConfirmation()
                    ->modalHeading('Close this incident?')
                    ->modalDescription('This closes the operational incident only. It does not mark the payment as paid, activate the package, or move any money.')
                    ->modalSubmitActionLabel('Close incident')
                    ->icon('heroicon-o-check-circle')
                    ->color('gray')
                    ->visible(fn (PaymentReconciliationIssue $record): bool => auth()->user()?->can('resolve', $record) ?? false)
                    ->schema([
                        Textarea::make('note')
                            ->label('What was done?')
                            ->helperText('This closes the operational record only. It does not mark the payment received or grant any lessons.')
                            ->required()
                            ->maxLength(1000),
                    ])
                    ->action(function (PaymentReconciliationIssue $record, array $data): void {
                        app(PaymentReconciliationIssueService::class)
                            ->resolveManually($record, auth()->user(), (string) $data['note']);

                        Notification::make()
                            ->title('Discrepancy marked resolved')
                            ->body('The payment itself is unchanged — only this operational record was closed.')
                            ->success()
                            ->send();
                    }),
            ])
            ->emptyStateHeading('No payment discrepancies')
            ->emptyStateDescription('Verified provider events have matched every approved amount and currency.');
    }

    private static function money(?int $amountMinor, ?string $currency): string
    {
        if ($amountMinor === null || $currency === null) {
            return '—';
        }

        return MoneyFormatter::format($amountMinor, $currency);
    }

    /** Package purchases are the only payable today; anything else degrades to a dash. */
    private static function purchaseReference(PaymentReconciliationIssue $record): string
    {
        $payment = $record->payment;

        if ($payment === null || $payment->payable_type !== StudentPackagePurchase::PAYABLE_TYPE) {
            return '—';
        }

        return StudentPackagePurchase::query()->whereKey($payment->payable_id)->value('reference') ?? '—';
    }
}
