<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Settings\PasswordPolicySettings;
use Illuminate\Validation\Rules\Password;

class PasswordRuleBuilder
{
    public function __construct(private readonly PasswordPolicySettings $settings) {}

    public function build(): Password
    {
        $rule = Password::min($this->settings->min_length);

        if ($this->settings->require_uppercase || $this->settings->require_lowercase) {
            $rule = $rule->mixedCase();
        }

        if ($this->settings->require_number) {
            $rule = $rule->numbers();
        }

        if ($this->settings->require_special) {
            $rule = $rule->symbols();
        }

        return $rule;
    }

    /**
     * The same policy in words, for the hint shown under a password field.
     *
     * Lives beside build() on purpose: both read one settings object, so the
     * sentence a user is shown cannot drift from the rule actually enforced.
     * The registration form previously hardcoded "8 or more characters with
     * uppercase and lowercase letters, at least one number, and one symbol",
     * which silently became a lie the moment an administrator changed the
     * policy in Access Control → Password Policy.
     */
    public function describe(): string
    {
        $requirements = [];

        // build() maps EITHER case flag onto Password::mixedCase(), which
        // demands both — so this describes what is enforced, not what the two
        // flags read like in isolation.
        if ($this->settings->require_uppercase || $this->settings->require_lowercase) {
            $requirements[] = 'uppercase and lowercase letters';
        }

        if ($this->settings->require_number) {
            $requirements[] = 'at least one number';
        }

        if ($this->settings->require_special) {
            $requirements[] = 'one symbol';
        }

        $length = sprintf('Use %d or more characters', $this->settings->min_length);

        if ($requirements === []) {
            return $length.'.';
        }

        return $length.' with '.$this->joinNaturally($requirements).'.';
    }

    /**
     * @param  list<string>  $items
     */
    private function joinNaturally(array $items): string
    {
        if (count($items) === 1) {
            return $items[0];
        }

        $last = array_pop($items);

        // Oxford comma only where there are three or more, so two items read
        // "x and y" rather than "x, and y".
        return count($items) === 1
            ? $items[0].' and '.$last
            : implode(', ', $items).', and '.$last;
    }
}
