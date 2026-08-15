<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Filament\Widgets\RelatedResourceLinksWidget;
use Filament\Widgets\WidgetConfiguration;

trait HasRelatedResourceLinks
{
    /**
     * @return array<int, array{label: string, url: string}>
     */
    abstract protected function getRelatedResourceLinks(): array;

    /**
     * @return array<class-string | WidgetConfiguration>
     */
    protected function getHeaderWidgets(): array
    {
        $links = $this->getRelatedResourceLinks();

        if ($links === []) {
            return [];
        }

        return [
            RelatedResourceLinksWidget::make([
                'links' => $links,
                'activePath' => $this->getRelatedResourceActivePath(),
            ]),
        ];
    }

    private function getRelatedResourceActivePath(): string
    {
        if (method_exists(static::class, 'getResource')) {
            $resource = static::getResource();
            $url = $resource::getUrl();
        } else {
            $url = static::getUrl();
        }

        return '/'.trim((string) parse_url($url, PHP_URL_PATH), '/');
    }
}
