<?php

declare(strict_types=1);

namespace App\Messaging\Safety\Schemas;

use App\Ai\Contracts\AiSchemaInterface;

/**
 * The only response shape a communication-risk analysis may take.
 *
 * THE OMISSIONS ARE THE ENFORCEMENT BOUNDARY. There is no
 * `block_message`, `ban_user`, `suspend_account`, `restrict_user`,
 * `remove_content` or `action` property, so a model physically cannot
 * instruct the platform to do anything. It describes; people decide.
 *
 * `category` includes `none` as a valid answer. Most messages that
 * reach analysis turn out to be innocent, and a schema that forced a
 * category would guarantee a false positive on every one of them.
 *
 * `reason` is capped short and the prompt requires it to describe the
 * message, never the person — an admin reading a queue of findings
 * should see "mentions arranging payment outside the platform", never
 * "this instructor is trying to defraud SIRI".
 */
final class CommunicationRiskSchema implements AiSchemaInterface
{
    public const string KEY = 'communication_risk';

    public function key(): string
    {
        return self::KEY;
    }

    public function name(): string
    {
        return 'communication_risk';
    }

    public function jsonSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'category' => [
                    'type' => 'string',
                    'enum' => ['contact_sharing', 'payment_bypass', 'other_policy_risk', 'none'],
                ],
                'risk_level' => ['type' => 'string', 'enum' => ['low', 'medium', 'high']],
                'reason' => ['type' => 'string'],
                'confidence' => ['type' => 'number'],
                'requires_review' => ['type' => 'boolean'],
            ],
            'required' => ['category', 'risk_level', 'reason', 'confidence', 'requires_review'],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return [
            'category' => ['required', 'string', 'in:contact_sharing,payment_bypass,other_policy_risk,none'],
            'risk_level' => ['required', 'string', 'in:low,medium,high'],
            // Long enough to be useful to a reviewer, short enough that
            // it cannot become a narrative about a person.
            'reason' => ['required', 'string', 'min:10', 'max:300'],
            'confidence' => ['required', 'numeric', 'between:0,1'],
            'requires_review' => ['required', 'boolean'],
        ];
    }
}
