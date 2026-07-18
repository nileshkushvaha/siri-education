<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\FaqStatus;
use App\Models\Faq;
use App\Models\FaqCategory;
use Illuminate\Database\Seeder;

/**
 * Idempotent baseline FAQ catalog (SRS §22.13/§22.14) covering the three
 * accordion surfaces already wired to the Faq model: the student
 * dashboard, the instructor dashboard, and the public "Become an
 * Instructor" page.
 */
class FaqSeeder extends Seeder
{
    /** @var array<int, array{name: string, description: string, icon: string, faqs: array<int, array{question: string, answer: string, audience: array<int, string>, featured?: bool}>}> */
    private const array CATEGORIES = [
        [
            'name' => 'Become an Instructor',
            'description' => 'Answers for prospective instructors exploring the application process.',
            'icon' => 'academic-cap',
            'faqs' => [
                [
                    'question' => 'Who can apply to teach?',
                    'answer' => 'University students, graduates, working professionals and experienced teachers can apply. Current school students up to and including grade 12 are not eligible to apply as instructors.',
                    'audience' => ['public'],
                    'featured' => true,
                ],
                [
                    'question' => 'What verification documents may be required?',
                    'answer' => 'Requirements are configurable and may include a government-issued ID, profile photograph, address proof, educational qualifications, professional certificates, resume, and an introduction video.',
                    'audience' => ['public'],
                ],
                [
                    'question' => 'How long does the review take?',
                    'answer' => 'Review time depends on application volume and whether the submitted information is complete. You can track your current application status from your dashboard.',
                    'audience' => ['public'],
                ],
                [
                    'question' => 'How much can I earn?',
                    'answer' => 'Potential earnings depend on completed and approved lessons, availability, pricing, student demand, and platform policies. The platform does not guarantee fixed income.',
                    'audience' => ['public'],
                ],
                [
                    'question' => 'What happens after I submit my application?',
                    'answer' => 'Your application moves through a verification workflow. You can track its status from your dashboard and will be notified once a decision has been made.',
                    'audience' => ['public'],
                ],
                [
                    'question' => 'Can I reapply if my application is not approved?',
                    'answer' => 'Contact support for details on reapplying after an application is not approved.',
                    'audience' => ['public'],
                ],
            ],
        ],
        [
            'name' => 'Student FAQs',
            'description' => 'Booking, wallet, and progress questions for students.',
            'icon' => 'user-graduate',
            'faqs' => [
                [
                    'question' => 'How do I book a lesson with an instructor?',
                    'answer' => 'Browse verified instructor profiles, choose a subject and an available time slot, and confirm your booking from the instructor\'s profile.',
                    'audience' => ['student'],
                    'featured' => true,
                ],
                [
                    'question' => 'What is a demo lesson?',
                    'answer' => 'A demo lesson is a free introductory lesson that lets you evaluate an instructor before booking paid lessons. Availability is limited according to platform rules.',
                    'audience' => ['student'],
                ],
                [
                    'question' => 'How does my wallet work?',
                    'answer' => 'Your wallet holds a digital balance in your billing currency for lesson payments, refunds, and rewards. You can recharge it from your dashboard.',
                    'audience' => ['student'],
                ],
                [
                    'question' => 'Can I cancel or reschedule a booked lesson?',
                    'answer' => 'Yes, from your dashboard, subject to the platform\'s cancellation and rescheduling policy.',
                    'audience' => ['student'],
                ],
                [
                    'question' => 'How do I track my learning progress?',
                    'answer' => 'Your dashboard shows your lessons, homework, and learning plan progress in one place.',
                    'audience' => ['student'],
                ],
                [
                    'question' => 'What if I\'m not satisfied with a lesson?',
                    'answer' => 'You can leave a review and private feedback after each lesson, and contact support if you have concerns.',
                    'audience' => ['student'],
                ],
            ],
        ],
        [
            'name' => 'Instructor FAQs',
            'description' => 'Teaching workflow, earnings, and availability questions for instructors.',
            'icon' => 'chalkboard-teacher',
            'faqs' => [
                [
                    'question' => 'How do I manage my upcoming lessons?',
                    'answer' => 'Your dashboard lists upcoming and recent lessons, including options to join, review student details, and record outcomes.',
                    'audience' => ['instructor'],
                    'featured' => true,
                ],
                [
                    'question' => 'How and when do I get paid?',
                    'answer' => 'Approved earnings from completed lessons are visible on your Earnings dashboard and are settled according to the platform\'s settlement schedule.',
                    'audience' => ['instructor'],
                ],
                [
                    'question' => 'Can I pause bookings temporarily?',
                    'answer' => 'Yes. Vacation mode lets you pause new bookings while keeping your profile and history intact — students see that you\'re temporarily unavailable.',
                    'audience' => ['instructor'],
                ],
                [
                    'question' => 'How is my instructor rating calculated?',
                    'answer' => 'Your rating is calculated from eligible student reviews on completed lessons and is visible on your Analytics dashboard along with quality and engagement trends.',
                    'audience' => ['instructor'],
                ],
                [
                    'question' => 'How do I see which students I\'m teaching?',
                    'answer' => 'Your Students section lists everyone you have an active or past teaching relationship with, along with their recent lesson history.',
                    'audience' => ['instructor'],
                ],
                [
                    'question' => 'Do I need to accept every booking request?',
                    'answer' => 'Booking availability is based on the schedule you set — students can only book lesson slots you\'ve made available.',
                    'audience' => ['instructor'],
                ],
            ],
        ],
    ];

    public function run(): void
    {
        foreach (self::CATEGORIES as $categoryIndex => $category) {
            $categoryModel = FaqCategory::query()->updateOrCreate(
                ['name' => $category['name']],
                [
                    'description' => $category['description'],
                    'icon' => $category['icon'],
                    'display_order' => $categoryIndex,
                    'is_active' => true,
                ],
            );

            foreach ($category['faqs'] as $faqIndex => $faq) {
                Faq::query()->updateOrCreate(
                    [
                        'faq_category_id' => $categoryModel->id,
                        'question' => $faq['question'],
                    ],
                    [
                        'answer' => $faq['answer'],
                        'audience' => $faq['audience'],
                        'display_order' => $faqIndex,
                        'featured' => $faq['featured'] ?? false,
                        'status' => FaqStatus::Published,
                        'published_at' => now(),
                    ],
                );
            }
        }
    }
}
