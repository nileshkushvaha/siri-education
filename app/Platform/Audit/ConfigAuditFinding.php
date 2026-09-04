<?php

declare(strict_types=1);

namespace App\Platform\Audit;

/** One line of the configuration audit: where, how bad, what, and what to do. */
final readonly class ConfigAuditFinding
{
    public const string FAIL = 'fail';

    public const string WARN = 'warn';

    public const string OK = 'ok';

    public function __construct(
        public string $section,
        public string $severity,
        public string $message,
        public ?string $fix = null,
    ) {}

    public static function fail(string $section, string $message, ?string $fix = null): self
    {
        return new self($section, self::FAIL, $message, $fix);
    }

    public static function warn(string $section, string $message, ?string $fix = null): self
    {
        return new self($section, self::WARN, $message, $fix);
    }

    public static function ok(string $section, string $message): self
    {
        return new self($section, self::OK, $message);
    }

    /** @return array{section: string, severity: string, message: string, fix: ?string} */
    public function toArray(): array
    {
        return ['section' => $this->section, 'severity' => $this->severity, 'message' => $this->message, 'fix' => $this->fix];
    }
}
