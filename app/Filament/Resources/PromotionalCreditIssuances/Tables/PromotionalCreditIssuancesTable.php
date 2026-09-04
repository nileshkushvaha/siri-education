<?php

declare(strict_types=1);

namespace App\Filament\Resources\PromotionalCreditIssuances\Tables;

use App\Filament\Support\AdminDayRange;
use App\Filament\Support\Tables\AdminListTable;
use App\Models\Currency;
use App\Models\PromotionalCreditCampaign;
use App\Models\PromotionalCreditIssuance;
use App\Models\User;
use App\PromotionalCredits\Enums\PromotionalCreditIssuanceType;
use App\PromotionalCredits\Exceptions\PromotionalCreditException;
use App\PromotionalCredits\Services\PromotionalCreditService;
use App\Support\MoneyFormatter;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Bounded, permission-controlled issuance history plus the single
 * "Issue Credit" entry point (requirement #7) — every issuance,
 * campaign-attributed or ad-hoc manual, is created only through
 * PromotionalCreditService::issueCampaignCredit()/issueManualCredit().
 */
class PromotionalCreditIssuancesTable
{
    public static function configure(Table $table): Table
    {
        $table
            ->columns([
                TextColumn::make('issued_at')->dateTime()->sortable(),
                TextColumn::make('student.name')->label('Student')->searchable(),
                TextColumn::make('issuance_type')
                    ->badge()
                    ->formatStateUsing(fn (PromotionalCreditIssuanceType $state): string => $state->label()),
                TextColumn::make('campaign.name')->label('Campaign')->placeholder('—'),
                TextColumn::make('amount_minor')
                    ->label('Amount')
                    ->state(fn (PromotionalCreditIssuance $record): string => MoneyFormatter::format($record->amount_minor, $record->currency_code))
                    ->sortable(),
                TextColumn::make('issuedByUser.name')->label('Issued by'),
            ])
            ->filters([
                SelectFilter::make('student_id')
                    ->label('Student')
                    ->relationship('student', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('campaign_id')
                    ->label('Campaign')
                    ->relationship('campaign', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('issuance_type')
                    ->label('Type')
                    ->options(collect(PromotionalCreditIssuanceType::cases())->mapWithKeys(fn (PromotionalCreditIssuanceType $t) => [$t->value => $t->label()])),
                Filter::make('issued_between')
                    ->form([
                        DatePicker::make('from'),
                        DatePicker::make('until'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'], fn (Builder $q, $date) => $q->where('issued_at', '>=', AdminDayRange::viewerDay($date)->startUtc))
                        ->when($data['until'], fn (Builder $q, $date) => $q->where('issued_at', '<', AdminDayRange::viewerDay($date)->endUtcExclusive))),
            ])
            ->headerActions([
                Action::make('issue_credit')
                    ->label('Issue Credit')
                    ->icon('heroicon-m-banknotes')
                    ->color('primary')
                    ->authorize(fn (): bool => auth()->user()?->can('IssuePromotionalCredit') ?? false)
                    ->form([
                        Select::make('student_id')
                            ->label('Student')
                            ->options(fn (): array => User::query()->whereHas('roles', fn ($q) => $q->where('name', 'student'))->pluck('name', 'id')->toArray())
                            ->searchable()
                            ->preload()
                            ->required(),
                        Select::make('campaign_id')
                            ->label('Campaign (optional)')
                            ->options(fn (): array => PromotionalCreditCampaign::query()->active()->pluck('name', 'id')->toArray())
                            ->helperText('Leave empty for an ad-hoc manual credit. Selecting a campaign uses its configured amount and currency.')
                            ->native(false)
                            ->live(),
                        TextInput::make('amount_minor')
                            ->label('Amount (smallest currency unit)')
                            ->helperText('Enter the amount in the smallest unit of the currency below — e.g. paise for INR, cents for USD.')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->visible(fn (Get $get): bool => blank($get('campaign_id')))
                            ->requiredIf('campaign_id', null),
                        Select::make('currency_code')
                            ->label('Currency')
                            ->options(fn (): array => Currency::query()->active()->pluck('code', 'code')->toArray())
                            ->native(false)
                            ->visible(fn (Get $get): bool => blank($get('campaign_id')))
                            ->requiredIf('campaign_id', null),
                        Textarea::make('reason')
                            ->label('Reason')
                            ->required()
                            ->maxLength(500)
                            ->helperText('Always recorded — mandatory for manual credits, and kept as the audit rationale for campaign credits too.'),
                    ])
                    ->action(function (array $data): void {
                        try {
                            $service = app(PromotionalCreditService::class);
                            $student = User::query()->findOrFail($data['student_id']);
                            $idempotencyKey = 'promo_credit:'.Str::uuid();

                            if (filled($data['campaign_id'] ?? null)) {
                                $campaign = PromotionalCreditCampaign::query()->findOrFail($data['campaign_id']);
                                $service->issueCampaignCredit($campaign, $student, auth()->user(), $data['reason'], $idempotencyKey);
                            } else {
                                $service->issueManualCredit($student, auth()->user(), (int) $data['amount_minor'], $data['currency_code'], $data['reason'], $idempotencyKey);
                            }

                            Notification::make()->title('Promotional credit issued')->success()->send();
                        } catch (PromotionalCreditException $e) {
                            Notification::make()->title('Credit not issued')->body($e->getMessage())->danger()->send();
                        }
                    }),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->defaultSort('issued_at', 'desc');

        return AdminListTable::apply($table);
    }
}
