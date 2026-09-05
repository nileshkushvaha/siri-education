<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\GoogleActivationResult;
use App\Enums\LoginResult;

/** Result of GoogleActivationService::activate() plus the context its message needs. */
final readonly class GoogleActivationOutcome
{
    public function __construct(
        public GoogleActivationResult $result,
        public ?string $googleEmail = null,
        public ?LoginResult $loginResult = null,
    ) {}

    public function isSuccessful(): bool
    {
        return $this->result->isSuccessful();
    }

    public function message(): string
    {
        return $this->result->message($this->googleEmail, $this->loginResult);
    }
}
