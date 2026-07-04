<?php

declare(strict_types=1);

namespace App\Livewire\Frontend\Layout;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cookie;
use Livewire\Component;

class CookieBanner extends Component
{
    public bool $visible = false;

    public function mount(): void
    {
        $this->visible = request()->cookie('enterprise_cookie_consent') !== 'accepted';
    }

    public function accept(): void
    {
        Cookie::queue(cookie('enterprise_cookie_consent', 'accepted', 60 * 24 * 365));
        $this->visible = false;
    }

    public function dismiss(): void
    {
        $this->visible = false;
    }

    public function render(): View
    {
        return view('livewire.frontend.layout.cookie-banner');
    }
}
