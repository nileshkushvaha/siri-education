<?php

declare(strict_types=1);

namespace App\Livewire\Frontend\Student;

use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;

final class NotificationsPanel extends Component
{
    use WithPagination;

    public function markRead(string $id): void
    {
        auth()->user()->notifications()->find($id)?->markAsRead();
    }

    public function markAllRead(): void
    {
        auth()->user()->unreadNotifications->markAsRead();
    }

    public function render(): View
    {
        $user = auth()->user();

        return view('livewire.frontend.student.notifications-panel', [
            'notifications' => $user->notifications()->paginate(20),
            'unreadCount' => $user->unreadNotifications()->count(),
        ]);
    }
}
