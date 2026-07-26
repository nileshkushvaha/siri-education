<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Tests\TestCase;

/**
 * Permanent guards for the domain boundaries: Reviews must never
 * directly mutate Booking/Wallet/Payments/Earnings/Settlements;
 * Quality must never directly suspend instructors, change marketplace
 * ranking, move money, or modify review text; Feedback must never
 * directly modify Reviews/Learning Plans/Homework/Ratings;
 * notification listeners must never mutate domain state. Uses an
 * explicit allowlist for legitimate read-only DTO/enum/utility
 * imports rather than a blanket prohibition, since a domain is
 * allowed to *read* another domain's data — it just cannot write it.
 */
class ReviewQualityFeedbackDomainBoundaryTest extends TestCase
{
    // ── Reviews must not mutate Booking/Wallet/Earnings ───────────────────

    public function test_reviews_domain_does_not_import_mutating_booking_wallet_or_earning_classes(): void
    {
        $allowed = [
            'App\Booking\Enums\BookingPaymentStatus', // read-only: eligibility/pricing checks
        ];

        $this->assertNoDisallowedCrossDomainImports(
            base_path('app/Reviews'),
            ['App\Booking\\', 'App\Wallet\\', 'App\Earnings\\'],
            $allowed,
        );
    }

    // ── Quality must not suspend instructors, move money, or edit reviews ─

    public function test_quality_domain_does_not_import_mutating_booking_earning_or_review_classes(): void
    {
        $allowed = [
            'App\Booking\Enums\BookingActor', // read-only: cancellation attribution
            'App\Booking\DTOs\CancelBookingData', // read-only: cancellation attribution
            // Read-only enums Quality filters/reads on, never writes:
            'App\Reviews\Enums\ReviewReportStatus',
            'App\Reviews\Enums\StudentReviewStatus',
            'App\Reviews\Enums\ReviewReportReason',
            'App\Reviews\Enums\LessonReviewEligibilityMode',
            'App\Reviews\Enums\ReviewableLessonType',
            'App\Reviews\Support\PublicReviewerIdentity', // stateless display-label utility, not a Reviews-domain write
        ];

        $this->assertNoDisallowedCrossDomainImports(
            base_path('app/Quality'),
            ['App\Booking\\', 'App\Wallet\\', 'App\Earnings\\', 'App\Reviews\\'],
            $allowed,
        );
    }

    public function test_quality_services_never_call_instructor_suspension_or_ranking_methods(): void
    {
        foreach ($this->phpFilesUnder(base_path('app/Quality')) as $file) {
            $contents = (string) file_get_contents($file);

            foreach (['suspend(', 'ban(', 'deactivateProfile(', 'updateRanking(', 'changeRanking('] as $needle) {
                $this->assertStringNotContainsString($needle, $contents, "{$file} must not call {$needle} — Quality never enforces or ranks.");
            }
        }
    }

    // ── Feedback must not modify Reviews/Learning Plans/Homework/Ratings ──

    public function test_feedback_domain_does_not_import_mutating_review_learning_plan_homework_or_rating_classes(): void
    {
        $allowed = [
            'App\Reviews\Support\ReviewContentSanitizer', // stateless utility, shared — not a Reviews-domain write
            'App\Reviews\DTOs\SanitizedReviewContent', // read-only value object returned by the sanitizer above
        ];

        $this->assertNoDisallowedCrossDomainImports(
            base_path('app/Feedback'),
            ['App\Reviews\\', 'App\LearningPlan\\', 'App\Homework\\'],
            $allowed,
        );

        foreach ($this->phpFilesUnder(base_path('app/Feedback')) as $file) {
            $contents = (string) file_get_contents($file);
            $this->assertStringNotContainsString('InstructorRatingAggregate', $contents, "{$file} must not touch the rating aggregate — feedback never affects ratings.");
        }
    }

    // ── Notification listeners must never mutate domain state ────────────

    public function test_review_and_quality_notification_listeners_never_mutate_domain_state(): void
    {
        $directories = [base_path('app/Listeners/Reviews'), base_path('app/Listeners/Quality')];
        $mutatingNeedles = [
            '->save(', '->update(', '->delete(', '->fill(',
            'TransitionReviewStatusAction', 'TransitionInstructorEarningAction',
            'ReviewModerationService', 'ReviewReportService', 'InstructorQualityAlertService',
            'BookingServiceInterface', 'WalletLedgerService',
        ];

        foreach ($directories as $directory) {
            foreach ($this->phpFilesUnder($directory) as $file) {
                if (! str_contains(basename($file), 'Send') || ! str_contains(basename($file), 'Notification')) {
                    continue; // only the notification-sending listeners are in scope here
                }

                $contents = (string) file_get_contents($file);

                foreach ($mutatingNeedles as $needle) {
                    $this->assertStringNotContainsString($needle, $contents, "{$file} is a notification listener and must never mutate domain state (found \"{$needle}\").");
                }
            }
        }
    }

    // ── User-facing review-report Livewire UI boundaries ──────────────────

    public function test_review_report_livewire_ui_does_not_import_mutating_wallet_earning_or_quality_classes(): void
    {
        $allowed = [
            'App\Reviews\Contracts\ReviewReportServiceInterface', // the one write path this UI may call
            'App\Reviews\DTOs\SubmitReviewReportData',
            'App\Reviews\Enums\ReviewReportReason',
            'App\Reviews\Exceptions\DuplicateReviewReportException',
            'App\Reviews\Exceptions\ReviewNotReportableException',
        ];

        $this->assertNoDisallowedCrossDomainImports(
            base_path('app/Livewire/Reviews'),
            ['App\Booking\\', 'App\Wallet\\', 'App\Earnings\\', 'App\Quality\\'],
            $allowed,
        );
    }

    public function test_review_report_livewire_ui_never_mutates_a_review_report_or_rating_aggregate_directly(): void
    {
        foreach ($this->phpFilesUnder(base_path('app/Livewire/Reviews')) as $file) {
            $contents = (string) file_get_contents($file);

            foreach ([
                'new ReviewReport(', 'ReviewReport::create', 'ReviewReport::query()->update',
                'InstructorRatingAggregate', 'TransitionReviewStatusAction', 'ReviewModerationService',
                'activity(', 'Activity::create',
            ] as $needle) {
                $this->assertStringNotContainsString($needle, $contents, "{$file} must never directly mutate ReviewReport/LessonReview state or bypass AuditTrailService (found \"{$needle}\").");
            }
        }
    }

    public function test_public_review_rendering_does_not_reference_review_report_records(): void
    {
        foreach ([
            base_path('app/Reviews/DTOs/PublicInstructorReviewData.php'),
            base_path('resources/views/instructors/show.blade.php'),
        ] as $file) {
            $contents = (string) file_get_contents($file);
            $this->assertStringNotContainsString('ReviewReport', $contents, "{$file} must never serialize or reference a ReviewReport record on the public profile page.");
        }
    }

    public function test_review_report_service_delegates_resolution_to_the_existing_moderation_service(): void
    {
        $contents = (string) file_get_contents(base_path('app/Reviews/Services/ReviewReportService.php'));

        $this->assertStringContainsString('ReviewModerationServiceInterface', $contents, 'ReviewReportService must keep delegating status-changing resolutions to the existing moderation service.');
        $this->assertStringNotContainsString('->status = ', $contents, 'ReviewReportService must never write LessonReview::$status directly.');
    }

    // ── Helpers ────────────────────────────────────────────────────────

    /** @param list<string> $prefixes @param list<string> $allowed */
    private function assertNoDisallowedCrossDomainImports(string $directory, array $prefixes, array $allowed): void
    {
        foreach ($this->phpFilesUnder($directory) as $file) {
            foreach (file((string) $file) ?: [] as $line) {
                if (! preg_match('/^use\s+([A-Za-z0-9\\\\]+);/', trim($line), $matches)) {
                    continue;
                }

                $imported = $matches[1];

                foreach ($prefixes as $prefix) {
                    if (str_starts_with($imported, $prefix) && ! in_array($imported, $allowed, true)) {
                        $this->fail("{$file} imports {$imported}, which is not in the read-only allowlist for cross-domain access.");
                    }
                }
            }
        }

        $this->addToAssertionCount(1);
    }

    /** @return list<string> */
    private function phpFilesUnder(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );

        $files = [];

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
