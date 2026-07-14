<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Append-only claim ledger — see NotificationIdempotencyGuard. No update path exists. */
class NotificationDispatchLog extends Model
{
    public $timestamps = false;

    protected $table = 'notification_dispatch_log';

    protected $fillable = [
        'idempotency_key',
        'notification_class',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
        ];
    }
}
