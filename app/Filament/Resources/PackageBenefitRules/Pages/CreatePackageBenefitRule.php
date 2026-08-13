<?php

declare(strict_types=1);

namespace App\Filament\Resources\PackageBenefitRules\Pages;

use App\Filament\Resources\PackageBenefitRules\PackageBenefitRuleResource;
use App\Package\Exceptions\PackageException;
use App\Package\Services\PackageBenefitRuleService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreatePackageBenefitRule extends CreateRecord
{
    protected static string $resource = PackageBenefitRuleResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(PackageBenefitRuleService::class)->create(auth()->user(), $data);
        } catch (PackageException|ValidationException $e) {
            Notification::make()->title('Package offer not created')->body($e->getMessage())->danger()->send();

            throw new Halt;
        }
    }
}
