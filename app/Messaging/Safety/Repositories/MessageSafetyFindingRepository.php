<?php

declare(strict_types=1);

namespace App\Messaging\Safety\Repositories;

use App\Messaging\Safety\Contracts\MessageSafetyFindingRepositoryInterface;
use App\Messaging\Safety\Enums\MessageSafetyFindingStatus;
use App\Messaging\Safety\Enums\MessageSafetySource;
use App\Models\Message;
use App\Models\MessageSafetyFinding;

final class MessageSafetyFindingRepository implements MessageSafetyFindingRepositoryInterface
{
    public function upsertForSource(Message $message, MessageSafetySource $source, array $attributes): MessageSafetyFinding
    {
        return MessageSafetyFinding::query()->updateOrCreate(
            ['message_id' => $message->getKey(), 'source_type' => $source],
            [...$attributes, 'sender_id' => $message->sender_id],
        );
    }

    public function update(MessageSafetyFinding $finding, array $attributes): MessageSafetyFinding
    {
        $finding->fill($attributes)->save();

        return $finding;
    }

    public function find(string $id): ?MessageSafetyFinding
    {
        return MessageSafetyFinding::query()->find($id);
    }

    public function findForMessageAndSource(Message $message, MessageSafetySource $source): ?MessageSafetyFinding
    {
        return MessageSafetyFinding::query()
            ->where('message_id', $message->getKey())
            ->where('source_type', $source)
            ->first();
    }

    public function countConfirmedForSenderSince(int $senderId, \DateTimeInterface $since): int
    {
        return MessageSafetyFinding::query()
            ->where('sender_id', $senderId)
            ->where('status', MessageSafetyFindingStatus::Confirmed)
            ->where('created_at', '>=', $since)
            ->count();
    }
}
