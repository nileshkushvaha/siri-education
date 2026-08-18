<?php

declare(strict_types=1);

namespace App\Lessons\Summaries\Schemas;

use App\Ai\Contracts\AiSchemaInterface;

/**
 * The only response shape an AI lesson summary may take.
 *
 * THE OMISSIONS ARE THE FEATURE. There is no `mastery_level`,
 * `progress_percentage`, `student_level`, `grade` or `score` property,
 * so a model physically cannot return one. Any of those would read as
 * an authoritative metric the moment it was stored, and would invite a
 * later feature to chart it — which is precisely how "AI decides
 * progress" happens without anyone deciding to let it.
 *
 * `strengths_observed` is the closest the schema comes to assessment,
 * and it is bounded to what the instructor's own notes support: the
 * prompt forbids inferring competence the notes do not state.
 */
final class LessonSummarySchema implements AiSchemaInterface
{
    public const string KEY = 'lesson_summary';

    private const int MAX_ITEMS = 6;

    public function key(): string
    {
        return self::KEY;
    }

    public function name(): string
    {
        return 'lesson_summary';
    }

    public function jsonSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'lesson_summary' => ['type' => 'string'],
                'topics_covered' => ['type' => 'array', 'items' => ['type' => 'string']],
                'strengths_observed' => ['type' => 'array', 'items' => ['type' => 'string']],
                'practice_recommendations' => ['type' => 'array', 'items' => ['type' => 'string']],
                'next_focus' => ['type' => 'array', 'items' => ['type' => 'string']],
                'confidence' => ['type' => 'number'],
                'requires_instructor_review' => ['type' => 'boolean'],
            ],
            'required' => [
                'lesson_summary', 'topics_covered', 'strengths_observed',
                'practice_recommendations', 'next_focus', 'confidence',
                'requires_instructor_review',
            ],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return [
            'lesson_summary' => ['required', 'string', 'min:20', 'max:1500'],
            'topics_covered' => ['present', 'array', 'max:'.self::MAX_ITEMS],
            'topics_covered.*' => ['string', 'min:2', 'max:200'],
            'strengths_observed' => ['present', 'array', 'max:'.self::MAX_ITEMS],
            'strengths_observed.*' => ['string', 'min:3', 'max:300'],
            'practice_recommendations' => ['present', 'array', 'max:'.self::MAX_ITEMS],
            'practice_recommendations.*' => ['string', 'min:3', 'max:300'],
            'next_focus' => ['present', 'array', 'max:'.self::MAX_ITEMS],
            'next_focus.*' => ['string', 'min:3', 'max:300'],
            'confidence' => ['required', 'numeric', 'between:0,1'],
            'requires_instructor_review' => ['required', 'boolean'],
        ];
    }
}
