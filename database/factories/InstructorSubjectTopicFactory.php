<?php

namespace Database\Factories;

use App\Models\InstructorSubjectTopic;
use App\Models\SubjectTopic;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InstructorSubjectTopic>
 */
class InstructorSubjectTopicFactory extends Factory
{
    public function definition(): array
    {
        return [
            'teacher_id' => User::factory(),
            // Order matters: the subject_id closure below reads the already
            // materialized topic, so the topic key must come first.
            'subject_topic_id' => SubjectTopic::factory(),
            'subject_id' => fn (array $attributes) => SubjectTopic::find($attributes['subject_topic_id'])?->subject_id,
            'academic_level_id' => null,
            'is_primary' => false,
            'is_active' => true,
            'approved_at' => now(),
        ];
    }

    public function unapproved(): static
    {
        return $this->state(['approved_at' => null, 'approved_by' => null]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
