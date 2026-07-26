<?php

declare(strict_types=1);

namespace Tests\Architecture;

use App\Booking\Enums\BookingActor;
use App\Enums\ActivityActorType;
use App\Models\Activity;
use App\Models\Booking;
use App\Models\BookingGuest;
use App\Services\AuditTrailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * A permanent guard against guest-booking concepts being
 * reintroduced. Prefers structural checks (class/table/column/route
 * existence) over free-text grepping, since a blind repo-wide string
 * search would also flag this file's own documentation. Legitimate
 * "guest" usage — Laravel's `guest`
 * middleware, Blade `@guest`, the public-visitor persona, and the
 * generic Activity Log actor-type system (Activity::$guest_name/
 * guest_email/guest_phone, AuditTrailService::logGuest(), used by
 * ContactFormController/PublicFormService/NewsletterSubscriptionService
 * — none of them booking-related) — is deliberately untouched by every
 * check below.
 */
class BookingGuestRemovalGuardTest extends TestCase
{
    use RefreshDatabase;

    // ── No guest-booking classes exist ──────────────────────────────────

    public function test_booking_guest_model_does_not_exist(): void
    {
        $this->assertFalse(class_exists(BookingGuest::class));
    }

    public function test_guest_booking_service_classes_do_not_exist(): void
    {
        $this->assertFalse(interface_exists('App\Booking\Contracts\GuestBookingServiceInterface'));
        $this->assertFalse(class_exists('App\Booking\Services\GuestBookingService'));
        $this->assertFalse(class_exists('App\Booking\DTOs\GuestBookingData'));
        $this->assertFalse(class_exists('App\Booking\Enums\BookingGuestStatus'));
    }

    public function test_guest_booking_http_layer_does_not_exist(): void
    {
        $this->assertFalse(class_exists('App\Http\Controllers\Api\Guest\GuestBookingController'));
        $this->assertFalse(class_exists('App\Http\Controllers\Api\Guest\GuestAvailabilityController'));
        $this->assertFalse(class_exists('App\Http\Controllers\Api\Guest\GuestBookingPaymentController'));
        $this->assertFalse(class_exists('App\Http\Controllers\Api\Guest\GuestCatalogController'));
        $this->assertFalse(class_exists('App\Http\Controllers\Booking\GuestBookingPageController'));
        $this->assertFalse(class_exists('App\Http\Resources\Guest\GuestBookingResource'));
    }

    public function test_authenticated_attendee_rule_does_not_exist(): void
    {
        // Superseded by the type system itself: CreateBookingData::$studentId
        // is non-nullable, so the old "reject a null attendee" rule is
        // structurally impossible to need again.
        $this->assertFalse(class_exists('App\Booking\Validation\Rules\AuthenticatedAttendeeRule'));
    }

    public function test_no_guest_booking_source_files_exist_on_disk(): void
    {
        // Defense against re-creating the file without wiring it back
        // into the autoloader/tests catching it structurally above.
        foreach ([
            'app/Models/BookingGuest.php',
            'app/Booking/Contracts/GuestBookingServiceInterface.php',
            'app/Booking/Services/GuestBookingService.php',
            'app/Booking/DTOs/GuestBookingData.php',
            'app/Http/Controllers/Api/Guest',
            'app/Http/Requests/Api/Guest',
            'app/Http/Resources/Guest',
            'app/Http/Controllers/Booking/GuestBookingPageController.php',
            'database/factories/BookingGuestFactory.php',
            'app/Filament/Resources/Bookings/RelationManagers/GuestsRelationManager.php',
        ] as $path) {
            $this->assertFileDoesNotExist(base_path($path), "{$path} must not exist — guest booking was removed.");
        }
    }

    // ── No guest-booking schema ──────────────────────────────────────────

    public function test_booking_guests_table_does_not_exist(): void
    {
        $this->assertFalse(Schema::hasTable('booking_guests'));
    }

    public function test_bookings_table_has_no_guest_supporting_columns(): void
    {
        foreach (['guest_name', 'guest_email', 'guest_phone', 'manage_token'] as $column) {
            $this->assertFalse(Schema::hasColumn('bookings', $column), "bookings.{$column} must not exist.");
        }
    }

    public function test_bookings_student_and_instructor_columns_are_required(): void
    {
        $studentId = collect(DB::select("
            SELECT IS_NULLABLE FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bookings' AND COLUMN_NAME = 'student_id'
        "))->first();
        $instructorId = collect(DB::select("
            SELECT IS_NULLABLE FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bookings' AND COLUMN_NAME = 'instructor_id'
        "))->first();

        $this->assertSame('NO', $studentId->IS_NULLABLE);
        $this->assertSame('NO', $instructorId->IS_NULLABLE);
    }

    // ── No guest-booking routes ──────────────────────────────────────────

    public function test_no_guest_booking_routes_are_registered(): void
    {
        $names = collect(Route::getRoutes())->map(fn ($route) => $route->getName())->filter()->values();

        $this->assertFalse($names->contains(fn (string $name) => str_starts_with($name, 'api.guest.')), 'No api.guest.* route may be registered.');
        $this->assertFalse($names->contains('booking.manage'), 'The guest booking manage route must not exist.');
        $this->assertFalse($names->contains('instructors.booking.create'), 'The duplicate guest-era booking alias route must not exist.');
    }

    public function test_guest_booking_api_prefix_is_entirely_unrouted(): void
    {
        $this->getJson('/api/v1/guest/booking-types')->assertStatus(404);
        $this->postJson('/api/v1/guest/bookings', [])->assertStatus(404);
    }

    // ── Booking-domain role terminology: student/instructor, not attendee/host ──

    /**
     * Scans only the Booking domain's own source (not comments/docblocks
     * repo-wide, and not the Attendance domain, the meeting-provider
     * `host_url`/Zoom `host_user_id`/`host_email` fields, or SMTP's
     * `host` setting — all legitimate, unrelated uses of the word).
     */
    public function test_booking_domain_uses_student_instructor_column_names_not_attendee_host(): void
    {
        $prohibited = ['attendee_id', 'host_id', 'attendeeId', 'hostId'];

        $files = [
            ...$this->phpFilesUnder(base_path('app/Booking')),
            base_path('app/Models/Booking.php'),
            base_path('app/Policies/BookingPolicy.php'),
        ];

        foreach ($files as $file) {
            if (! is_file($file)) {
                continue;
            }

            $contents = file_get_contents($file);

            foreach ($prohibited as $needle) {
                $this->assertStringNotContainsString(
                    $needle,
                    $contents,
                    sprintf('%s still contains the pre-removal identifier "%s".', $file, $needle),
                );
            }
        }
    }

    public function test_booking_actor_enum_uses_student_instructor_not_attendee_host(): void
    {
        $cases = array_map(fn ($case) => $case->name, BookingActor::cases());

        $this->assertContains('Student', $cases);
        $this->assertContains('Instructor', $cases);
        $this->assertNotContains('Attendee', $cases);
        $this->assertNotContains('Host', $cases);
    }

    public function test_booking_model_relations_are_student_and_instructor(): void
    {
        $this->assertTrue(method_exists(Booking::class, 'student'));
        $this->assertTrue(method_exists(Booking::class, 'instructor'));
        $this->assertFalse(method_exists(Booking::class, 'attendee'));
        $this->assertFalse(method_exists(Booking::class, 'host'));
    }

    // ── Legitimate Guest terminology survives (not a blind removal) ──────

    public function test_legitimate_activity_log_guest_actor_system_is_untouched(): void
    {
        $this->assertTrue(property_exists(Activity::class, 'guest_name') || Schema::hasColumn('activity_log', 'guest_name'));
        $this->assertTrue(method_exists(AuditTrailService::class, 'logGuest'));
        $this->assertTrue(enum_exists(ActivityActorType::class));
        $this->assertContains('Guest', array_map(fn ($case) => $case->name, ActivityActorType::cases()));
    }

    public function test_laravel_guest_middleware_still_gates_auth_routes(): void
    {
        $registrationRoute = collect(Route::getRoutes())->first(fn ($route) => $route->getName() === 'auth.register');

        $this->assertNotNull($registrationRoute);
        $this->assertContains('guest', $registrationRoute->middleware());
    }

    // ── Helpers ────────────────────────────────────────────────────────

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
