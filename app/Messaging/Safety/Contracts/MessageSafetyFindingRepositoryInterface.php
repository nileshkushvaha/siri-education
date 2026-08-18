<?php

declare(strict_types=1);

namespace App\Messaging\Safety\Contracts;

use App\Messaging\Safety\Enums\MessageSafetySource;
use App\Models\Message;
use App\Models\MessageSafetyFinding;

interface MessageSafetyFindingRepositoryInterface
{
    /** @param array<string, mixed> $attributes */
    public function upsertForSource(Message $message, MessageSafetySource $source, array $attributes): MessageSafetyFinding;

    /** @param array<string, mixed> $attributes */
    public function update(MessageSafetyFinding $finding, array $attributes): MessageSafetyFinding;

    public function find(string $id): ?MessageSafetyFinding;

    public function findForMessageAndSource(Message $message, MessageSafetySource $source): ?MessageSafetyFinding;

    /** Confirmed findings for one sender since a cutoff — the escalation rule's only query. */
    public function countConfirmedForSenderSince(int $senderId, \DateTimeInterface $since): int;
}
