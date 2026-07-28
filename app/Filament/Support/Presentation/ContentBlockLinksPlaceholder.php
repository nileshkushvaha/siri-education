<?php

declare(strict_types=1);

namespace App\Filament\Support\Presentation;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

/**
 * The "+ Add Block" / "View {Noun} Blocks" link pair shown on the Page and
 * Post forms' "Advanced Layout" section. Extracted because both forms had
 * this exact markup duplicated verbatim, hardcoded to raw hex colors
 * (#f59e0b, #d1d5db) via inline styles — invisible in dark mode and
 * disconnected from the panel's actual configured primary color.
 *
 * Uses Tailwind utility classes generated from Filament's own `primary-*`
 * CSS custom properties (see `--color-primary-*` in
 * vendor/filament/support/resources/css/index.css) instead of a literal
 * value, so this tracks the panel's real primary color and both themes
 * automatically — the same mechanism Filament's own components use.
 */
final class ContentBlockLinksPlaceholder
{
    private const string PRIMARY_BUTTON_CLASSES = 'inline-flex items-center gap-1.5 rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-500 dark:bg-primary-500 dark:hover:bg-primary-400';

    private const string SECONDARY_BUTTON_CLASSES = 'inline-flex items-center gap-1.5 rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-white/20 dark:text-gray-200 dark:hover:bg-white/5';

    public static function content(?Model $record, string $ownerQueryParam, string $blockableTypeFilterValue, string $noun): HtmlString
    {
        if (! $record) {
            return new HtmlString('Save this '.e(strtolower($noun)).' first, then you can add content blocks.');
        }

        $createUrl = url('/admin/page-blocks/create?'.$ownerQueryParam.'='.$record->getKey());
        $listUrl = url('/admin/page-blocks?tableFilters[blockable_type][value]='.urlencode($blockableTypeFilterValue));

        return new HtmlString(
            '<div class="flex items-center gap-3">'
            .'<a href="'.e($createUrl).'" class="'.self::PRIMARY_BUTTON_CLASSES.'">+ Add Block</a>'
            .'<a href="'.e($listUrl).'" class="'.self::SECONDARY_BUTTON_CLASSES.'">View '.e($noun).' Blocks</a>'
            .'</div>'
        );
    }
}
