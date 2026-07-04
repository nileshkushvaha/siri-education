<?php

namespace App\Services;

use App\Enums\BlockType;
use Illuminate\Support\Str;

/**
 * Converts form data to JSON for storage in database.
 * Normalizes and validates the data structure for each block type.
 */
class BlockContentConverter
{
    public static function convert(BlockType $blockType, array $formData): array
    {
        return match ($blockType) {
            BlockType::Hero => self::heroToJson($formData),
            BlockType::RichText => self::richTextToJson($formData),
            BlockType::Image => self::imageToJson($formData),
            BlockType::Gallery => self::galleryToJson($formData),
            BlockType::Video => self::videoToJson($formData),
            BlockType::CTA => self::ctaToJson($formData),
            BlockType::FAQ => self::faqToJson($formData),
            BlockType::Accordion => self::accordionToJson($formData),
            BlockType::Tabs => self::tabsToJson($formData),
            BlockType::Team => self::teamToJson($formData),
            BlockType::Features => self::featuresToJson($formData),
            BlockType::FeaturedCourses => self::featuredCoursesToJson($formData),
            BlockType::FeaturedTeachers => self::featuredTeachersToJson($formData),
            BlockType::Testimonials => self::testimonialsToJson($formData),
            BlockType::Pricing => self::pricingToJson($formData),
            BlockType::Newsletter => self::newsletterToJson($formData),
            BlockType::Statistics => self::statisticsToJson($formData),
            BlockType::Timeline => self::timelineToJson($formData),
            BlockType::Button => self::buttonToJson($formData),
            BlockType::Divider => self::dividerToJson($formData),
            BlockType::Spacer => self::spacerToJson($formData),
            BlockType::Map => self::mapToJson($formData),
            BlockType::ContactForm => self::contactFormToJson($formData),
            BlockType::ContactInfo => self::contactInfoToJson($formData),
        };
    }

    private static function heroToJson(array $data): array
    {
        return [
            'title' => $data['title'] ?? '',
            'subtitle' => $data['subtitle'] ?? '',
            'image' => $data['image'] ?? null,
            'button_text' => $data['button_text'] ?? '',
            'button_link' => $data['button_link'] ?? '',
            'button_style' => $data['button_style'] ?? 'primary',
        ];
    }

    private static function richTextToJson(array $data): array
    {
        return [
            'text' => $data['text'] ?? '',
        ];
    }

    private static function imageToJson(array $data): array
    {
        return [
            'image' => $data['image'] ?? null,
            'caption' => $data['caption'] ?? '',
            'alt_text' => $data['alt_text'] ?? '',
        ];
    }

    private static function galleryToJson(array $data): array
    {
        return [
            'images' => $data['images'] ?? [],
            'columns' => (int) ($data['columns'] ?? 3),
            'gap' => $data['gap'] ?? 'md',
        ];
    }

    private static function videoToJson(array $data): array
    {
        return [
            'video_url' => $data['video_url'] ?? '',
            'caption' => $data['caption'] ?? '',
            'thumbnail' => $data['thumbnail'] ?? null,
        ];
    }

    private static function ctaToJson(array $data): array
    {
        return [
            'title' => $data['title'] ?? '',
            'description' => $data['description'] ?? '',
            'button_text' => $data['button_text'] ?? '',
            'button_link' => $data['button_link'] ?? '',
            'button_style' => $data['button_style'] ?? 'primary',
            'background_color' => $data['background_color'] ?? '#ffffff',
            'text_color' => $data['text_color'] ?? '#000000',
        ];
    }

    private static function faqToJson(array $data): array
    {
        $items = $data['items'] ?? [];

        return [
            'items' => array_values(array_filter($items, fn ($item) => ! empty($item['question'] ?? null))),
        ];
    }

    private static function accordionToJson(array $data): array
    {
        $items = $data['items'] ?? [];

        return [
            'items' => array_values(array_filter($items, fn ($item) => ! empty($item['title'] ?? null))),
            'single_open' => (bool) ($data['single_open'] ?? true),
        ];
    }

    private static function tabsToJson(array $data): array
    {
        $items = $data['items'] ?? [];

        $normalizedItems = array_values(array_filter(array_map(
            static function (array $item): array {
                return [
                    'title' => $item['title'] ?? $item['tab_title'] ?? '',
                    'content' => $item['content'] ?? $item['tab_content'] ?? '',
                ];
            },
            $items
        ), static fn (array $item): bool => ! empty($item['title'])));

        return [
            'items' => $normalizedItems,
        ];
    }

    private static function teamToJson(array $data): array
    {
        $members = $data['members'] ?? [];

        return [
            'title' => $data['title'] ?? '',
            'description' => $data['description'] ?? '',
            'members' => array_values(array_filter($members, fn ($member) => ! empty($member['name'] ?? null))),
            'columns' => (int) ($data['columns'] ?? 3),
        ];
    }

    private static function featuresToJson(array $data): array
    {
        $features = $data['features'] ?? [];

        $normalizedFeatures = array_values(array_filter(array_map(
            static fn (array $feature): array => [
                'icon' => $feature['icon'] ?? '',
                'title' => $feature['title'] ?? '',
                'description' => $feature['description'] ?? '',
                'link' => $feature['link'] ?? '',
                'link_label' => $feature['link_label'] ?? '',
            ],
            $features
        ), static fn (array $feature): bool => $feature['title'] !== ''));

        return [
            'eyebrow' => $data['eyebrow'] ?? '',
            'title' => $data['title'] ?? '',
            'description' => $data['description'] ?? '',
            'features' => $normalizedFeatures,
            'columns' => (int) ($data['columns'] ?? 3),
        ];
    }

    private static function featuredCoursesToJson(array $data): array
    {
        $courses = $data['courses'] ?? [];

        $normalizedCourses = array_values(array_filter(array_map(
            static fn (array $course): array => [
                'title' => $course['title'] ?? '',
                'category' => $course['category'] ?? '',
                'description' => $course['description'] ?? '',
                'instructor' => $course['instructor'] ?? '',
                'image' => $course['image'] ?? null,
                'url' => $course['url'] ?? '',
                'price' => $course['price'] ?? '',
                'duration' => $course['duration'] ?? '',
                'level' => $course['level'] ?? '',
                'badge' => $course['badge'] ?? '',
            ],
            $courses
        ), static fn (array $course): bool => $course['title'] !== ''));

        return [
            'eyebrow' => $data['eyebrow'] ?? '',
            'title' => $data['title'] ?? '',
            'description' => $data['description'] ?? '',
            'courses' => $normalizedCourses,
            'columns' => (int) ($data['columns'] ?? 3),
            'link_label' => $data['link_label'] ?? '',
            'link_url' => $data['link_url'] ?? '',
        ];
    }

    private static function featuredTeachersToJson(array $data): array
    {
        return [
            'eyebrow' => $data['eyebrow'] ?? '',
            'title' => $data['title'] ?? '',
            'description' => $data['description'] ?? '',
            'limit' => max(1, min(12, (int) ($data['limit'] ?? 4))),
            'columns' => (int) ($data['columns'] ?? 4),
            'link_label' => $data['link_label'] ?? '',
            'link_url' => $data['link_url'] ?? '',
        ];
    }

    private static function testimonialsToJson(array $data): array
    {
        $testimonials = $data['testimonials'] ?? [];

        return [
            'testimonials' => array_values(array_filter($testimonials, fn ($t) => ! empty($t['text'] ?? null))),
            'columns' => (int) ($data['columns'] ?? 3),
        ];
    }

    private static function pricingToJson(array $data): array
    {
        $plans = $data['plans'] ?? [];

        $normalizedPlans = array_values(array_filter(array_map(
            static function (array $plan): array {
                return [
                    'name' => $plan['name'] ?? '',
                    'description' => $plan['description'] ?? '',
                    'price' => $plan['price'] ?? '',
                    'period' => $plan['period'] ?? '',
                    'features' => self::normalizeList($plan['features'] ?? []),
                    'button_text' => $plan['button_text'] ?? '',
                    'button_link' => $plan['button_link'] ?? '',
                    'highlighted' => (bool) ($plan['highlighted'] ?? false),
                    'badge' => $plan['badge'] ?? '',
                ];
            },
            $plans
        ), static fn (array $plan): bool => $plan['name'] !== ''));

        return [
            'eyebrow' => $data['eyebrow'] ?? '',
            'title' => $data['title'] ?? '',
            'description' => $data['description'] ?? '',
            'plans' => $normalizedPlans,
            'columns' => (int) ($data['columns'] ?? 3),
        ];
    }

    private static function newsletterToJson(array $data): array
    {
        return [
            'eyebrow' => $data['eyebrow'] ?? '',
            'title' => $data['title'] ?? '',
            'description' => $data['description'] ?? '',
            'name_label' => $data['name_label'] ?? '',
            'name_placeholder' => $data['name_placeholder'] ?? '',
            'email_label' => $data['email_label'] ?? '',
            'email_placeholder' => $data['email_placeholder'] ?? '',
            'button_text' => $data['button_text'] ?? '',
            'consent_text' => $data['consent_text'] ?? '',
            'success_message' => $data['success_message'] ?? '',
        ];
    }

    private static function statisticsToJson(array $data): array
    {
        $stats = $data['stats'] ?? [];

        return [
            'stats' => array_values(array_filter($stats, fn ($s) => ! empty($s['number'] ?? null))),
            'columns' => (int) ($data['columns'] ?? 4),
        ];
    }

    private static function timelineToJson(array $data): array
    {
        $items = $data['items'] ?? [];

        return [
            'items' => array_values(array_filter($items, fn ($item) => ! empty($item['title'] ?? null))),
        ];
    }

    private static function buttonToJson(array $data): array
    {
        return [
            'text' => $data['text'] ?? '',
            'link' => $data['link'] ?? '',
            'style' => $data['style'] ?? 'primary',
            'size' => $data['size'] ?? 'md',
            'alignment' => $data['alignment'] ?? 'left',
        ];
    }

    private static function dividerToJson(array $data): array
    {
        return [
            'style' => $data['style'] ?? 'solid',
            'color' => $data['color'] ?? '#e5e7eb',
            'width' => (int) ($data['width'] ?? 100),
        ];
    }

    private static function spacerToJson(array $data): array
    {
        return [
            'height' => (int) ($data['height'] ?? 60),
        ];
    }

    private static function mapToJson(array $data): array
    {
        return [
            'latitude' => $data['latitude'] ?? '0',
            'longitude' => $data['longitude'] ?? '0',
            'address' => $data['address'] ?? '',
            'title' => $data['title'] ?? '',
            'zoom' => (int) ($data['zoom'] ?? 15),
        ];
    }

    private static function contactFormToJson(array $data): array
    {
        $fields = $data['fields'] ?? [];

        $normalizedFields = array_values(array_filter(array_map(
            static function (array $field, int $index): array {
                $label = trim((string) ($field['label'] ?? ''));
                $name = $field['name'] ?? Str::slug($label !== '' ? $label : "field_{$index}", '_');

                return [
                    'name' => $name,
                    'label' => $label,
                    'type' => $field['type'] ?? 'text',
                    'placeholder' => $field['placeholder'] ?? '',
                    'required' => (bool) ($field['required'] ?? false),
                    'options' => $field['options'] ?? '',
                ];
            },
            $fields,
            array_keys($fields)
        ), static fn (array $field): bool => $field['label'] !== ''));

        return [
            'title' => $data['title'] ?? '',
            'description' => $data['description'] ?? '',
            'fields' => $normalizedFields,
            'button_text' => $data['button_text'] ?? '',
            'success_message' => $data['success_message'] ?? '',
        ];
    }

    private static function contactInfoToJson(array $data): array
    {
        $items = $data['items'] ?? [];

        return [
            'eyebrow' => $data['eyebrow'] ?? '',
            'title' => $data['title'] ?? '',
            'description' => $data['description'] ?? '',
            'items' => array_values(array_filter($items, fn ($i) => ! empty($i['value'] ?? null))),
        ];
    }

    private static function normalizeList(array|string $items): array
    {
        if (is_string($items)) {
            $items = preg_split('/\r\n|\r|\n/', $items) ?: [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $item): string => trim((string) $item),
            $items
        )));
    }
}
