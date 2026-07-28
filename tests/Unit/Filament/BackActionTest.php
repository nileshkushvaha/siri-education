<?php

declare(strict_types=1);

namespace Tests\Unit\Filament;

use App\Filament\Support\Presentation\BackAction;
use Filament\Support\Icons\Heroicon;
use Tests\TestCase;

/**
 * The Stage 2 deterministic Back-action factory. Mirrors the pattern
 * already hand-written on ActivityLog/LoginHistory's View pages
 * (Action::make('back')->icon('heroicon-o-arrow-left')->color('gray'))
 * rather than introducing a new visual language.
 */
class BackActionTest extends TestCase
{
    public function test_returns_null_when_no_destination_is_known(): void
    {
        $this->assertNull(BackAction::make(null));
        $this->assertNull(BackAction::make(''));
    }

    public function test_builds_a_gray_labeled_back_arrow_action_pointing_at_the_given_url(): void
    {
        $action = BackAction::make('/admin/review-tags', 'Back to Review Tags');

        $this->assertNotNull($action);
        $this->assertSame('back', $action->getName());
        $this->assertSame('Back to Review Tags', $action->getLabel());
        $this->assertSame('gray', $action->getColor());
        $this->assertSame(Heroicon::OutlinedArrowLeft, $action->getIcon());
        $this->assertSame('/admin/review-tags', $action->getUrl());
    }

    public function test_defaults_to_a_plain_back_label_and_key(): void
    {
        $action = BackAction::make('/admin/faq/faq-categories');

        $this->assertSame('Back', $action->getLabel());
        $this->assertSame('back', $action->getName());
    }

    public function test_a_custom_action_key_is_honored(): void
    {
        $action = BackAction::make('/admin/settings/payment-settings', 'Back to Payment Settings', 'backToPaymentSettings');

        $this->assertSame('backToPaymentSettings', $action->getName());
    }
}
