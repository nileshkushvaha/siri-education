<?php

declare(strict_types=1);

namespace Tests\Feature\Profile;

use App\Enums\EducationLevel;
use App\Enums\EmploymentType;
use App\Models\Country;
use App\Models\User;
use App\Models\UserEducation;
use App\Models\UserExperience;
use App\Services\Profile\ProfileCompletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProfileCompletionServiceTest extends TestCase
{
    use RefreshDatabase;

    private ProfileCompletionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ProfileCompletionService::class);
        Storage::fake('public');
    }

    public function test_a_brand_new_user_has_low_completion(): void
    {
        $user = User::factory()->create([
            'first_name' => null,
            'last_name' => null,
            'email_verified_at' => null,
        ]);

        $this->assertSame(0, $this->service->calculate($user));
    }

    public function test_completion_increases_as_sections_are_filled(): void
    {
        $user = User::factory()->create([
            'first_name' => 'Jane',
            'last_name' => null,
            'email_verified_at' => null,
        ]);

        $partial = $this->service->calculate($user);

        $user->update(['last_name' => 'Doe', 'email_verified_at' => now()]);
        $user->profile->update(['headline' => 'Engineer', 'bio' => 'Hello', 'phone' => '12345']);

        $more = $this->service->calculate($user->fresh());

        $this->assertGreaterThan($partial, $more);
    }

    public function test_a_fully_completed_profile_is_100_percent(): void
    {
        $country = Country::factory()->create();
        $user = User::factory()->create([
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email_verified_at' => now(),
        ]);
        $user->profile->update([
            'headline' => 'Engineer',
            'bio' => 'Hello world',
            'phone' => '12345',
            'address' => '123 Main St',
            'country_id' => $country->id,
            'website' => 'https://example.com',
            'facebook' => 'https://facebook.com/jane',
            'twitter' => 'https://twitter.com/jane',
            'linkedin' => 'https://linkedin.com/in/jane',
            'github' => 'https://github.com/jane',
            'instagram' => 'https://instagram.com/jane',
            'youtube' => 'https://youtube.com/@jane',
        ]);
        $user->profile->addMedia(UploadedFile::fake()->image('avatar.jpg'))->toMediaCollection('avatar');

        UserExperience::factory()->for($user)->create([
            'employment_type' => EmploymentType::FullTime,
            'is_current' => true,
            'start_date' => now()->subYear(),
            'end_date' => null,
        ]);
        UserEducation::factory()->for($user)->create([
            'education_level' => EducationLevel::Bachelor,
            'is_current' => false,
            'start_date' => now()->subYears(4),
            'end_date' => now()->subYears(1),
        ]);

        $this->assertSame(100, $this->service->calculate($user->fresh()));
    }

    public function test_experience_and_education_sections_contribute_their_full_weight(): void
    {
        $withoutRecords = User::factory()->create(['first_name' => 'Jane', 'last_name' => 'Doe', 'email_verified_at' => now()]);
        $baseline = $this->service->calculate($withoutRecords);

        UserExperience::factory()->for($withoutRecords)->create([
            'employment_type' => EmploymentType::FullTime,
            'is_current' => true,
            'start_date' => now()->subYear(),
            'end_date' => null,
        ]);

        $withExperience = $this->service->calculate($withoutRecords->fresh());

        $this->assertSame(30, $withExperience - $baseline);
    }

    public function test_recalculate_and_store_persists_the_percentage(): void
    {
        $user = User::factory()->create(['first_name' => 'Jane', 'last_name' => 'Doe', 'email_verified_at' => now()]);

        $percentage = $this->service->recalculateAndStore($user);

        $this->assertSame($percentage, $user->profile->fresh()->profile_completion);
    }

    public function test_breakdown_reports_each_weighted_section_individually(): void
    {
        $user = User::factory()->create(['first_name' => 'Jane', 'last_name' => null]);

        $breakdown = $this->service->breakdown($user);

        $this->assertArrayHasKey('basic_profile', $breakdown);
        $this->assertArrayHasKey('avatar', $breakdown);
        $this->assertArrayHasKey('bio', $breakdown);
        $this->assertArrayHasKey('experience', $breakdown);
        $this->assertArrayHasKey('education', $breakdown);
        $this->assertArrayHasKey('social_links', $breakdown);

        $this->assertSame(20, $breakdown['basic_profile']['weight']);
        $this->assertSame(10, $breakdown['avatar']['weight']);
        $this->assertSame(10, $breakdown['bio']['weight']);
        $this->assertSame(30, $breakdown['experience']['weight']);
        $this->assertSame(20, $breakdown['education']['weight']);
        $this->assertSame(10, $breakdown['social_links']['weight']);

        $this->assertSame(0.0, $breakdown['experience']['score']);
        $this->assertSame(0.0, $breakdown['avatar']['score']);
        $this->assertGreaterThan(0.0, $breakdown['basic_profile']['score']);
        $this->assertLessThan(1.0, $breakdown['basic_profile']['score']);
    }
}
