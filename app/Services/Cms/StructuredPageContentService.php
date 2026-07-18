<?php

namespace App\Services\Cms;

use App\Models\Page;

class StructuredPageContentService
{
    public const MARKER = 'data-cms-structured-page';

    public function usesStructuredContent(?Page $page): bool
    {
        return $page !== null && $this->containsMarker((string) $page->content);
    }

    public function preserveStructureDuringUpdate(Page $page): void
    {
        if (! $page->isDirty('content')) {
            return;
        }

        $original = (string) $page->getOriginal('content');
        $incoming = (string) $page->content;

        if ($this->containsMarker($original) && ! $this->containsMarker($incoming)) {
            $page->content = $original;
        }
    }

    private function containsMarker(string $content): bool
    {
        return str_contains($content, self::MARKER);
    }
}
