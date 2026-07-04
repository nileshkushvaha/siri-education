<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\NewsletterSubscriptionService;
use Illuminate\View\View;

final class NewsletterUnsubscribeController extends Controller
{
    public function __invoke(string $token, NewsletterSubscriptionService $subscriptions): View
    {
        $success = $subscriptions->unsubscribe($token);

        return view('newsletter.unsubscribe', ['success' => $success]);
    }
}
