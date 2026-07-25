<?php

declare(strict_types=1);

namespace App\Livewire\Frontend\Messaging;

use App\Models\Conversation;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Read-only — the participant's own conversations only (mirrors
 * SupportCaseList's ownership-scoped query pattern). Unread count is
 * "messages sent by the other participant that I haven't read yet."
 */
final class ConversationList extends Component
{
    use WithPagination;

    public function render(): View
    {
        $userId = auth()->id();

        return view('livewire.frontend.messaging.conversation-list', [
            'conversations' => Conversation::query()
                ->where(fn ($q) => $q->where('student_id', $userId)->orWhere('instructor_id', $userId))
                ->withCount(['messages as unread_count' => fn ($q) => $q->where('sender_id', '!=', $userId)->whereNull('read_at')])
                ->with(['student', 'instructor'])
                ->latest('last_message_at')
                ->paginate(10),
        ]);
    }
}
