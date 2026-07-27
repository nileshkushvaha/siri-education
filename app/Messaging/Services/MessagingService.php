<?php

declare(strict_types=1);

namespace App\Messaging\Services;

use App\Messaging\Enums\ConversationStatus;
use App\Messaging\Enums\MessageReportReason;
use App\Messaging\Enums\MessageReportStatus;
use App\Messaging\Events\MessageReported;
use App\Messaging\Events\MessageSent;
use App\Messaging\Exceptions\MessagingException;
use App\Messaging\Support\LeakageDetector;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageReport;
use App\Models\MessagingRestriction;
use App\Models\User;
use App\Services\AuditTrailService;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * The single authoritative writer of conversations/messages/reports/
 * restrictions (SRS §17.28-§17.36). Controllers, Livewire
 * components, and Filament actions must never write these tables
 * directly. Eligibility is recomputed on every send — never trusted
 * from conversation-open time.
 */
final class MessagingService
{
    private const string LOG_NAME = 'messaging';

    public function __construct(
        private readonly MessagingEligibilityService $eligibility,
        private readonly LeakageDetector $leakage,
        private readonly AuditTrailService $audit,
    ) {}

    public function openOrFindConversation(User $student, User $instructor, User $opener): Conversation
    {
        if (! $this->eligibility->lifecycleEligible($student, $instructor)) {
            throw new MessagingException('Messaging is not available for this instructor or student right now.');
        }

        if ($this->hasActiveRestriction($student->id) || $this->hasActiveRestriction($instructor->id)) {
            throw new MessagingException('Messaging is currently restricted for one of the participants.');
        }

        $context = $this->eligibility->findEligibleContext($student, $instructor);

        if ($context === null) {
            throw new MessagingException('Messaging requires a confirmed paid booking or an active learning relationship.');
        }

        [$contextType, $contextId] = $context;

        $existing = $this->findConversation($student->id, $instructor->id, $contextType, $contextId);

        if ($existing !== null) {
            return $existing;
        }

        try {
            return DB::transaction(function () use ($student, $instructor, $opener, $contextType, $contextId): Conversation {
                $conversation = Conversation::query()->create([
                    'student_id' => $student->id,
                    'instructor_id' => $instructor->id,
                    'context_type' => $contextType,
                    'context_id' => $contextId,
                    'status' => ConversationStatus::Active,
                    'opened_by' => $opener->id,
                ]);

                $this->audit->logUser(
                    $opener,
                    self::LOG_NAME,
                    'conversation_opened',
                    sprintf('Conversation opened between student #%d and instructor #%d.', $student->id, $instructor->id),
                    $conversation,
                    ['context_type' => $contextType, 'context_id' => $contextId],
                );

                return $conversation;
            });
        } catch (QueryException $e) {
            $existing = $this->findConversation($student->id, $instructor->id, $contextType, $contextId);

            if ($existing !== null) {
                return $existing;
            }

            throw $e;
        }
    }

    public function send(Conversation $conversation, User $sender, string $body, ?UploadedFile $attachment = null): Message
    {
        return DB::transaction(function () use ($conversation, $sender, $body, $attachment): Message {
            /** @var Conversation $locked */
            $locked = Conversation::query()->whereKey($conversation->getKey())->lockForUpdate()->firstOrFail();

            $this->assertCanSend($locked, $sender);

            $flags = $this->leakage->detect($body);

            $message = Message::query()->create([
                'conversation_id' => $locked->id,
                'sender_id' => $sender->id,
                'body' => $body,
                'sent_at' => now(),
                'flagged_leakage' => $flags !== [],
                'flagged_leakage_reasons' => $flags,
            ]);

            if ($attachment !== null) {
                $message->addMedia($attachment)->toMediaCollection('attachment');
            }

            $locked->forceFill(['last_message_at' => $message->sent_at])->save();

            if ($flags !== []) {
                $this->audit->logSystem(
                    self::LOG_NAME,
                    'message_flagged_leakage',
                    'A message was flagged by deterministic leakage-prevention policy.',
                    $message,
                    ['flags' => $flags],
                );
            }

            MessageSent::dispatch($message);

            return $message;
        });
    }

    /** @return int number of messages marked read */
    public function markRead(Conversation $conversation, User $reader): int
    {
        if (! $conversation->isParticipant($reader)) {
            throw new MessagingException('You are not a participant in this conversation.');
        }

        return $conversation->messages()
            ->where('sender_id', '!=', $reader->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    public function close(Conversation $conversation, User $actor, ?string $reason = null): Conversation
    {
        if (! $conversation->isParticipant($actor) && ! $this->hasPermission($actor, 'Close:Messaging')) {
            throw new MessagingException('You may not close this conversation.');
        }

        return DB::transaction(function () use ($conversation, $actor, $reason): Conversation {
            /** @var Conversation $locked */
            $locked = Conversation::query()->whereKey($conversation->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === ConversationStatus::Closed) {
                return $locked;
            }

            $locked->forceFill([
                'status' => ConversationStatus::Closed,
                'closed_at' => now(),
                'closed_by' => $actor->id,
            ])->save();

            $this->audit->logUser(
                $actor,
                self::LOG_NAME,
                'conversation_closed',
                'Conversation closed.',
                $locked,
                array_filter(['reason' => $reason]),
            );

            return $locked;
        });
    }

    public function reportMessage(Message $message, User $reporter, MessageReportReason $reason, ?string $details = null): MessageReport
    {
        $conversation = $message->conversation;

        if (! $conversation->isParticipant($reporter)) {
            throw new MessagingException('You may only report messages in your own conversations.');
        }

        $existing = MessageReport::query()
            ->where('message_id', $message->id)
            ->where('reporter_id', $reporter->id)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            return DB::transaction(function () use ($message, $reporter, $reason, $details): MessageReport {
                $report = MessageReport::query()->create([
                    'message_id' => $message->id,
                    'reporter_id' => $reporter->id,
                    'reason' => $reason,
                    'details' => $details,
                    'status' => MessageReportStatus::Pending,
                ]);

                $this->audit->logUser(
                    $reporter,
                    self::LOG_NAME,
                    'message_reported',
                    sprintf('Message reported (%s).', $reason->label()),
                    $message,
                    ['reason' => $reason->value, 'report_id' => $report->id],
                );

                MessageReported::dispatch($report);

                return $report;
            });
        } catch (QueryException $e) {
            $existing = MessageReport::query()
                ->where('message_id', $message->id)
                ->where('reporter_id', $reporter->id)
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            throw $e;
        }
    }

    public function reviewReport(MessageReport $report, User $admin, MessageReportStatus $status, ?string $notes = null): MessageReport
    {
        if (! $this->hasPermission($admin, 'ReviewReport:Messaging')) {
            throw new MessagingException('You may not review message reports.');
        }

        return DB::transaction(function () use ($report, $admin, $status, $notes): MessageReport {
            /** @var MessageReport $locked */
            $locked = MessageReport::query()->whereKey($report->getKey())->lockForUpdate()->firstOrFail();

            $locked->forceFill([
                'status' => $status,
                'reviewed_at' => now(),
                'reviewed_by' => $admin->id,
                'review_notes' => $notes,
            ])->save();

            $this->audit->logUser(
                $admin,
                self::LOG_NAME,
                'report_reviewed',
                sprintf('Message report reviewed: %s.', $status->label()),
                $locked,
                ['status' => $status->value],
            );

            return $locked;
        });
    }

    public function applyRestriction(User $target, User $admin, string $reason): MessagingRestriction
    {
        if (! $this->hasPermission($admin, 'Restrict:Messaging')) {
            throw new MessagingException('You may not restrict messaging access.');
        }

        if (trim($reason) === '') {
            throw new MessagingException('Restricting messaging requires a reason.');
        }

        return DB::transaction(function () use ($target, $admin, $reason): MessagingRestriction {
            $existing = MessagingRestriction::query()
                ->where('user_id', $target->id)
                ->whereNull('lifted_at')
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $restriction = MessagingRestriction::query()->create([
                'user_id' => $target->id,
                'applied_by' => $admin->id,
                'reason' => $reason,
                'applied_at' => now(),
            ]);

            Conversation::query()
                ->where(fn ($q) => $q->where('student_id', $target->id)->orWhere('instructor_id', $target->id))
                ->where('status', ConversationStatus::Active)
                ->update(['status' => ConversationStatus::Restricted]);

            $this->audit->logUser(
                $admin,
                self::LOG_NAME,
                'messaging_restriction_applied',
                sprintf('Messaging restricted for user #%d.', $target->id),
                $restriction,
                ['reason' => $reason],
            );

            return $restriction;
        });
    }

    public function removeRestriction(MessagingRestriction $restriction, User $admin, string $reason): MessagingRestriction
    {
        if (! $this->hasPermission($admin, 'Restrict:Messaging')) {
            throw new MessagingException('You may not remove a messaging restriction.');
        }

        if (trim($reason) === '') {
            throw new MessagingException('Removing a messaging restriction requires a reason.');
        }

        return DB::transaction(function () use ($restriction, $admin, $reason): MessagingRestriction {
            /** @var MessagingRestriction $locked */
            $locked = MessagingRestriction::query()->whereKey($restriction->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->lifted_at !== null) {
                return $locked;
            }

            $locked->forceFill([
                'lifted_at' => now(),
                'lifted_by' => $admin->id,
                'lifted_reason' => $reason,
            ])->save();

            // Only reactivate a conversation if the OTHER participant
            // isn't independently restricted too.
            $conversations = Conversation::query()
                ->where(fn ($q) => $q->where('student_id', $locked->user_id)->orWhere('instructor_id', $locked->user_id))
                ->where('status', ConversationStatus::Restricted)
                ->get();

            foreach ($conversations as $conversation) {
                $otherId = $conversation->student_id === $locked->user_id ? $conversation->instructor_id : $conversation->student_id;

                $otherStillRestricted = MessagingRestriction::query()
                    ->where('user_id', $otherId)
                    ->whereNull('lifted_at')
                    ->exists();

                if (! $otherStillRestricted) {
                    $conversation->forceFill(['status' => ConversationStatus::Active])->save();
                }
            }

            $this->audit->logUser(
                $admin,
                self::LOG_NAME,
                'messaging_restriction_removed',
                sprintf('Messaging restriction removed for user #%d.', $locked->user_id),
                $locked,
                ['reason' => $reason],
            );

            return $locked;
        });
    }

    // ── Internals ─────────────────────────────────────────────────────────

    private function findConversation(int $studentId, int $instructorId, string $contextType, string $contextId): ?Conversation
    {
        return Conversation::query()
            ->where('student_id', $studentId)
            ->where('instructor_id', $instructorId)
            ->where('context_type', $contextType)
            ->where('context_id', $contextId)
            ->first();
    }

    private function assertCanSend(Conversation $conversation, User $sender): void
    {
        if (! $conversation->isParticipant($sender)) {
            throw new MessagingException('You are not a participant in this conversation.');
        }

        if (! $conversation->status->acceptsNewMessages()) {
            throw new MessagingException(sprintf('This conversation is %s and cannot accept new messages.', $conversation->status->label()));
        }

        $student = $conversation->student;
        $instructor = $conversation->instructor;

        if (! $this->eligibility->isEligible($student, $instructor)) {
            throw new MessagingException('Messaging eligibility is no longer met for this conversation.');
        }

        if (! $this->eligibility->contextBelongsToBoth($conversation->context_type, $conversation->context_id, $student, $instructor)) {
            throw new MessagingException('This conversation\'s context no longer applies to both participants.');
        }

        if ($this->hasActiveRestriction($student->id) || $this->hasActiveRestriction($instructor->id)) {
            throw new MessagingException('Messaging is currently restricted for one of the participants.');
        }
    }

    private function hasActiveRestriction(int $userId): bool
    {
        return MessagingRestriction::query()->where('user_id', $userId)->whereNull('lifted_at')->exists();
    }

    private function hasPermission(User $user, string $permission): bool
    {
        try {
            return $user->hasPermissionTo($permission);
        } catch (PermissionDoesNotExist) {
            return false;
        }
    }
}
