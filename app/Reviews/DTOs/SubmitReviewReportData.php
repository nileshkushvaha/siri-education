<?php

declare(strict_types=1);

namespace App\Reviews\DTOs;

use App\Reviews\Enums\ReviewReportReason;

/** Raw reporter input for one report submission — unvalidated/unsanitized as given; SubmitReviewReportAction enforces both. */
final readonly class SubmitReviewReportData
{
    public function __construct(
        public ReviewReportReason $reason,
        public ?string $explanation = null,
    ) {}
}
