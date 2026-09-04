<?php

declare(strict_types=1);

namespace App\Filament\Resources\InstructorSettlementBatches\Pages;

use App\Earnings\Contracts\InstructorEarningServiceInterface;
use App\Earnings\Enums\SettlementBatchStatus;
use App\Earnings\Exceptions\EarningException;
use App\Filament\Concerns\HasRelatedResourceLinks;
use App\Filament\Resources\InstructorEarnings\InstructorEarningResource;
use App\Filament\Resources\InstructorSettlementBatches\InstructorSettlementBatchResource;
use App\Filament\Support\Presentation\BackAction;
use App\Filament\Support\RelatedResourceLinkGroups;
use App\Filament\Support\Tables\StatusTabs;
use App\Models\InstructorEarning;
use App\Models\InstructorSettlementBatch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;

class ListInstructorSettlementBatches extends ListRecords
{
    use HasRelatedResourceLinks;

    protected static string $resource = InstructorSettlementBatchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...array_filter([
                BackAction::toResourceIndex(InstructorEarningResource::class, 'Back to Instructor Earnings'),
            ]),
            Action::make('create_batch')
                ->label('New Settlement Batch')
                ->icon('heroicon-m-plus')
                ->authorize(fn (): bool => auth()->user()?->can('create', InstructorSettlementBatch::class) ?? false)
                ->form([
                    // Only instructors who actually have releasable, unassigned
                    // earnings are offered — an empty batch is impossible to draft.
                    Select::make('instructor_id')
                        ->label('Instructor')
                        ->options(fn (): array => User::query()
                            ->whereIn('id', InstructorEarning::query()->settleable()->select('instructor_id'))
                            ->pluck('name', 'id')
                            ->toArray())
                        ->required()
                        ->live()
                        ->searchable()
                        ->native(false),
                    Select::make('currency_code')
                        ->label('Currency')
                        ->options(fn (): array => InstructorEarning::query()
                            ->settleable()
                            ->distinct()
                            ->pluck('currency_code', 'currency_code')
                            ->toArray())
                        ->required()
                        ->native(false),
                    DatePicker::make('period_start')->label('Period start (optional)'),
                    DatePicker::make('period_end')->label('Period end (optional)'),
                    Textarea::make('notes')->maxLength(1000),
                ])
                ->action(function (array $data): void {
                    try {
                        $batch = app(InstructorEarningServiceInterface::class)->createSettlementBatch(
                            instructorId: (int) $data['instructor_id'],
                            currencyCode: $data['currency_code'],
                            periodStart: $data['period_start'] ? CarbonImmutable::parse($data['period_start'])->startOfDay() : null,
                            periodEnd: $data['period_end'] ? CarbonImmutable::parse($data['period_end'])->endOfDay() : null,
                            actor: auth()->user(),
                            notes: $data['notes'] ?? null,
                        );

                        Notification::make()
                            ->title(sprintf('Batch %s drafted', $batch->batch_reference))
                            ->success()
                            ->send();
                    } catch (EarningException $e) {
                        Notification::make()->title('Batch not created')->body($e->getMessage())->danger()->send();
                    }
                }),
        ];
    }

    protected function getRelatedResourceLinks(): array
    {
        return RelatedResourceLinkGroups::instructorFinance();
    }

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        return StatusTabs::forEnum(InstructorSettlementBatch::class, SettlementBatchStatus::class);
    }
}
