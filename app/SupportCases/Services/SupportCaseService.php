<?php

declare(strict_types=1);

namespace App\SupportCases\Services;

use App\Models\SupportCase;
use App\Models\SupportCaseReply;
use App\Models\User;
use App\Services\AuditTrailService;
use App\SupportCases\Actions\TransitionSupportCaseStatusAction;
use App\SupportCases\DTOs\CreateSupportCaseData;
use App\SupportCases\Enums\SupportCaseReplyVisibility;
use App\SupportCases\Enums\SupportCaseResolutionType;
use App\SupportCases\Enums\SupportCaseStatus;
use App\SupportCases\Events\SupportCaseAssigned;
use App\SupportCases\Events\SupportCaseCreated;
use App\SupportCases\Events\SupportCaseReplyAdded;
use App\SupportCases\Exceptions\InvalidSupportCaseTransitionException;
use App\SupportCases\Support\LinkedRecordAuthorizer;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * The single authoritative writer of support cases and replies
 * (SRS Chapter 25). Controllers, Livewire components, and
 * Filament actions must never write `support_cases`/
 * `support_case_replies` directly — every mutation, including status
 * transitions, routes through here so audit logging, notifications,
 * and the linked-record authorization boundary run exactly once, in
 * exactly one place.
 */
final class SupportCaseService
{
    public function __construct(
        private readonly SupportCaseNumberAllocator $numbers,
        private readonly LinkedRecordAuthorizer $linkedRecords,
        private readonly TransitionSupportCaseStatusAction $transition,
        private readonly AuditTrailService $audit,
    ) {}

    public function create(CreateSupportCaseData $data): SupportCase
    {
        [$linkedType, $linkedId] = $this->linkedRecords->authorize(
            $data->creator,
            $data->linkedRecordType,
            $data->linkedRecordId,
            $data->skipLinkedRecordOwnershipCheck,
        ) ?? [null, null];

        return DB::transaction(function () use ($data, $linkedType, $linkedId): SupportCase {
            $case = SupportCase::query()->create([
                'case_number' => $this->numbers->allocate(),
                'type' => $data->type,
                'category' => $data->category,
                'priority' => $data->priority,
                'status' => SupportCaseStatus::Open,
                'created_by' => $data->creator->id,
                'student_id' => $data->student?->id,
                'instructor_id' => $data->instructor?->id,
                'linked_record_type' => $linkedType,
                'linked_record_id' => $linkedId,
                'subject' => $data->subject,
                'description' => $data->description,
                'opened_at' => now(),
            ]);

            $this->audit->logUser(
                $data->creator,
                'support_cases',
                'case_created',
                sprintf('Support case %s created (%s / %s).', $case->case_number, $data->type->label(), $data->category->label()),
                $case,
                ['priority' => $data->priority->value],
            );

            SupportCaseCreated::dispatch($case);

            return $case;
        });
    }

    public function assign(SupportCase $case, User $actor, User $assignee): SupportCase
    {
        return DB::transaction(function () use ($case, $actor, $assignee): SupportCase {
            /** @var SupportCase $locked */
            $locked = SupportCase::query()->whereKey($case->getKey())->lockForUpdate()->firstOrFail();

            $isReassignment = $locked->assigned_to !== null;

            $locked->forceFill([
                'assigned_to' => $assignee->id,
                'assigned_at' => now(),
            ])->save();

            $this->audit->logUser(
                $actor,
                'support_cases',
                $isReassignment ? 'case_reassigned' : 'case_assigned',
                sprintf('Support case %s assigned to %s.', $locked->case_number, $assignee->name),
                $locked,
                ['assignee_id' => $assignee->id],
            );

            if ($locked->status === SupportCaseStatus::Open) {
                $locked = $this->transition->execute($locked, SupportCaseStatus::InProgress, $actor);
            }

            SupportCaseAssigned::dispatch($locked, $assignee, $actor, $isReassignment);

            return $locked;
        });
    }

    public function addReply(SupportCase $case, User $author, string $body, SupportCaseReplyVisibility $visibility): SupportCaseReply
    {
        if ($visibility === SupportCaseReplyVisibility::InternalNote && ! $this->canAddInternalNotes($author)) {
            throw new InvalidSupportCaseTransitionException('You are not authorized to add internal notes.');
        }

        return DB::transaction(function () use ($case, $author, $body, $visibility): SupportCaseReply {
            $reply = SupportCaseReply::query()->create([
                'support_case_id' => $case->id,
                'author_id' => $author->id,
                'visibility' => $visibility,
                'body' => $body,
            ]);

            $this->audit->logUser(
                $author,
                'support_cases',
                $visibility === SupportCaseReplyVisibility::InternalNote ? 'internal_note_added' : 'public_reply_added',
                sprintf('%s added to support case %s.', $visibility->label(), $case->case_number),
                $case,
            );

            SupportCaseReplyAdded::dispatch($reply);

            return $reply;
        });
    }

    public function escalate(SupportCase $case, User $actor, string $reason): SupportCase
    {
        return $this->transition->execute($case, SupportCaseStatus::Escalated, $actor, reason: $reason);
    }

    public function markWaitingForUser(SupportCase $case, User $actor): SupportCase
    {
        return $this->transition->execute($case, SupportCaseStatus::WaitingForUser, $actor);
    }

    public function markInProgress(SupportCase $case, User $actor): SupportCase
    {
        return $this->transition->execute($case, SupportCaseStatus::InProgress, $actor);
    }

    public function resolve(SupportCase $case, User $actor, SupportCaseResolutionType $type, string $summary): SupportCase
    {
        return $this->transition->execute($case, SupportCaseStatus::Resolved, $actor, resolutionType: $type, resolutionSummary: $summary);
    }

    public function close(SupportCase $case, User $actor): SupportCase
    {
        return $this->transition->execute($case, SupportCaseStatus::Closed, $actor);
    }

    /** SRS §25.33/§25.40 "Case Reopen — the system shall allow reopening of cases where permitted." */
    public function reopen(SupportCase $case, User $actor, ?string $reason = null): SupportCase
    {
        if (! $case->status->isReopenable()) {
            throw new InvalidSupportCaseTransitionException(sprintf(
                'Support case %s cannot be reopened from status %s.',
                $case->case_number,
                $case->status->label(),
            ));
        }

        return $this->transition->execute($case, SupportCaseStatus::Open, $actor, reason: $reason);
    }

    private function canAddInternalNotes(User $user): bool
    {
        try {
            return $user->hasPermissionTo('AddInternalNote:SupportCase');
        } catch (PermissionDoesNotExist) {
            return false;
        }
    }
}
