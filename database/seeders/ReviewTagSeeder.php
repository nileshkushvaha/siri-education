<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\ReviewTag;
use Illuminate\Database\Seeder;

/** Idempotent default review-tag catalog. Admin-curated — students never create tags. */
class ReviewTagSeeder extends Seeder
{
    private const array TAGS = [
        ['key' => 'great_explainer', 'label' => 'Great Explainer', 'modes' => ['public_review', 'private_feedback']],
        ['key' => 'patient', 'label' => 'Patient', 'modes' => ['public_review', 'private_feedback']],
        ['key' => 'well_prepared', 'label' => 'Well Prepared', 'modes' => ['public_review', 'private_feedback']],
        ['key' => 'punctual', 'label' => 'Punctual', 'modes' => ['public_review', 'private_feedback']],
        ['key' => 'engaging', 'label' => 'Engaging', 'modes' => ['public_review', 'private_feedback']],
        ['key' => 'helped_me_improve', 'label' => 'Helped Me Improve', 'modes' => ['public_review']],
        ['key' => 'good_for_beginners', 'label' => 'Good for Beginners', 'modes' => ['public_review']],
        ['key' => 'needs_improvement', 'label' => 'Needs Improvement', 'modes' => ['private_feedback']],
    ];

    public function run(): void
    {
        foreach (self::TAGS as $index => $tag) {
            ReviewTag::query()->updateOrCreate(
                ['key' => $tag['key']],
                [
                    'label' => $tag['label'],
                    'applicable_modes' => $tag['modes'],
                    'is_active' => true,
                    'sort_order' => $index,
                ],
            );
        }
    }
}
