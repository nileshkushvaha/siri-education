<?php

namespace App\Enums;

enum BlockType: string
{
    case Hero = 'hero';
    case RichText = 'rich_text';
    case Image = 'image';
    case Gallery = 'gallery';
    case Video = 'video';
    case CTA = 'cta';
    case FAQ = 'faq';
    case Accordion = 'accordion';
    case Tabs = 'tabs';
    case Team = 'team';
    case Features = 'features';
    case FeaturedCourses = 'featured_courses';
    case FeaturedTeachers = 'featured_teachers';
    case Testimonials = 'testimonials';
    case Pricing = 'pricing';
    case Newsletter = 'newsletter';
    case Statistics = 'statistics';
    case Timeline = 'timeline';
    case Button = 'button';
    case Divider = 'divider';
    case Spacer = 'spacer';
    case Map = 'map';
    case ContactForm = 'contact_form';
    case ContactInfo = 'contact_info';

    public function label(): string
    {
        return match ($this) {
            self::Hero => 'Hero',
            self::RichText => 'Rich Text',
            self::Image => 'Image',
            self::Gallery => 'Gallery',
            self::Video => 'Video',
            self::CTA => 'Call to Action',
            self::FAQ => 'FAQ',
            self::Accordion => 'Accordion',
            self::Tabs => 'Tabs',
            self::Team => 'Team',
            self::Features => 'Features',
            self::FeaturedCourses => 'Featured Courses',
            self::FeaturedTeachers => 'Featured Teachers',
            self::Testimonials => 'Testimonials',
            self::Pricing => 'Pricing',
            self::Newsletter => 'Newsletter',
            self::Statistics => 'Statistics',
            self::Timeline => 'Timeline',
            self::Button => 'Button',
            self::Divider => 'Divider',
            self::Spacer => 'Spacer',
            self::Map => 'Map',
            self::ContactForm => 'Contact Form',
            self::ContactInfo => 'Contact Info',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Hero => 'heroicon-m-photo',
            self::RichText => 'heroicon-m-document-text',
            self::Image => 'heroicon-m-image',
            self::Gallery => 'heroicon-m-photo-square',
            self::Video => 'heroicon-m-video-camera',
            self::CTA => 'heroicon-m-megaphone',
            self::FAQ => 'heroicon-m-question-mark-circle',
            self::Accordion => 'heroicon-m-list-bullet',
            self::Tabs => 'heroicon-m-rectangle-group',
            self::Team => 'heroicon-m-user-group',
            self::Features => 'heroicon-m-squares-2x2',
            self::FeaturedCourses => 'heroicon-m-academic-cap',
            self::FeaturedTeachers => 'heroicon-m-user-group',
            self::Testimonials => 'heroicon-m-star',
            self::Pricing => 'heroicon-m-banknotes',
            self::Newsletter => 'heroicon-m-envelope-open',
            self::Statistics => 'heroicon-m-chart-bar',
            self::Timeline => 'heroicon-m-arrow-long-down',
            self::Button => 'heroicon-m-hand-raised',
            self::Divider => 'heroicon-m-minus',
            self::Spacer => 'heroicon-m-pause',
            self::Map => 'heroicon-m-map',
            self::ContactForm => 'heroicon-m-envelope',
            self::ContactInfo => 'heroicon-m-map-pin',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Hero => 'Large banner with title, subtitle, image, and CTA button',
            self::RichText => 'Rich text content with formatting',
            self::Image => 'Single image with caption',
            self::Gallery => 'Multiple images in a grid',
            self::Video => 'Embedded video player',
            self::CTA => 'Call-to-action section with button',
            self::FAQ => 'Frequently asked questions',
            self::Accordion => 'Expandable accordion sections',
            self::Tabs => 'Tabbed content sections',
            self::Team => 'Team member profiles',
            self::Features => 'Feature cards with optional links',
            self::FeaturedCourses => 'CMS-authored featured course cards',
            self::FeaturedTeachers => 'Featured public instructors from the instructor service',
            self::Testimonials => 'Customer testimonials/reviews',
            self::Pricing => 'Pricing plans and included features',
            self::Newsletter => 'Newsletter signup section',
            self::Statistics => 'Statistics/metrics display',
            self::Timeline => 'Timeline of events',
            self::Button => 'Single button element',
            self::Divider => 'Visual divider/separator',
            self::Spacer => 'Whitespace/spacing element',
            self::Map => 'Map/location display',
            self::ContactForm => 'Contact form',
            self::ContactInfo => 'Phone, email & address cards',
        };
    }

    public function category(): string
    {
        return match ($this) {
            self::Hero, self::Divider, self::Spacer => 'Layout',
            self::RichText, self::Button => 'Content',
            self::Image, self::Gallery, self::Video => 'Media',
            self::CTA => 'Call to Action',
            self::FAQ, self::Accordion, self::Tabs => 'Interactive',
            self::Team, self::Features, self::FeaturedCourses, self::FeaturedTeachers, self::Testimonials, self::Pricing, self::Statistics, self::Timeline => 'Components',
            self::Newsletter => 'Forms',
            self::Map => 'Location',
            self::ContactForm => 'Forms',
            self::ContactInfo => 'Forms',
        };
    }

    /**
     * Get Filament badge color for block type
     */
    public function color(): string
    {
        return match ($this) {
            self::Hero => 'info',
            self::RichText => 'success',
            self::Image, self::Gallery, self::Video => 'warning',
            self::CTA => 'danger',
            self::FAQ, self::Accordion, self::Tabs => 'purple',
            self::Team, self::Features, self::FeaturedCourses, self::FeaturedTeachers, self::Testimonials, self::Pricing, self::Statistics, self::Timeline => 'blue',
            self::Newsletter => 'amber',
            self::Button => 'pink',
            self::Divider, self::Spacer => 'gray',
            self::Map => 'cyan',
            self::ContactForm => 'amber',
            self::ContactInfo => 'cyan',
        };
    }

    /**
     * Get all block types grouped by category
     */
    public static function grouped(): array
    {
        $grouped = [];

        foreach (self::cases() as $type) {
            $category = $type->category();
            if (! isset($grouped[$category])) {
                $grouped[$category] = [];
            }
            $grouped[$category][] = $type;
        }

        return $grouped;
    }
}
