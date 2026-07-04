<?php

declare(strict_types=1);

namespace App\Livewire\Frontend\Layout;

use Illuminate\Contracts\View\View;
use Livewire\Component;

class AnnouncementBar extends Component
{
    public bool $hidden = false;

    public bool $enabled = false;

    public ?string $message = null;

    public ?string $url = null;

    public ?string $actionLabel = null;

    public function mount(): void
    {
        $this->enabled = (bool) config('frontend.announcement.enabled', false);
        $this->message = config('frontend.announcement.message');
        $this->url = config('frontend.announcement.url');
        $this->actionLabel = config('frontend.announcement.action_label');
    }

    public function dismiss(): void
    {
        $this->hidden = true;
    }

    public function render(): View
    {
        return view('livewire.frontend.layout.announcement-bar');
    }
}
