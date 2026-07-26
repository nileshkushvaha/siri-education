<?php

declare(strict_types=1);

namespace App\Filament\Resources\ReviewTags\Pages;

use App\Filament\Resources\ReviewTags\ReviewTagResource;
use Filament\Resources\Pages\EditRecord;

/**
 * No DeleteAction/ForceDeleteAction here — ReviewTag has no
 * `deleted_at` column at all; retirement is the
 * `is_active` toggle on the edit form only.
 */
class EditReviewTag extends EditRecord
{
    protected static string $resource = ReviewTagResource::class;
}
