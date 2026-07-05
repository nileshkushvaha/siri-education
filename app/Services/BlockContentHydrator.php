<?php

namespace App\Services;

use App\Enums\BlockType;

/**
 * Converts stored JSON content back to form-friendly arrays for editing.
 * Ensures all expected fields exist with default values.
 */
class BlockContentHydrator
{
    public static function hydrate(BlockType $blockType, array $jsonContent): array
    {
        return match ($blockType) {
            BlockType::Hero => self::hydrateHero($jsonContent),
            BlockType::RichText => self::hydrateRichText($jsonContent),
            BlockType::Image => self::hydrateImage($jsonContent),
            BlockType::Gallery => self::hydrateGallery($jsonContent),
            BlockType::Video => self::hydrateVideo($jsonContent),
            BlockType::CTA => self::hydrateCTA($jsonContent),
            BlockType::FAQ => self::hydrateFAQ($jsonContent),
            BlockType::Accordion => self::hydrateAccordion($jsonContent),
            BlockType::Tabs => self::hydrateTabs($jsonContent),
            BlockType::Team => self::hydrateTeam($jsonContent),
            BlockType::Features => self::hydrateFeatures($jsonContent),
            BlockType::FeaturedTeachers => self::hydrateFeaturedTeachers($jsonContent),
            BlockType::Testimonials => self::hydrateTestimonials($jsonContent),
            BlockType::Pricing => self::hydratePricing($jsonContent),
            BlockType::Newsletter => self::hydrateNewsletter($jsonContent),
            BlockType::Statistics => self::hydrateStatistics($jsonContent),
            BlockType::Timeline => self::hydrateTimeline($jsonContent),
            BlockType::Button => self::hydrateButton($jsonContent),
            BlockType::Divider => self::hydrateDivider($jsonContent),
            BlockType::Spacer => self::hydrateSpacer($jsonContent),
            BlockType::Map => self::hydrateMap($jsonContent),
            BlockType::ContactForm => self::hydrateContactForm($jsonContent),
            BlockType::ContactInfo => self::hydrateContactInfo($jsonContent),
        };
    }

    private static function hydrateHero(array $content): array
    {
        return [
            'title' => $content['title'] ?? '',
            'subtitle' => $content['subtitle'] ?? '',
            'image' => $content['image'] ?? null,
            'button_text' => $content['button_text'] ?? '',
            'button_link' => $content['button_link'] ?? '',
            'button_style' => $content['button_style'] ?? 'primary',
        ];
    }

    private static function hydrateRichText(array $content): array
    {
        return [
            'text' => $content['text'] ?? '',
        ];
    }

    private static function hydrateImage(array $content): array
    {
        return [
            'image' => $content['image'] ?? null,
            'caption' => $content['caption'] ?? '',
            'alt_text' => $content['alt_text'] ?? '',
        ];
    }

    private static function hydrateGallery(array $content): array
    {
        return [
            'images' => $content['images'] ?? [],
            'columns' => $content['columns'] ?? 3,
            'gap' => $content['gap'] ?? 'md',
        ];
    }

    private static function hydrateVideo(array $content): array
    {
        return [
            'video_url' => $content['video_url'] ?? '',
            'caption' => $content['caption'] ?? '',
            'thumbnail' => $content['thumbnail'] ?? null,
        ];
    }

    private static function hydrateCTA(array $content): array
    {
        return [
            'title' => $content['title'] ?? '',
            'description' => $content['description'] ?? '',
            'button_text' => $content['button_text'] ?? '',
            'button_link' => $content['button_link'] ?? '',
            'button_style' => $content['button_style'] ?? 'primary',
            'background_color' => $content['background_color'] ?? '#ffffff',
            'text_color' => $content['text_color'] ?? '#000000',
        ];
    }

    private static function hydrateFAQ(array $content): array
    {
        return [
            'items' => $content['items'] ?? [],
        ];
    }

    private static function hydrateAccordion(array $content): array
    {
        return [
            'items' => $content['items'] ?? [],
            'single_open' => $content['single_open'] ?? true,
        ];
    }

    private static function hydrateTabs(array $content): array
    {
        $items = array_map(static function (array $item): array {
            $title = $item['title'] ?? $item['tab_title'] ?? $item['label'] ?? '';
            $tabContent = $item['content'] ?? $item['tab_content'] ?? '';

            return [
                'title' => $title,
                'label' => $title,
                'content' => $tabContent,
                // backward compatibility for older forms
                'tab_title' => $title,
                'tab_content' => $tabContent,
            ];
        }, $content['items'] ?? []);

        return [
            'items' => $items,
        ];
    }

    private static function hydrateTeam(array $content): array
    {
        return [
            'title' => $content['title'] ?? '',
            'description' => $content['description'] ?? '',
            'members' => $content['members'] ?? [],
            'columns' => $content['columns'] ?? 3,
        ];
    }

    private static function hydrateFeatures(array $content): array
    {
        return [
            'eyebrow' => $content['eyebrow'] ?? '',
            'title' => $content['title'] ?? '',
            'description' => $content['description'] ?? '',
            'features' => $content['features'] ?? [],
            'columns' => $content['columns'] ?? 3,
        ];
    }

    private static function hydrateFeaturedTeachers(array $content): array
    {
        return [
            'eyebrow' => $content['eyebrow'] ?? '',
            'title' => $content['title'] ?? '',
            'description' => $content['description'] ?? '',
            'limit' => $content['limit'] ?? 4,
            'columns' => $content['columns'] ?? 4,
            'link_label' => $content['link_label'] ?? '',
            'link_url' => $content['link_url'] ?? '',
        ];
    }

    private static function hydrateTestimonials(array $content): array
    {
        return [
            'testimonials' => $content['testimonials'] ?? [],
            'columns' => $content['columns'] ?? 3,
        ];
    }

    private static function hydrateNewsletter(array $content): array
    {
        return [
            'eyebrow' => $content['eyebrow'] ?? '',
            'title' => $content['title'] ?? '',
            'description' => $content['description'] ?? '',
            'name_label' => $content['name_label'] ?? '',
            'name_placeholder' => $content['name_placeholder'] ?? '',
            'email_label' => $content['email_label'] ?? '',
            'email_placeholder' => $content['email_placeholder'] ?? '',
            'button_text' => $content['button_text'] ?? '',
            'consent_text' => $content['consent_text'] ?? '',
            'success_message' => $content['success_message'] ?? '',
        ];
    }

    private static function hydratePricing(array $content): array
    {
        return [
            'eyebrow' => $content['eyebrow'] ?? '',
            'title' => $content['title'] ?? '',
            'description' => $content['description'] ?? '',
            'plans' => $content['plans'] ?? [],
            'columns' => $content['columns'] ?? 3,
        ];
    }

    private static function hydrateStatistics(array $content): array
    {
        return [
            'stats' => $content['stats'] ?? [],
            'columns' => $content['columns'] ?? 4,
        ];
    }

    private static function hydrateTimeline(array $content): array
    {
        return [
            'items' => $content['items'] ?? [],
        ];
    }

    private static function hydrateButton(array $content): array
    {
        return [
            'text' => $content['text'] ?? '',
            'link' => $content['link'] ?? '',
            'style' => $content['style'] ?? 'primary',
            'size' => $content['size'] ?? 'md',
            'alignment' => $content['alignment'] ?? 'left',
        ];
    }

    private static function hydrateDivider(array $content): array
    {
        return [
            'style' => $content['style'] ?? 'solid',
            'color' => $content['color'] ?? '#e5e7eb',
            'width' => $content['width'] ?? '100',
        ];
    }

    private static function hydrateSpacer(array $content): array
    {
        return [
            'height' => $content['height'] ?? '60',
        ];
    }

    private static function hydrateMap(array $content): array
    {
        return [
            'latitude' => $content['latitude'] ?? '0',
            'longitude' => $content['longitude'] ?? '0',
            'address' => $content['address'] ?? '',
            'title' => $content['title'] ?? '',
            'zoom' => $content['zoom'] ?? '15',
        ];
    }

    private static function hydrateContactForm(array $content): array
    {
        $fields = array_map(static function (array $field, int $index): array {
            $name = $field['name'] ?? "field_{$index}";

            return [
                'name' => $name,
                'label' => $field['label'] ?? '',
                'type' => $field['type'] ?? 'text',
                'placeholder' => $field['placeholder'] ?? '',
                'required' => (bool) ($field['required'] ?? false),
                'options' => $field['options'] ?? '',
            ];
        }, $content['fields'] ?? [], array_keys($content['fields'] ?? []));

        return [
            'title' => $content['title'] ?? '',
            'description' => $content['description'] ?? '',
            'fields' => $fields,
            'button_text' => $content['button_text'] ?? '',
            'success_message' => $content['success_message'] ?? '',
        ];
    }

    private static function hydrateContactInfo(array $content): array
    {
        return [
            'eyebrow' => $content['eyebrow'] ?? '',
            'title' => $content['title'] ?? '',
            'description' => $content['description'] ?? '',
            'items' => $content['items'] ?? [],
        ];
    }
}
