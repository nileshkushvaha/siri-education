<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmailLog extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'id',
        'notification_id',
        'notification_type',
        'category',
        'provider',
        'mailer',
        'status',
        'subject',
        'from_address',
        'from_name',
        'to',
        'cc',
        'bcc',
        'queued',
        'provider_message_id',
        'error',
        'attempts',
        'metadata',
        'sent_at',
        'delivered_at',
        'failed_at',
    ];

    protected function casts(): array
    {
        return [
            'to' => 'array',
            'cc' => 'array',
            'bcc' => 'array',
            'queued' => 'bool',
            'metadata' => 'array',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }
}
