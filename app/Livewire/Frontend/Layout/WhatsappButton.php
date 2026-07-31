<?php

declare(strict_types=1);

namespace App\Livewire\Frontend\Layout;

use App\Services\WhatsApp\WhatsAppUrlBuilder;
use App\Settings\WhatsAppSettings;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class WhatsappButton extends Component
{
    public ?string $url = null;

    public bool $showOnDesktop = false;

    public bool $showOnMobile = false;

    public function mount(WhatsAppUrlBuilder $builder, WhatsAppSettings $settings): void
    {
        $this->url = $builder->url(request()->path());
        $this->showOnDesktop = $settings->desktop_visible;
        $this->showOnMobile = $settings->mobile_visible;
    }

    public function render(): View
    {
        return view('livewire.frontend.layout.whatsapp-button');
    }
}
