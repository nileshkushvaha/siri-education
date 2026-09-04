<?php

declare(strict_types=1);

namespace App\Filament\Resources\InstructorPayoutMethods\Tables;

use App\Earnings\Contracts\InstructorPayoutMethodServiceInterface;
use App\Earnings\Contracts\RazorpayXDestinationProvisioningServiceInterface;
use App\Earnings\Enums\PayoutMethodStatus;
use App\Earnings\Enums\PayoutMethodType;
use App\Earnings\Enums\RazorpayXProviderLinkStatus;
use App\Earnings\Exceptions\EarningException;
use App\Earnings\Providers\RazorpayX\RazorpayXProvisioningException;
use App\Filament\Support\AdminDayRange;
use App\Filament\Support\Tables\AdminListTable;
use App\Models\InstructorPayoutDestinationProviderLink;
use App\Models\InstructorPayoutMethod;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;

/**
 * Masked data only — no column here (or anywhere in the panel) renders
 * account numbers, IBANs, or routing data. Decryption happens solely
 * inside the "View details" action's modal render, which requires the
 * dedicated ViewSensitive permission and is audit-logged by the
 * service. Lifecycle mutations route through
 * InstructorPayoutMethodService; there is no edit or delete.
 */
class InstructorPayoutMethodsTable
{
    public static function configure(Table $table): Table
    {
        $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('razorpayXProviderLink'))
            ->columns([
                TextColumn::make('instructor.name')
                    ->label('Instructor')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('type')
                    ->label('Type')
                    ->formatStateUsing(fn (PayoutMethodType $state): string => $state->label()),
                TextColumn::make('masked_identifier')
                    ->label('Destination'),
                TextColumn::make('country.name')
                    ->label('Country')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('currency_code')
                    ->label('Currency'),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (PayoutMethodStatus $state): string => $state->label())
                    ->color(fn (PayoutMethodStatus $state): string => $state->color()),
                IconColumn::make('is_default')
                    ->label('Default')
                    ->boolean(),
                TextColumn::make('submitted_at')
                    ->label('Submitted')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('verified_at')
                    ->label('Verified')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('verifier.name')
                    ->label('Verified By')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('rejection_reason')
                    ->label('Rejection Reason')
                    ->limit(40)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('disabled_at')
                    ->label('Disabled')
                    ->dateTime()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('razorpayx_status')
                    ->label('RazorpayX')
                    ->badge()
                    ->state(fn (InstructorPayoutMethod $record): string => ($record->razorpayXProviderLink?->status ?? null)?->label() ?? 'Not provisioned')
                    ->color(fn (InstructorPayoutMethod $record): string => $record->razorpayXProviderLink?->status?->color() ?? 'gray')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(PayoutMethodStatus::cases())
                        ->mapWithKeys(fn (PayoutMethodStatus $s) => [$s->value => $s->label()])
                        ->toArray()),
                SelectFilter::make('type')
                    ->options(collect(PayoutMethodType::cases())
                        ->mapWithKeys(fn (PayoutMethodType $t) => [$t->value => $t->label()])
                        ->toArray()),
                SelectFilter::make('country_id')
                    ->label('Country')
                    ->relationship('country', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('currency_code')
                    ->label('Currency')
                    ->options(fn (): array => InstructorPayoutMethod::query()
                        ->distinct()
                        ->pluck('currency_code', 'currency_code')
                        ->toArray()),
                TernaryFilter::make('is_default')
                    ->label('Default method'),
                Filter::make('submitted_between')
                    ->form([
                        DatePicker::make('from'),
                        DatePicker::make('until'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'], fn (Builder $q, $date) => $q->where('submitted_at', '>=', AdminDayRange::viewerDay($date)->startUtc))
                        ->when($data['until'], fn (Builder $q, $date) => $q->where('submitted_at', '<', AdminDayRange::viewerDay($date)->endUtcExclusive))),
            ])
            ->recordActions([
                Action::make('view_sensitive')
                    ->label('View Details')
                    ->icon('heroicon-m-eye')
                    ->color('gray')
                    ->authorize(fn (InstructorPayoutMethod $record): bool => auth()->user()?->can('viewSensitive', $record) ?? false)
                    ->modalHeading('Sensitive payout details')
                    ->modalDescription('This access is recorded in the audit trail.')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    // Decrypted only at modal render, inside the service
                    // (permission re-check + access audit log); nothing is
                    // serialized into the table or the URL.
                    ->modalContent(fn (InstructorPayoutMethod $record): View => view('filament.payouts.sensitive-details', [
                        'details' => app(InstructorPayoutMethodServiceInterface::class)
                            ->viewSensitiveDetails($record, auth()->user()),
                    ])),

                Action::make('verify')
                    ->icon('heroicon-m-check-badge')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription('Confirm the bank details were checked. The method becomes usable for withdrawals and its details become immutable.')
                    ->authorize(fn (InstructorPayoutMethod $record): bool => auth()->user()?->can('verify', $record) ?? false)
                    ->visible(fn (InstructorPayoutMethod $record): bool => $record->status === PayoutMethodStatus::PendingVerification)
                    ->action(fn (InstructorPayoutMethod $record) => self::callService(
                        fn (InstructorPayoutMethodServiceInterface $service) => $service->verify($record, auth()->user()),
                        'Payout method verified',
                    )),

                Action::make('reject')
                    ->icon('heroicon-m-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->authorize(fn (InstructorPayoutMethod $record): bool => auth()->user()?->can('reject', $record) ?? false)
                    ->visible(fn (InstructorPayoutMethod $record): bool => $record->status === PayoutMethodStatus::PendingVerification)
                    ->form([
                        Textarea::make('reason')
                            ->required()
                            ->maxLength(1000)
                            ->helperText('Shown to the instructor — keep it actionable and free of account data.'),
                    ])
                    ->action(fn (InstructorPayoutMethod $record, array $data) => self::callService(
                        fn (InstructorPayoutMethodServiceInterface $service) => $service->reject($record, auth()->user(), $data['reason']),
                        'Payout method rejected',
                    )),

                Action::make('disable')
                    ->icon('heroicon-m-no-symbol')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('The method can no longer be used for new withdrawals. Existing withdrawal snapshots are unaffected.')
                    ->authorize(fn (InstructorPayoutMethod $record): bool => auth()->user()?->can('disable', $record) ?? false)
                    ->visible(fn (InstructorPayoutMethod $record): bool => $record->status->canTransitionTo(PayoutMethodStatus::Disabled))
                    ->form([
                        Textarea::make('reason')->maxLength(1000),
                    ])
                    ->action(fn (InstructorPayoutMethod $record, array $data) => self::callService(
                        fn (InstructorPayoutMethodServiceInterface $service) => $service->disable($record, auth()->user(), $data['reason'] ?? null),
                        'Payout method disabled',
                    )),

                // RazorpayX Contact/Fund Account provisioning. Deliberately
                // separate from the payout-method lifecycle
                // actions above: provisioning failure never blocks
                // verification, and verification never auto-provisions.
                Action::make('razorpayx_provision')
                    ->label('Provision (RazorpayX)')
                    ->icon('heroicon-m-link')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalDescription('Creates (or reuses) the RazorpayX Contact and Fund Account for this destination.')
                    ->authorize(fn (): bool => auth()->user()?->can('ProvisionDestination:RazorpayXPayout') ?? false)
                    ->visible(fn (InstructorPayoutMethod $record): bool => $record->razorpayXProviderLink === null
                        && $record->status === PayoutMethodStatus::Verified
                        && $record->currency_code === 'INR')
                    ->action(fn (InstructorPayoutMethod $record) => self::callProvisioning(
                        fn (RazorpayXDestinationProvisioningServiceInterface $service) => $service->provision($record, auth()->user()),
                        'RazorpayX provisioning attempted',
                    )),

                Action::make('razorpayx_refresh')
                    ->label('Refresh (RazorpayX)')
                    ->icon('heroicon-m-arrow-path')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalDescription('Re-attempts provisioning from wherever it last stopped. Never creates a duplicate Contact or Fund Account.')
                    ->authorize(fn (): bool => auth()->user()?->can('RefreshDestination:RazorpayXPayout') ?? false)
                    ->visible(fn (InstructorPayoutMethod $record): bool => $record->razorpayXProviderLink !== null
                        && ! in_array($record->razorpayXProviderLink->status, [RazorpayXProviderLinkStatus::Ready, RazorpayXProviderLinkStatus::Disabled, RazorpayXProviderLinkStatus::Stale, RazorpayXProviderLinkStatus::Failed], true))
                    ->action(fn (InstructorPayoutMethod $record) => self::callProvisioning(
                        fn (RazorpayXDestinationProvisioningServiceInterface $service) => $service->refresh($record->razorpayXProviderLink, auth()->user()),
                        'RazorpayX provisioning refreshed',
                    )),

                Action::make('razorpayx_mark_stale')
                    ->label('Mark Stale (RazorpayX)')
                    ->icon('heroicon-m-exclamation-triangle')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->authorize(fn (): bool => auth()->user()?->can('Reconcile:RazorpayXPayout') ?? false)
                    ->visible(fn (InstructorPayoutMethod $record): bool => $record->razorpayXProviderLink?->status === RazorpayXProviderLinkStatus::Ready)
                    ->form([
                        Textarea::make('reason')
                            ->required()
                            ->maxLength(1000)
                            ->helperText('Mandatory — recorded on the audit trail.'),
                    ])
                    ->action(fn (InstructorPayoutMethod $record, array $data) => self::callProvisioning(
                        fn (RazorpayXDestinationProvisioningServiceInterface $service) => $service->markStale($record->razorpayXProviderLink, auth()->user(), $data['reason']),
                        'RazorpayX provider link marked stale',
                    )),

                Action::make('razorpayx_disable_link')
                    ->label('Disable Link (RazorpayX)')
                    ->icon('heroicon-m-no-symbol')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('This destination can no longer be used for a RazorpayX payout. The Contact and Fund Account at RazorpayX are left untouched.')
                    ->authorize(fn (): bool => auth()->user()?->can('Configure:RazorpayXPayout') ?? false)
                    ->visible(fn (InstructorPayoutMethod $record): bool => $record->razorpayXProviderLink !== null
                        && $record->razorpayXProviderLink->status->canTransitionTo(RazorpayXProviderLinkStatus::Disabled))
                    ->action(fn (InstructorPayoutMethod $record) => self::callProvisioning(
                        fn (RazorpayXDestinationProvisioningServiceInterface $service) => $service->disable($record->razorpayXProviderLink, auth()->user()),
                        'RazorpayX provider link disabled',
                    )),
            ])
            ->defaultSort('created_at', 'desc');

        return AdminListTable::apply($table);
    }

    private static function callService(callable $callback, string $successTitle): void
    {
        try {
            $callback(app(InstructorPayoutMethodServiceInterface::class));
            Notification::make()->title($successTitle)->success()->send();
        } catch (EarningException $e) {
            Notification::make()->title('Action failed')->body($e->getMessage())->danger()->send();
        }
    }

    private static function callProvisioning(callable $callback, string $successTitle): void
    {
        try {
            $link = $callback(app(RazorpayXDestinationProvisioningServiceInterface::class));
            $status = $link instanceof InstructorPayoutDestinationProviderLink ? $link->status : null;
            $notification = Notification::make()->title($successTitle)->body($status !== null ? "Current state: {$status->label()}." : null);

            match ($status?->color()) {
                'success' => $notification->success(),
                'danger' => $notification->danger(),
                default => $notification->warning(),
            };

            $notification->send();
        } catch (RazorpayXProvisioningException $e) {
            Notification::make()->title('RazorpayX action failed')->body($e->getMessage())->danger()->send();
        }
    }
}
