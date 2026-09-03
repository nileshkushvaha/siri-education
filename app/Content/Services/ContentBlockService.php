<?php

declare(strict_types=1);

namespace App\Content\Services;

use App\Content\Contracts\HasContentBlocks;
use App\Content\Models\ContentBlock;
use App\Enums\BlockType;
use Illuminate\Database\Eloquent\Collection;

/**
 * Unified block service for any content type implementing HasContentBlocks.
 *
 * Replaces the former PageBlock-only BlockService. All operations accept
 * a generic HasContentBlocks owner (Page, Post, or any future type).
 */
class ContentBlockService
{
    public function createBlock(
        HasContentBlocks $owner,
        BlockType|string $blockType,
        array $content,
        array $settings = [],
        ?int $sortOrder = null,
    ): ContentBlock {
        $sortOrder ??= ((int) $owner->blocks()->max('sort_order')) + 1;

        return $owner->blocks()->create([
            'block_type' => $blockType,
            'content' => $content,
            'settings' => $settings,
            'sort_order' => $sortOrder,
            'is_active' => true,
        ]);
    }

    public function updateBlock(ContentBlock $block, array $content, array $settings = []): bool
    {
        return $block->update([
            'content' => $content,
            'settings' => $settings,
        ]);
    }

    /**
     * Reorder blocks by providing an array of UUIDs in the desired order.
     */
    public function reorderBlocks(array $blockIds): void
    {
        foreach ($blockIds as $index => $blockId) {
            ContentBlock::where('id', $blockId)->update(['sort_order' => $index]);
        }
    }

    /**
     * Move a single block to an absolute position within its owner's block list.
     */
    public function moveBlock(ContentBlock $block, int $newPosition): bool
    {
        $owner = $block->blockable;

        if (! $owner) {
            return false;
        }

        $total = $owner->blocks()->count();

        if ($newPosition < 0 || $newPosition >= $total) {
            return false;
        }

        $siblings = $owner->blocks()
            ->orderBy('sort_order')
            ->get()
            ->filter(fn ($b) => $b->id !== $block->id)
            ->values();

        $siblings->splice($newPosition, 0, [$block]);

        foreach ($siblings as $index => $b) {
            $b->update(['sort_order' => $index]);
        }

        return true;
    }

    public function duplicateBlock(ContentBlock $block, ?int $sortOrder = null): ContentBlock
    {
        $owner = $block->blockable;
        $sortOrder ??= ($owner ? ((int) $owner->blocks()->max('sort_order')) + 1 : $block->sort_order + 1);

        $duplicate = $block->replicate(['id']);
        $duplicate->sort_order = $sortOrder;
        $duplicate->save();

        return $duplicate;
    }

    public function deleteBlock(ContentBlock $block): bool
    {
        return (bool) $block->delete();
    }

    public function forceDeleteBlock(ContentBlock $block): bool
    {
        return (bool) $block->forceDelete();
    }

    public function restoreBlock(ContentBlock $block): bool
    {
        return (bool) $block->restore();
    }

    public function toggleBlockActive(ContentBlock $block): bool
    {
        return $block->update(['is_active' => ! $block->is_active]);
    }

    public function getBlocks(HasContentBlocks $owner, bool $activeOnly = false): Collection
    {
        $query = $owner->blocks();

        if ($activeOnly) {
            $query->where('is_active', true);
        }

        return $query->orderBy('sort_order')->get();
    }

    // ── Default content / settings templates ────────────────────────────

    public function getDefaultContent(BlockType $type): array
    {
        return match ($type) {
            BlockType::Hero => [
                'title' => '',
                'subtitle' => '',
                'image' => null,
                'button_text' => '',
                'button_link' => '',
                'button_style' => 'primary',
            ],
            // Mirrors BlockContentHydrator::hydrateHeroCarousel() — the
            // hydrator is the canonical shape, and a default that omitted
            // a key it fills would hand the form a half-built block.
            BlockType::HeroCarousel => [
                'prefix_text' => '',
                'suffix_text' => '',
                'footnote' => '',
                'slides' => [],
                'autoplay' => true,
                'interval' => 5000,
                'show_arrows' => true,
            ],
            BlockType::RichText => ['text' => ''],
            BlockType::Image => ['image' => null, 'caption' => '', 'alt_text' => ''],
            BlockType::Gallery => ['images' => [], 'columns' => 3, 'gap' => 'md'],
            BlockType::Video => ['video_url' => '', 'caption' => '', 'thumbnail' => null],
            BlockType::CTA => [
                'title' => '',
                'description' => '',
                'button_text' => '',
                'button_link' => '',
                'button_style' => 'primary',
                'image' => null,
            ],
            BlockType::FAQ => ['items' => []],
            BlockType::Accordion => ['items' => [], 'single_open' => true],
            BlockType::Tabs => ['items' => []],
            BlockType::Team => ['title' => '', 'description' => '', 'members' => [], 'columns' => 3],
            BlockType::Features => ['eyebrow' => '', 'title' => '', 'description' => '', 'features' => [], 'columns' => 3],
            BlockType::FeaturedTeachers => ['eyebrow' => '', 'title' => '', 'description' => '', 'limit' => 4, 'columns' => 4, 'link_label' => '', 'link_url' => ''],
            BlockType::Testimonials => ['testimonials' => [], 'columns' => 3],
            BlockType::Pricing => ['eyebrow' => '', 'title' => '', 'description' => '', 'plans' => [], 'columns' => 3],
            BlockType::Newsletter => [
                'eyebrow' => '',
                'title' => '',
                'description' => '',
                'name_label' => '',
                'name_placeholder' => '',
                'email_label' => '',
                'email_placeholder' => '',
                'button_text' => '',
                'consent_text' => '',
                'success_message' => '',
            ],
            BlockType::Statistics => ['stats' => [], 'columns' => 3],
            BlockType::Timeline => ['items' => []],
            BlockType::Button => ['text' => '', 'link' => '', 'style' => 'primary', 'size' => 'medium'],
            BlockType::Divider => ['style' => 'solid', 'color' => '#000000', 'height' => 1],
            BlockType::Spacer => ['height' => 40],
            BlockType::Map => ['latitude' => '0', 'longitude' => '0', 'zoom' => 13, 'address' => '', 'title' => ''],
            BlockType::ContactForm => [
                'title' => '',
                'description' => '',
                'fields' => [],
                'button_text' => '',
                'success_message' => '',
            ],
            BlockType::ContactInfo => [
                'eyebrow' => '',
                'title' => '',
                'description' => '',
                'items' => [],
            ],
        };
    }

    public function getDefaultSettings(BlockType $type): array
    {
        return [
            'background_color' => null,
            'text_color' => null,
            'padding' => 'medium',
            'margin' => 'medium',
            'text_alignment' => 'left',
            'animation' => 'none',
            'animation_delay' => 0,
            'container_width' => 'full',
            'custom_class' => '',
        ];
    }
}
