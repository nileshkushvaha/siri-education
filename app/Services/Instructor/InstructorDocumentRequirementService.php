<?php

declare(strict_types=1);

namespace App\Services\Instructor;

use App\Models\InstructorDocumentRequirement;
use App\Models\User;
use App\Services\AuditTrailService;
use Illuminate\Support\Collection;

/**
 * Single authority for instructor KYC document requirements (Phase
 * 23G) — replaces the hardcoded
 * InstructorOnboardingService::REQUIRED_DOCUMENT_COLLECTIONS constant.
 * InstructorOnboardingService and OnboardingWizard consume this
 * service's read methods only; they never query
 * InstructorDocumentRequirement directly.
 */
final class InstructorDocumentRequirementService
{
    public function __construct(
        private readonly AuditTrailService $auditTrail,
    ) {}

    /** @return Collection<int, InstructorDocumentRequirement> */
    public function activeRequirements(): Collection
    {
        return InstructorDocumentRequirement::query()
            ->active()
            ->orderBy('sort_order')
            ->orderBy('label')
            ->get();
    }

    /** @return list<string> */
    public function requiredCollections(): array
    {
        return $this->activeRequirements()
            ->where('required', true)
            ->pluck('collection_name')
            ->values()
            ->all();
    }

    /** @return list<string> */
    public function optionalCollections(): array
    {
        return $this->activeRequirements()
            ->where('required', false)
            ->pluck('collection_name')
            ->values()
            ->all();
    }

    /** Every collection name an upload is currently allowed against — required and optional, active only. */
    public function activeCollectionNames(): array
    {
        return $this->activeRequirements()
            ->pluck('collection_name')
            ->values()
            ->all();
    }

    public function findActiveByCollection(string $collectionName): ?InstructorDocumentRequirement
    {
        return $this->activeRequirements()->firstWhere('collection_name', $collectionName);
    }

    /** @return list<string> */
    public function acceptedMimeTypesFor(string $collectionName): array
    {
        return $this->findActiveByCollection($collectionName)?->accepted_mime_types ?? [];
    }

    public function maxSizeKbFor(string $collectionName): ?int
    {
        return $this->findActiveByCollection($collectionName)?->max_size_kb;
    }

    public function create(User $actor, array $data): InstructorDocumentRequirement
    {
        $requirement = InstructorDocumentRequirement::query()->create([
            ...$data,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);

        $this->auditTrail->logUser(
            $actor,
            'instructor',
            'instructor_document_requirement_created',
            'Instructor document requirement created',
            $requirement,
            [
                'requirement_id' => $requirement->id,
                'collection' => $requirement->collection_name,
            ],
        );

        return $requirement;
    }

    /**
     * Single write path for every field, including `active` — a
     * transition from active to inactive is logged as the more specific
     * `instructor_document_requirement_disabled` event; every other
     * change (including reactivating) logs `..._updated`.
     */
    public function update(User $actor, InstructorDocumentRequirement $requirement, array $data): InstructorDocumentRequirement
    {
        $trackedFields = ['label', 'description', 'required', 'accepted_mime_types', 'max_size_kb', 'active', 'sort_order'];
        $oldValues = $requirement->only($trackedFields);
        $wasActive = (bool) $requirement->active;

        $requirement->fill($data);
        $requirement->updated_by = $actor->id;
        $requirement->save();

        $newValues = $requirement->only($trackedFields);
        $isNowInactive = $wasActive && ! $requirement->active;

        $this->auditTrail->logUser(
            $actor,
            'instructor',
            $isNowInactive ? 'instructor_document_requirement_disabled' : 'instructor_document_requirement_updated',
            $isNowInactive ? 'Instructor document requirement disabled' : 'Instructor document requirement updated',
            $requirement,
            [
                'requirement_id' => $requirement->id,
                'collection' => $requirement->collection_name,
                'old_values' => $oldValues,
                'new_values' => $newValues,
            ],
        );

        return $requirement->fresh();
    }
}
