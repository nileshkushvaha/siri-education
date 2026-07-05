<?php

declare(strict_types=1);

namespace App\Notifications\Contracts;

interface TransactionalEmail
{
    public function emailCategory(): string;

    public function senderKey(): string;
}
