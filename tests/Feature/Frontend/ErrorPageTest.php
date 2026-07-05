<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ErrorPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_method_not_allowed_uses_friendly_error_page_when_debug_is_disabled(): void
    {
        config(['app.debug' => false]);

        $this->get('/dashboard/instructor/start')
            ->assertStatus(405)
            ->assertSee('Method not allowed')
            ->assertSee('This link cannot be opened directly')
            ->assertDontSee('frontend.layout.site-header')
            ->assertDontSee('frontend.layout.site-footer')
            ->assertDontSee('Sign in')
            ->assertDontSee('Get started')
            ->assertDontSee('MethodNotAllowedHttpException');
    }

    public function test_method_not_allowed_uses_debug_exception_page_when_debug_is_enabled(): void
    {
        config(['app.debug' => true]);

        $this->get('/dashboard/instructor/start')
            ->assertStatus(405)
            ->assertSee('MethodNotAllowedHttpException');
    }

    public function test_missing_page_uses_friendly_error_page(): void
    {
        $this->get('/this-page-does-not-exist')
            ->assertNotFound()
            ->assertSee('Page not found');
    }
}
