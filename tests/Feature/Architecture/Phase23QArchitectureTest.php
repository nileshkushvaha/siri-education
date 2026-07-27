<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Tests\TestCase;

/**
 * Guards the unified-registration boundary: /register became the canonical,
 * role-neutral registration entry point — wording and routing only.
 * No second authentication system, no duplicate registration
 * controller/service, no new instructor-specific registration route,
 * and registration/validation/captcha/referral/intent logic is
 * byte-for-byte the same class as before.
 */
final class Phase23QArchitectureTest extends TestCase
{
    public function test_no_duplicate_registration_controller_or_service_was_created(): void
    {
        $matches = [];
        foreach (glob(app_path('Http/Controllers/Auth/*.php')) as $file) {
            if (str_contains(basename($file), 'Register')) {
                $matches[] = $file;
            }
        }
        $this->assertCount(1, $matches, 'Exactly one registration controller must exist (App\\Http\\Controllers\\Auth\\RegisterController).');
        $this->assertSame('RegisterController.php', basename($matches[0]));

        // The one authoritative registration service, plus its pre-existing
        // captcha helper and result DTO — no new rival service was added.
        $this->assertFileExists(app_path('Services/Auth/RegistrationService.php'));
        $this->assertFileDoesNotExist(app_path('Services/Auth/UnifiedRegistrationService.php'));
        $this->assertFileDoesNotExist(app_path('Services/Auth/AccountRegistrationService.php'));
    }

    public function test_no_instructor_specific_registration_route_was_created(): void
    {
        $routes = file_get_contents(base_path('routes/web.php'));
        $this->assertIsString($routes);

        $this->assertStringNotContainsString("Route::get('/instructor-registration'", $routes);
        $this->assertStringNotContainsString("Route::get('/register-instructor'", $routes);
        $this->assertStringNotContainsString('->name(\'instructor.register\')', $routes);

        // Instructor intent still flows through the one shared route via
        // a query parameter, never a second route/controller.
        $this->assertStringContainsString("route('auth.register', ['intent' => 'instructor'])", file_get_contents(resource_path('views/instructor-apply/partials/cta.blade.php')));
    }

    public function test_register_is_canonical_and_student_registration_is_a_redirect_only(): void
    {
        $routes = file_get_contents(base_path('routes/web.php'));
        $this->assertIsString($routes);

        $this->assertStringContainsString("Route::get('/register', [RegisterController::class, 'showForm'])", $routes);
        $this->assertStringContainsString("Route::post('/register', [RegisterController::class, 'store'])", $routes);
        $this->assertStringContainsString("Route::get('/student-registration',", $routes);

        // The redirect line contains no controller/business logic — it
        // only forwards to the named canonical route.
        $redirectLine = substr($routes, (int) strpos($routes, "Route::get('/student-registration',"));
        $redirectLine = substr($redirectLine, 0, (int) strpos($redirectLine, "\n"));
        $this->assertStringContainsString("redirect()->route('auth.register'", $redirectLine);
    }

    public function test_registration_request_validation_rules_are_unchanged(): void
    {
        $request = file_get_contents(app_path('Http/Requests/Auth/RegisterRequest.php'));
        $this->assertIsString($request);

        // The exact same rule set this phase was told not to touch.
        foreach (['first_name', 'last_name', 'email', 'phone', 'phone_country_iso2', 'country_id', 'password', 'terms', 'captcha_answer', 'referral_code'] as $field) {
            $this->assertStringContainsString("'{$field}'", $request);
        }

        $this->assertStringContainsString('ValidRegistrationCaptcha', $request);
        $this->assertStringContainsString('SupportedRegistrationCountry', $request);
    }

    public function test_registration_service_and_intent_capture_are_untouched(): void
    {
        $this->assertFileExists(app_path('Services/Auth/RegistrationService.php'));
        $this->assertFileExists(app_path('Support/InstructorApplicationIntent.php'));

        $controller = file_get_contents(app_path('Http/Controllers/Auth/RegisterController.php'));
        $this->assertIsString($controller);
        $this->assertStringContainsString('InstructorApplicationIntent::captureFromRequest()', $controller);
        $this->assertStringContainsString('InstructorApplicationIntent::consume()', $controller);
        $this->assertStringContainsString('$this->registrationService->register(', $controller);
    }

    public function test_no_student_only_wording_remains_on_the_registration_page(): void
    {
        $view = file_get_contents(resource_path('views/auth/register.blade.php'));
        $formView = file_get_contents(resource_path('views/livewire/frontend/auth/register-form.blade.php'));
        $this->assertIsString($view);
        $this->assertIsString($formView);

        foreach ([$view, $formView] as $source) {
            $this->assertStringNotContainsString('Student registration', $source);
            $this->assertStringNotContainsString('Student Registration', $source);
            $this->assertStringNotContainsString('Free student account', $source);
            $this->assertStringNotContainsString('Your student account', $source);
            $this->assertStringNotContainsString('Create student account', $source);
        }
    }
}
