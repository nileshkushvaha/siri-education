<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\NewsletterSubscriberStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class NewsletterSubscriber extends Model
{
    use HasUuids;

    protected $fillable = [
        'email',
        'name',
        'status',
        'unsubscribe_token',
        'source',
        'subscribed_at',
        'unsubscribed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => NewsletterSubscriberStatus::class,
            'subscribed_at' => 'immutable_datetime',
            'unsubscribed_at' => 'immutable_datetime',
        ];
    }

    public function isSubscribed(): bool
    {
        return $this->status === NewsletterSubscriberStatus::Subscribed;
    }
}
