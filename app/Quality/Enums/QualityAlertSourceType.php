<?php

declare(strict_types=1);

namespace App\Quality\Enums;

/** What kind of record `source_id` refers to — a label, not a functioning Eloquent morph relation (no admin UI consumes it yet). */
enum QualityAlertSourceType: string
{
    case LessonReview = 'lesson_review';
    case Lesson = 'lesson';
    case Booking = 'booking';
    case ReviewReport = 'review_report';

    public function label(): string
    {
        return match ($this) {
            self::LessonReview => 'Lesson Review',
            self::Lesson => 'Lesson',
            self::Booking => 'Booking',
            self::ReviewReport => 'Review Report',
        };
    }
}
