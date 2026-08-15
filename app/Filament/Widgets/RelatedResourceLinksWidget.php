<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use Filament\Widgets\Widget;

final class RelatedResourceLinksWidget extends Widget
{
    protected string $view = 'filament.widgets.related-resource-links';

    protected int|string|array $columnSpan = 'full';

    /** @var array<int, array{label: string, url: string}> */
    public array $links = [];

    public string $activePath = '';
}
