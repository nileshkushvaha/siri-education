<?php

declare(strict_types=1);

namespace App\Ai\Exceptions;

/**
 * A provider call failed. Thrown only by adapters, only with an
 * already-classified failure code and an already-redacted message.
 */
class AiProviderException extends AiException {}
