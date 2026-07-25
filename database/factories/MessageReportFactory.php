<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Messaging\Enums\MessageReportReason;
use App\Messaging\Enums\MessageReportStatus;
use App\Models\Message;
use App\Models\MessageReport;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MessageReport>
 */
class MessageReportFactory extends Factory
{
    public function definition(): array
    {
        return [
            'message_id' => Message::factory(),
            'reporter_id' => User::factory(),
            'reason' => MessageReportReason::Other,
            'status' => MessageReportStatus::Pending,
        ];
    }
}
