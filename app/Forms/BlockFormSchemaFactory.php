<?php

namespace App\Forms;

use App\Enums\BlockType;
use App\Forms\Blocks\AccordionBlockForm;
use App\Forms\Blocks\ButtonBlockForm;
use App\Forms\Blocks\ContactFormBlockForm;
use App\Forms\Blocks\ContactInfoBlockForm;
use App\Forms\Blocks\CTABlockForm;
use App\Forms\Blocks\DividerBlockForm;
use App\Forms\Blocks\FAQBlockForm;
use App\Forms\Blocks\FeaturedTeachersBlockForm;
use App\Forms\Blocks\FeaturesBlockForm;
use App\Forms\Blocks\GalleryBlockForm;
use App\Forms\Blocks\HeroBlockForm;
use App\Forms\Blocks\ImageBlockForm;
use App\Forms\Blocks\MapBlockForm;
use App\Forms\Blocks\NewsletterBlockForm;
use App\Forms\Blocks\PricingBlockForm;
use App\Forms\Blocks\RichTextBlockForm;
use App\Forms\Blocks\SpacerBlockForm;
use App\Forms\Blocks\StatisticsBlockForm;
use App\Forms\Blocks\TabsBlockForm;
use App\Forms\Blocks\TeamBlockForm;
use App\Forms\Blocks\TestimonialsBlockForm;
use App\Forms\Blocks\TimelineBlockForm;
use App\Forms\Blocks\VideoBlockForm;

/**
 * Factory for generating form schemas for each block type.
 * Routes block types to their respective form classes.
 */
class BlockFormSchemaFactory
{
    public static function make(BlockType $blockType): array
    {
        return match ($blockType) {
            BlockType::Hero => HeroBlockForm::schema(),
            BlockType::RichText => RichTextBlockForm::schema(),
            BlockType::Image => ImageBlockForm::schema(),
            BlockType::Gallery => GalleryBlockForm::schema(),
            BlockType::Video => VideoBlockForm::schema(),
            BlockType::CTA => CTABlockForm::schema(),
            BlockType::FAQ => FAQBlockForm::schema(),
            BlockType::Accordion => AccordionBlockForm::schema(),
            BlockType::Tabs => TabsBlockForm::schema(),
            BlockType::Team => TeamBlockForm::schema(),
            BlockType::Features => FeaturesBlockForm::schema(),
            BlockType::FeaturedTeachers => FeaturedTeachersBlockForm::schema(),
            BlockType::Testimonials => TestimonialsBlockForm::schema(),
            BlockType::Pricing => PricingBlockForm::schema(),
            BlockType::Newsletter => NewsletterBlockForm::schema(),
            BlockType::Statistics => StatisticsBlockForm::schema(),
            BlockType::Timeline => TimelineBlockForm::schema(),
            BlockType::Button => ButtonBlockForm::schema(),
            BlockType::Divider => DividerBlockForm::schema(),
            BlockType::Spacer => SpacerBlockForm::schema(),
            BlockType::Map => MapBlockForm::schema(),
            BlockType::ContactForm => ContactFormBlockForm::schema(),
            BlockType::ContactInfo => ContactInfoBlockForm::schema(),
        };
    }
}
