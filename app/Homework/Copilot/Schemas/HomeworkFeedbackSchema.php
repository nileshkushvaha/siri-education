<?php

declare(strict_types=1);

namespace App\Homework\Copilot\Schemas;

use App\Ai\Contracts\AiSchemaInterface;

/**
 * The only response shape an AI feedback draft may take.
 *
 * THE OMISSIONS ARE THE FEATURE. There is no `score`, `grade`, `mark`,
 * `percentage`, `correct`, or `pass` property, so a model physically
 * cannot return a grade through this contract — the guarantee is
 * structural, not a matter of the prompt being obeyed. Assessment stays
 * with the instructor, in the pre-existing `grade` field that no AI code
 * path writes.
 *
 * `suggested_feedback` is a DRAFT addressed to the student, written for
 * the instructor to edit. It is never published as-is by any code path;
 * the instructor retypes or edits it in their own editor and submits
 * through the existing review flow.
 */
final class HomeworkFeedbackSchema implements AiSchemaInterface
{
    public const string KEY = 'homework_feedback';

    private const int MAX_ITEMS = 5;

    public function key(): string
    {
        return self::KEY;
    }

    public function name(): string
    {
        return 'homework_feedback';
    }

    public function jsonSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'summary' => ['type' => 'string'],
                'strengths' => ['type' => 'array', 'items' => ['type' => 'string']],
                'improvements' => ['type' => 'array', 'items' => ['type' => 'string']],
                'suggested_feedback' => ['type' => 'string'],
                'confidence' => ['type' => 'number'],
                'requires_instructor_review' => ['type' => 'boolean'],
            ],
            'required' => ['summary', 'strengths', 'improvements', 'suggested_feedback', 'confidence', 'requires_instructor_review'],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return [
            'summary' => ['required', 'string', 'min:20', 'max:1000'],
            'strengths' => ['present', 'array', 'max:'.self::MAX_ITEMS],
            'strengths.*' => ['string', 'min:3', 'max:300'],
            'improvements' => ['present', 'array', 'max:'.self::MAX_ITEMS],
            'improvements.*' => ['string', 'min:3', 'max:300'],
            // The draft an instructor will edit — long enough to be
            // useful, capped so it stays a starting point rather than a
            // wall of text nobody reads before publishing.
            'suggested_feedback' => ['required', 'string', 'min:20', 'max:2000'],
            'confidence' => ['required', 'numeric', 'between:0,1'],
            'requires_instructor_review' => ['required', 'boolean'],
        ];
    }
}
