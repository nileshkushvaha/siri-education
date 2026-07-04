<?php

declare(strict_types=1);

namespace App\Enums;

enum NewsletterSubscriberStatus: string
{
    case Subscribed = 'subscribed';
    case Unsubscribed = 'unsubscribed';
}
