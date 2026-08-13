<?php

declare(strict_types=1);

namespace App\Filament\Resources\PackageBenefitRules\Pages;

use App\Filament\Resources\PackageBenefitRules\PackageBenefitRuleResource;
use App\Models\PackageBenefitRule;
use App\Package\Exceptions\PackageException;
use App\Package\Services\PackageBenefitRuleService;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditPackageBenefitRule extends EditRecord
{
    protected static string $resource = PackageBenefitRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            RestoreAction::make(),
        ];
    }

    /** @param  PackageBenefitRule  $record */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            return app(PackageBenefitRuleService::class)->update(auth()->user(), $record, $data);
        } catch (PackageException|ValidationException $e) {
            Notification::make()->title('Package offer not updated')->body($e->getMessage())->danger()->send();

            throw new Halt;
        }
    }
}
