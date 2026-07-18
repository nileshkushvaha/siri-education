<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Tests\TestCase;

/**
 * Guards the Phase 23F boundary: public instructor service/DTO/views
 * never reference PII, KYC, or internal admin fields.
 */
final class Phase23FArchitectureTest extends TestCase
{
    public function test_public_instructor_service_never_references_pii_fields(): void
    {
        $service = file_get_contents(app_path('Services/Instructor/InstructorService.php'));
        $this->assertIsString($service);

        foreach (['->email', "'email'", '->phone', "'phone'", 'phone_e164', 'phone_national_number'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $service, "InstructorService must never reference {$forbidden}.");
        }
    }

    public function test_public_instructor_service_never_references_kyc_collections(): void
    {
        $service = file_get_contents(app_path('Services/Instructor/InstructorService.php'));
        $this->assertIsString($service);

        foreach (['government_id', 'address_proof', 'education_certificate', 'teaching_certificate', 'resume', 'introduction_video'] as $collection) {
            $this->assertStringNotContainsString($collection, $service, "InstructorService must never reference the {$collection} KYC collection.");
        }
    }

    public function test_public_profile_views_never_reference_admin_or_kyc_fields(): void
    {
        foreach ([
            resource_path('views/instructors/show.blade.php'),
            resource_path('views/instructors/index.blade.php'),
        ] as $view) {
            $contents = file_get_contents($view);
            $this->assertIsString($contents);

            foreach ([
                'instructor_review_reason',
                'instructor_documents_requested_reason',
                'government_id',
                'address_proof',
                'education_certificate',
                'teaching_certificate',
                '->email',
                'phone_e164',
                'compensation',
                'commission_rate',
            ] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $contents, "{$view} must never reference {$forbidden}.");
            }
        }
    }

    public function test_trust_badge_resolver_is_the_only_verified_badge_authority(): void
    {
        $show = file_get_contents(resource_path('views/instructors/show.blade.php'));
        $this->assertIsString($show);

        $this->assertStringNotContainsString('$profile->is_instructor_verified', $show, 'The view must consult $isVerified (built by InstructorTrustBadgeResolver), not the raw column, to correctly combine it with bookable status.');
    }

    public function test_sitemap_reuses_instructor_service_base_query_eligibility(): void
    {
        $service = file_get_contents(app_path('Services/Instructor/InstructorService.php'));
        $this->assertIsString($service);

        $sitemapMethodStart = strpos($service, 'function sitemapEntries');
        $this->assertNotFalse($sitemapMethodStart);

        $snippet = substr($service, $sitemapMethodStart, 400);
        $this->assertStringContainsString('baseQuery()', $snippet, 'sitemapEntries() must reuse baseQuery(), not a second eligibility query.');
    }
}
