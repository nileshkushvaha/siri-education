<?php

declare(strict_types=1);

namespace App\Quality\Intelligence\Schemas;

use App\Ai\Contracts\AiSchemaInterface;

/**
 * The only response shape an admin quality insight may take.
 *
 * Shaped to keep the output ADVISORY. There is no score, no grade, no
 * rank and no recommended action field — a model cannot emit
 * "suspend this instructor" through this schema because there is
 * nowhere to put it. `recommended_review` is explicitly a suggestion
 * about what a HUMAN should look at, and the string caps are tight
 * enough that the model must summarize rather than transcribe reviews
 * back to us.
 *
 * `strict: true` in the provider request additionally requires every
 * declared property to be present, so a partial object is rejected at
 * decode time as well as by the local rules.
 */
final class QualityInsightSchema implements AiSchemaInterface
{
    public const string KEY = 'quality_insight';

    private const int MAX_ITEMS = 5;

    public function key(): string
    {
        return self::KEY;
    }

    public function name(): string
    {
        return 'quality_insight';
    }

    public function jsonSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'summary' => ['type' => 'string'],
                'strengths' => ['type' => 'array', 'items' => ['type' => 'string']],
                'concerns' => ['type' => 'array', 'items' => ['type' => 'string']],
                'recommended_review' => ['type' => 'string'],
                'confidence' => ['type' => 'number'],
                'requires_human_review' => ['type' => 'boolean'],
            ],
            'required' => ['summary', 'strengths', 'concerns', 'recommended_review', 'confidence', 'requires_human_review'],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return [
            'summary' => ['required', 'string', 'min:20', 'max:1200'],
            'strengths' => ['present', 'array', 'max:'.self::MAX_ITEMS],
            'strengths.*' => ['string', 'min:3', 'max:300'],
            'concerns' => ['present', 'array', 'max:'.self::MAX_ITEMS],
            'concerns.*' => ['string', 'min:3', 'max:300'],
            // Nullable rather than required: "nothing in particular
            // needs a closer look" is a legitimate, useful answer.
            'recommended_review' => ['nullable', 'string', 'max:600'],
            'confidence' => ['required', 'numeric', 'between:0,1'],
            'requires_human_review' => ['required', 'boolean'],
        ];
    }
}
