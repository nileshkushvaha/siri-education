<?php

declare(strict_types=1);

namespace Tests\Feature\Referral;

use App\Enums\StudentStatus;
use App\Models\ReferralCode;
use App\Models\User;
use App\Services\Account\AccountMenuService;
use App\Settings\FeatureSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ReferFriendPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);

        $features = app(FeatureSettings::class);
        $features->referral_enabled = true;
        $features->save();
    }

    private function student(): User
    {
        $student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $student->assignRole('student');
        $student->profile()->update(['student_status' => StudentStatus::Active]); // Phase 24H.2: interactive student actions require Active status.

        return $student;
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('dashboard.refer-a-friend'))->assertRedirect(route('auth.login'));
    }

    public function test_student_sees_code_link_and_program_explanation(): void
    {
        $student = $this->student();

        $response = $this->actingAs($student)
            ->get(route('dashboard.refer-a-friend'))
            ->assertOk()
            ->assertSee('Refer a Friend')
            ->assertSee('registration alone does not guarantee a reward')
            ->assertSee('Referral tracking and rewards will appear here once eligible activity occurs.');

        // The code was lazily created on first visit.
        $code = ReferralCode::query()->where('user_id', $student->id)->sole();

        $response->assertSee($code->code);
        $response->assertSee(route('auth.register', ['ref' => $code->code]), false);
    }

    public function test_share_links_are_url_encoded_and_use_the_named_registration_route(): void
    {
        $student = $this->student();

        $response = $this->actingAs($student)->get(route('dashboard.refer-a-friend'))->assertOk();

        $code = ReferralCode::query()->where('user_id', $student->id)->sole();
        $link = route('auth.register', ['ref' => $code->code]);

        $response->assertSee('https://wa.me/?text=', false);
        $response->assertSee('mailto:?subject=', false);
        $response->assertSee(rawurlencode($link), false);

        // The link comes from the application URL configuration — never a
        // hardcoded domain baked into the page or the stored code.
        $this->assertStringStartsWith(config('app.url'), $link);
        $this->assertStringNotContainsString('http', $code->code);
    }

    public function test_page_shows_no_fabricated_reward_totals_or_referred_identities(): void
    {
        $student = $this->student();

        $response = $this->actingAs($student)->get(route('dashboard.refer-a-friend'))->assertOk();

        $response->assertDontSee('Credited rewards');
        $response->assertDontSee('Pending rewards');
        $response->assertDontSee('Invited students');
    }

    public function test_feature_disabled_returns_404_and_hides_the_menu_item(): void
    {
        $student = $this->student();

        $features = app(FeatureSettings::class);
        $features->referral_enabled = false;
        $features->save();

        $this->actingAs($student)->get(route('dashboard.refer-a-friend'))->assertNotFound();

        $labels = collect(app(AccountMenuService::class)->items($student))->flatMap(fn (array $group) => $group['items'])->pluck('label')->all();
        $this->assertNotContains('Refer a Friend', $labels);
    }

    public function test_menu_item_appears_for_students_when_enabled_and_never_for_instructors(): void
    {
        $student = $this->student();

        $labels = collect(app(AccountMenuService::class)->items($student))->flatMap(fn (array $group) => $group['items'])->pluck('label')->all();
        $this->assertContains('Refer a Friend', $labels);

        $instructor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $instructor->assignRole('instructor');

        $labels = collect(app(AccountMenuService::class)->items($instructor))->flatMap(fn (array $group) => $group['items'])->pluck('label')->all();
        $this->assertNotContains('Refer a Friend', $labels);
    }

    public function test_instructor_is_denied_with_403(): void
    {
        $instructor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $instructor->assignRole('instructor');

        $this->actingAs($instructor)->get(route('dashboard.refer-a-friend'))->assertForbidden();
    }

    public function test_disabled_code_shows_neutral_notice_instead_of_share_actions(): void
    {
        $student = $this->student();
        ReferralCode::factory()->disabled()->create(['user_id' => $student->id]);

        $this->actingAs($student)
            ->get(route('dashboard.refer-a-friend'))
            ->assertOk()
            ->assertSee('Your referral code is currently disabled')
            ->assertDontSee('https://wa.me/?text=', false);
    }
}
