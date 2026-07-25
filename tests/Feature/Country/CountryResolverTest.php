<?php

declare(strict_types=1);

namespace Tests\Feature\Country;

use App\Country\Services\CountryResolver;
use App\Models\Country;
use App\Models\User;
use App\Models\UserProfile;
use App\Settings\LocalizationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 34 (GAP-029, requirement #3) — the single place every
 * country-governed mutation resolves "which country applies here".
 */
class CountryResolverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
    }

    public function test_authenticated_student_resolves_their_own_profile_country(): void
    {
        $country = Country::factory()->create(['iso2' => 'IN']);
        $student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $student->assignRole('student');
        UserProfile::updateOrCreate(['user_id' => $student->id], ['country_id' => $country->id]);

        $resolved = app(CountryResolver::class)->forStudent($student->fresh());

        $this->assertNotNull($resolved);
        $this->assertSame($country->id, $resolved->id);
    }

    public function test_student_with_no_profile_country_resolves_to_null(): void
    {
        $student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $student->assignRole('student');

        $this->assertNull(app(CountryResolver::class)->forStudent($student));
    }

    public function test_instructor_action_resolves_the_instructors_own_profile_country(): void
    {
        $country = Country::factory()->create(['iso2' => 'GB']);
        $instructor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $instructor->assignRole('instructor');
        UserProfile::updateOrCreate(['user_id' => $instructor->id], ['country_id' => $country->id]);

        $resolved = app(CountryResolver::class)->forInstructor($instructor->fresh());

        $this->assertSame($country->id, $resolved->id);
    }

    public function test_guest_resolution_reuses_the_existing_marketplace_country_resolver(): void
    {
        $country = Country::factory()->create(['iso2' => 'FR']);

        $request = Request::create('/', 'GET', ['pricing_country' => 'FR']);
        $request->setLaravelSession($this->app['session']->driver());

        $resolved = app(CountryResolver::class)->forGuest($request);

        $this->assertSame($country->id, $resolved->id);
    }

    public function test_guest_resolution_falls_back_to_the_platform_default_country(): void
    {
        $default = Country::factory()->create(['iso2' => 'IN']);
        app(LocalizationSettings::class)->default_country = 'IN';
        app(LocalizationSettings::class)->save();

        $request = Request::create('/', 'GET');
        $request->setLaravelSession($this->app['session']->driver());

        $resolved = app(CountryResolver::class)->forGuest($request);

        $this->assertSame($default->id, $resolved->id);
    }
}
