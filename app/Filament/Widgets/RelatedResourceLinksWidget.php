<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use Filament\Widgets\Widget;

final class RelatedResourceLinksWidget extends Widget
{
    // Filament lazy-loads widgets by default. This one renders a handful of static
    // links passed in as properties — there is nothing to defer, and the lazy
    // placeholder round trip is what exposed the root-element issue in the view.
    protected static bool $isLazy = false;

    protected string $view = 'filament.widgets.related-resource-links';

    protected int|string|array $columnSpan = 'full';

    /** @var array<int, array{label: string, url: string}> */
    public array $links = [];

    public string $activePath = '';
}
