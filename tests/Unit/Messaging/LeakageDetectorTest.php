<?php

declare(strict_types=1);

namespace Tests\Unit\Messaging;

use App\Messaging\Support\LeakageDetector;
use Tests\TestCase;

/** SRS §17.32/§17.33 — deterministic, advisory-only flagging; body is never mutated. */
class LeakageDetectorTest extends TestCase
{
    public function test_detects_an_email_address(): void
    {
        $flags = (new LeakageDetector)->detect('Reach me at someone@example.com please.');

        $this->assertContains('email_address', $flags);
    }

    public function test_detects_a_phone_number(): void
    {
        $flags = (new LeakageDetector)->detect('Call me at +1 (555) 123-4567.');

        $this->assertContains('phone_number', $flags);
    }

    public function test_detects_an_external_link(): void
    {
        $flags = (new LeakageDetector)->detect('Join here: https://zoom.us/j/123456789');

        $this->assertContains('external_link', $flags);
    }

    public function test_does_not_flag_a_link_to_the_platforms_own_domain(): void
    {
        $flags = (new LeakageDetector)->detect('See '.config('app.url').'/lessons/123');

        $this->assertNotContains('external_link', $flags);
    }

    public function test_detects_an_off_platform_keyword(): void
    {
        $flags = (new LeakageDetector)->detect('Message me on WhatsApp instead.');

        $this->assertContains('off_platform_keyword', $flags);
    }

    public function test_clean_message_produces_no_flags(): void
    {
        $flags = (new LeakageDetector)->detect('Looking forward to our next lesson on algebra.');

        $this->assertSame([], $flags);
    }

    public function test_never_mutates_the_input_body(): void
    {
        $body = 'Call me at 555-123-4567 or email test@example.com';

        (new LeakageDetector)->detect($body);

        $this->assertSame('Call me at 555-123-4567 or email test@example.com', $body);
    }
}
