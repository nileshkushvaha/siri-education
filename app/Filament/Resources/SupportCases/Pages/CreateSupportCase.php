<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupportCases\Pages;

use App\Filament\Resources\SupportCases\SupportCaseResource;
use App\Models\SupportCase;
use App\Models\User;
use App\SupportCases\DTOs\CreateSupportCaseData;
use App\SupportCases\Enums\SupportCaseCategory;
use App\SupportCases\Enums\SupportCasePriority;
use App\SupportCases\Enums\SupportCaseType;
use App\SupportCases\Services\SupportCaseService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Admin-operational case creation only (§25.16). Never writes the
 * model directly — delegates to SupportCaseService::create() so
 * numbering, linked-record authorization, audit logging, and the
 * created event all run exactly as they do for every other creation
 * path.
 */
class CreateSupportCase extends CreateRecord
{
    protected static string $resource = SupportCaseResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return app(SupportCaseService::class)->create(new CreateSupportCaseData(
            creator: auth()->user(),
            type: SupportCaseType::AdminOperational,
            category: SupportCaseCategory::from($data['category']),
            priority: SupportCasePriority::from($data['priority']),
            subject: $data['subject'],
            description: $data['description'],
            student: isset($data['student_id']) ? User::query()->find($data['student_id']) : null,
            instructor: isset($data['instructor_id']) ? User::query()->find($data['instructor_id']) : null,
            linkedRecordType: $data['linked_record_type'] ?? null,
            linkedRecordId: $data['linked_record_id'] ?? null,
            skipLinkedRecordOwnershipCheck: true,
        ));
    }

    protected function getRedirectUrl(): string
    {
        /** @var SupportCase $record */
        $record = $this->getRecord();

        return SupportCaseResource::getUrl('view', ['record' => $record]);
    }
}
