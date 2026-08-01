<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Livewire\Frontend\Auth\RegisterForm;
use App\Services\Security\PasswordRuleBuilder;
use App\Settings\PasswordPolicySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The registration form used to hardcode "Use 8 or more characters with
 * uppercase and lowercase letters, at least one number, and one symbol",
 * which became wrong the moment an administrator changed the policy — the
 * form would reject a password it had just told the user was fine.
 *
 * describe() sits beside build() on PasswordRuleBuilder so both read one
 * settings object and cannot disagree.
 */
class PasswordPolicyHintTest extends TestCase
{
    use RefreshDatabase;

    private function policy(array $overrides = []): PasswordRuleBuilder
    {
        $settings = app(PasswordPolicySettings::class);

        foreach (array_merge([
            'min_length' => 8,
            'require_uppercase' => true,
            'require_lowercase' => true,
            'require_number' => true,
            'require_special' => true,
        ], $overrides) as $key => $value) {
            $settings->{$key} = $value;
        }

        app()->instance(PasswordPolicySettings::class, $settings);

        return new PasswordRuleBuilder($settings);
    }

    public function test_it_describes_the_full_policy(): void
    {
        $this->assertSame(
            'Use 8 or more characters with uppercase and lowercase letters, at least one number, and one symbol.',
            $this->policy()->describe(),
        );
    }

    public function test_the_minimum_length_follows_the_setting(): void
    {
        $this->assertStringStartsWith('Use 14 or more characters', $this->policy(['min_length' => 14])->describe());
    }

    public function test_a_dropped_requirement_disappears_from_the_sentence(): void
    {
        $hint = $this->policy(['require_special' => false])->describe();

        $this->assertStringNotContainsString('symbol', $hint);
        $this->assertSame('Use 8 or more characters with uppercase and lowercase letters and at least one number.', $hint);
    }

    public function test_length_only_policy_reads_as_a_plain_sentence(): void
    {
        $hint = $this->policy([
            'require_uppercase' => false,
            'require_lowercase' => false,
            'require_number' => false,
            'require_special' => false,
        ])->describe();

        $this->assertSame('Use 8 or more characters.', $hint);
    }

    public function test_it_describes_what_is_enforced_not_what_the_flags_read_like(): void
    {
        // build() maps EITHER case flag onto Password::mixedCase(), which
        // demands both. Saying "uppercase letters" while enforcing both would
        // send users straight into a validation error.
        $builder = $this->policy([
            'require_lowercase' => false,
            'require_number' => false,
            'require_special' => false,
        ]);

        $this->assertSame('Use 8 or more characters with uppercase and lowercase letters.', $builder->describe());
    }

    public function test_the_registration_form_renders_the_policy_driven_hint(): void
    {
        $this->policy(['min_length' => 11, 'require_special' => false]);

        Livewire::test(RegisterForm::class)
            ->assertSee('Use 11 or more characters with uppercase and lowercase letters and at least one number.');
    }
}
