<?php

declare(strict_types=1);

namespace App\Ai\Evaluation\Enums;

/**
 * Why an output was not useful — a FIXED code list, never free text.
 *
 * That constraint is a privacy decision, not a UX one. A free-text box
 * in an analytics table is where a reviewer eventually pastes the
 * sentence that bothered them, which is student content, landing in a
 * table built for aggregation and read by people with reporting access
 * rather than by the domain's own access rules. Reviewers who need to
 * write something have their domain's own review note, which lives
 * under those rules.
 *
 * The codes are chosen to map onto a prompt change: each one suggests a
 * different edit to the instruction that produced the output.
 */
enum AiFeedbackReason: string
{
    /** Said something that was not true of the underlying record. */
    case Inaccurate = 'inaccurate';

    /** True but obvious — no information the reviewer did not already have. */
    case TooGeneric = 'too_generic';

    /** Missed something the input actually contained. */
    case MissedSomething = 'missed_something';

    /** Right content, wrong register for the audience. */
    case WrongTone = 'wrong_tone';

    /** Overstated its certainty, or drew a conclusion the data did not support. */
    case Overconfident = 'overconfident';

    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Inaccurate => 'Inaccurate — not true of the record',
            self::TooGeneric => 'Too generic — told me nothing new',
            self::MissedSomething => 'Missed something in the input',
            self::WrongTone => 'Wrong tone for the audience',
            self::Overconfident => 'Overconfident — claimed more than the data supports',
            self::Other => 'Other',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
