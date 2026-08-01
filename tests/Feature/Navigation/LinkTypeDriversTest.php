<?php

declare(strict_types=1);

namespace Tests\Feature\Navigation;

use App\Enums\Navigation\NavigationLinkType;
use App\Enums\Navigation\NavigationStatus;
use App\Enums\Navigation\NavigationVisibility;
use App\Models\NavigationItem;
use App\Models\NavigationMenu;
use App\Models\Page;
use App\Models\Post;
use App\Models\PostCategory;
use App\Models\Tag;
use App\Navigation\Drivers\AnchorLinkDriver;
use App\Navigation\Drivers\CategoryLinkDriver;
use App\Navigation\Drivers\EmailLinkDriver;
use App\Navigation\Drivers\ExternalLinkDriver;
use App\Navigation\Drivers\PageLinkDriver;
use App\Navigation\Drivers\PhoneLinkDriver;
use App\Navigation\Drivers\PostLinkDriver;
use App\Navigation\Drivers\RouteLinkDriver;
use App\Navigation\Drivers\TagLinkDriver;
use App\Navigation\Drivers\UrlLinkDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LinkTypeDriversTest extends TestCase
{
    use RefreshDatabase;

    private function makeItem(array $attrs = []): NavigationItem
    {
        $menu = NavigationMenu::factory()->create(['status' => NavigationStatus::Published->value]);

        return NavigationItem::factory()->make(array_merge([
            'navigation_id' => $menu->id,
            'link_type' => NavigationLinkType::Url->value,
            'target' => '_self',
            'visibility' => NavigationVisibility::All->value,
            'is_active' => true,
        ], $attrs));
    }

    // ── UrlLinkDriver ─────────────────────────────────────────────────────

    public function test_url_driver_returns_raw_url(): void
    {
        $item = $this->makeItem(['url' => '/services', 'link_type' => NavigationLinkType::Url->value]);
        $driver = app(UrlLinkDriver::class);
        $link = $driver->resolve($item);

        $this->assertSame('/services', $link->url);
        $this->assertSame('_self', $link->target);
    }

    public function test_url_driver_supports_url_type(): void
    {
        $driver = app(UrlLinkDriver::class);

        $this->assertTrue($driver->supports(NavigationLinkType::Url));
        $this->assertFalse($driver->supports(NavigationLinkType::External));
    }

    public function test_url_driver_falls_back_to_hash_when_url_null(): void
    {
        $item = $this->makeItem(['url' => null, 'link_type' => NavigationLinkType::Url->value]);
        $driver = app(UrlLinkDriver::class);

        $this->assertSame('#', $driver->resolve($item)->url);
    }

    // ── ExternalLinkDriver ────────────────────────────────────────────────

    public function test_external_driver_sets_blank_target(): void
    {
        $item = $this->makeItem(['url' => 'https://example.com', 'link_type' => NavigationLinkType::External->value]);
        $driver = app(ExternalLinkDriver::class);
        $link = $driver->resolve($item);

        $this->assertSame('_blank', $link->target);
        $this->assertSame('https://example.com', $link->url);
    }

    public function test_external_driver_sets_noopener_rel_when_not_specified(): void
    {
        $item = $this->makeItem(['url' => 'https://ext.com', 'rel' => null, 'link_type' => NavigationLinkType::External->value]);
        $driver = app(ExternalLinkDriver::class);

        $this->assertSame('noopener noreferrer', $driver->resolve($item)->rel);
    }

    // ── EmailLinkDriver ───────────────────────────────────────────────────

    public function test_email_driver_prepends_mailto(): void
    {
        $item = $this->makeItem(['url' => 'hello@example.com', 'link_type' => NavigationLinkType::Email->value]);
        $driver = app(EmailLinkDriver::class);

        $this->assertSame('mailto:hello@example.com', $driver->resolve($item)->url);
    }

    public function test_email_driver_does_not_double_prepend(): void
    {
        $item = $this->makeItem(['url' => 'mailto:hello@example.com', 'link_type' => NavigationLinkType::Email->value]);
        $driver = app(EmailLinkDriver::class);

        $this->assertSame('mailto:hello@example.com', $driver->resolve($item)->url);
    }

    // ── PhoneLinkDriver ───────────────────────────────────────────────────

    public function test_phone_driver_prepends_tel(): void
    {
        $item = $this->makeItem(['url' => '+919876543210', 'link_type' => NavigationLinkType::Phone->value]);
        $driver = app(PhoneLinkDriver::class);

        $this->assertSame('tel:+919876543210', $driver->resolve($item)->url);
    }

    public function test_phone_driver_does_not_double_prepend(): void
    {
        $item = $this->makeItem(['url' => 'tel:+1234', 'link_type' => NavigationLinkType::Phone->value]);
        $driver = app(PhoneLinkDriver::class);

        $this->assertSame('tel:+1234', $driver->resolve($item)->url);
    }

    // ── AnchorLinkDriver ──────────────────────────────────────────────────

    public function test_anchor_driver_prepends_hash(): void
    {
        $item = $this->makeItem(['url' => 'section-top', 'link_type' => NavigationLinkType::Anchor->value]);
        $driver = app(AnchorLinkDriver::class);

        $this->assertSame('#section-top', $driver->resolve($item)->url);
    }

    public function test_anchor_driver_does_not_double_prepend(): void
    {
        $item = $this->makeItem(['url' => '#section-top', 'link_type' => NavigationLinkType::Anchor->value]);
        $driver = app(AnchorLinkDriver::class);

        $this->assertSame('#section-top', $driver->resolve($item)->url);
    }

    // ── RouteLinkDriver ───────────────────────────────────────────────────

    public function test_route_driver_resolves_named_route(): void
    {
        $item = $this->makeItem([
            'link_type' => NavigationLinkType::Route->value,
            'route_name' => 'home',
            'route_params' => null,
        ]);
        $driver = app(RouteLinkDriver::class);
        $link = $driver->resolve($item);

        $this->assertStringContainsString('/', $link->url);
        $this->assertNotSame('#', $link->url);
    }

    public function test_route_driver_falls_back_to_hash_for_invalid_route(): void
    {
        $item = $this->makeItem([
            'link_type' => NavigationLinkType::Route->value,
            'route_name' => 'non.existent.route.xyz',
        ]);
        $driver = app(RouteLinkDriver::class);

        $this->assertSame('#', $driver->resolve($item)->url);
    }

    public function test_route_driver_falls_back_to_hash_for_null_route(): void
    {
        $item = $this->makeItem([
            'link_type' => NavigationLinkType::Route->value,
            'route_name' => null,
        ]);
        $driver = app(RouteLinkDriver::class);

        $this->assertSame('#', $driver->resolve($item)->url);
    }

    // ── PageLinkDriver ────────────────────────────────────────────────────

    public function test_page_driver_resolves_page_url(): void
    {
        $page = Page::factory()->create(['slug' => 'about-us']);
        $item = $this->makeItem(['link_type' => NavigationLinkType::Page->value]);
        $item->setRelation('linkable', $page);

        $driver = app(PageLinkDriver::class);
        $link = $driver->resolve($item);

        $this->assertStringContainsString('about-us', $link->url);
    }

    public function test_page_driver_falls_back_to_hash_when_no_linkable(): void
    {
        $item = $this->makeItem(['link_type' => NavigationLinkType::Page->value]);
        $item->setRelation('linkable', null);

        $driver = app(PageLinkDriver::class);

        $this->assertSame('#', $driver->resolve($item)->url);
    }

    // ── PostLinkDriver ────────────────────────────────────────────────────

    public function test_post_driver_resolves_blog_url(): void
    {
        $post = Post::factory()->create(['slug' => 'my-post']);
        $item = $this->makeItem(['link_type' => NavigationLinkType::Post->value]);
        $item->setRelation('linkable', $post);

        $driver = app(PostLinkDriver::class);
        $link = $driver->resolve($item);

        $this->assertStringContainsString('blog/my-post', $link->url);
    }

    // ── CategoryLinkDriver ────────────────────────────────────────────────

    public function test_category_driver_resolves_category_url(): void
    {
        $category = PostCategory::factory()->create(['slug' => 'tutorials']);
        $item = $this->makeItem(['link_type' => NavigationLinkType::Category->value]);
        $item->setRelation('linkable', $category);

        $driver = app(CategoryLinkDriver::class);
        $link = $driver->resolve($item);

        $this->assertStringContainsString('tutorials', $link->url);
    }

    // ── TagLinkDriver ─────────────────────────────────────────────────────

    public function test_tag_driver_resolves_tag_url(): void
    {
        $tag = Tag::factory()->create(['slug' => 'laravel']);
        $item = $this->makeItem(['link_type' => NavigationLinkType::Tag->value]);
        $item->setRelation('linkable', $tag);

        $driver = app(TagLinkDriver::class);
        $link = $driver->resolve($item);

        $this->assertStringContainsString('laravel', $link->url);
    }
}
