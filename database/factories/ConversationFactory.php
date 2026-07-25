<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Messaging\Enums\ConversationStatus;
use App\Models\Booking;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Conversation>
 */
class ConversationFactory extends Factory
{
    public function definition(): array
    {
        $student = User::factory();
        $instructor = User::factory();
        $booking = Booking::factory();

        return [
            'student_id' => $student,
            'instructor_id' => $instructor,
            'context_type' => Booking::class,
            'context_id' => $booking,
            'status' => ConversationStatus::Active,
            'opened_by' => $student,
            'last_message_at' => now(),
        ];
    }

    public function between(User $student, User $instructor): static
    {
        return $this->state(fn (): array => [
            'student_id' => $student->id,
            'instructor_id' => $instructor->id,
            'opened_by' => $student->id,
        ]);
    }
}
