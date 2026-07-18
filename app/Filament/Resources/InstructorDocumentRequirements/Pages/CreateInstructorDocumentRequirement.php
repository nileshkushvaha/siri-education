<?php

declare(strict_types=1);

namespace App\Filament\Resources\InstructorDocumentRequirements\Pages;

use App\Filament\Resources\InstructorDocumentRequirements\InstructorDocumentRequirementResource;
use App\Models\User;
use App\Services\Instructor\InstructorDocumentRequirementService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateInstructorDocumentRequirement extends CreateRecord
{
    protected static string $resource = InstructorDocumentRequirementResource::class;

    /** Routed through the service so creation is audited (see Part 9). */
    protected function handleRecordCreation(array $data): Model
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        return app(InstructorDocumentRequirementService::class)->create($actor, $data);
    }
}
