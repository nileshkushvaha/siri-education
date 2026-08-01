<?php

declare(strict_types=1);

namespace Tests\Unit\Navigation;

use App\Navigation\DTOs\ResolvedLink;
use Tests\TestCase;

class ResolvedLinkTest extends TestCase
{
    public function test_is_external_returns_true_for_blank_target(): void
    {
        $link = new ResolvedLink('https://example.com', '_blank', 'noopener noreferrer', []);

        $this->assertTrue($link->isExternal());
    }

    public function test_is_external_returns_false_for_self_target(): void
    {
        $link = new ResolvedLink('/about', '_self', null, []);

        $this->assertFalse($link->isExternal());
    }

    public function test_is_empty_returns_true_for_hash(): void
    {
        $link = new ResolvedLink('#', '_self', null, []);

        $this->assertTrue($link->isEmpty());
    }

    public function test_is_empty_returns_true_for_empty_string(): void
    {
        $link = new ResolvedLink('', '_self', null, []);

        $this->assertTrue($link->isEmpty());
    }

    public function test_is_empty_returns_false_for_real_url(): void
    {
        $link = new ResolvedLink('/contact', '_self', null, []);

        $this->assertFalse($link->isEmpty());
    }

    public function test_properties_are_readonly(): void
    {
        $link = new ResolvedLink('/page', '_self', null, ['data-id' => '1']);

        $this->assertSame('/page', $link->url);
        $this->assertSame('_self', $link->target);
        $this->assertNull($link->rel);
        $this->assertSame(['data-id' => '1'], $link->attributes);
    }

    public function test_immutability_via_reflection(): void
    {
        $link = new ResolvedLink('/x', '_self', null, []);
        $ref = new \ReflectionProperty($link, 'url');

        $this->assertTrue($ref->isReadOnly());
    }
}
