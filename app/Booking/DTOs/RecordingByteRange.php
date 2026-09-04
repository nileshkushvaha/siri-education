<?php

declare(strict_types=1);

namespace App\Booking\DTOs;

use InvalidArgumentException;

/**
 * One contiguous, inclusive byte window of a stored recording — the
 * unit an HTTP `Range: bytes=start-end` request maps onto.
 *
 * Exists so RecordingStorage::read() can honour a seek without any
 * backend detail reaching the caller: the Drive adapter translates it
 * into a Range header on the media download, the filesystem adapter
 * into an fseek(). Both are invisible to the controller, which only
 * ever hands the window in and streams the bytes out.
 *
 * Always fully resolved against the object's known size before it is
 * constructed (see fromHttpHeader()), so an adapter never has to deal
 * with an open-ended or unsatisfiable request.
 */
final readonly class RecordingByteRange
{
    public function __construct(
        public int $start,
        public int $end,
    ) {
        if ($start < 0 || $end < $start) {
            throw new InvalidArgumentException('A recording byte range must be a non-negative, non-empty window.');
        }
    }

    /**
     * Resolves a single `bytes=` Range header against a known total
     * size. Returns null when the header is absent or is not a single
     * simple range (multipart ranges are deliberately unsupported —
     * no browser video element sends them). Returns false when the
     * header parses but is unsatisfiable, which the caller must turn
     * into a 416.
     */
    public static function fromHttpHeader(?string $header, int $totalBytes): self|false|null
    {
        if ($header === null || $totalBytes <= 0) {
            return null;
        }

        if (preg_match('/^bytes=(\d*)-(\d*)$/', trim($header), $m) !== 1) {
            return null;
        }

        [$rawStart, $rawEnd] = [$m[1], $m[2]];

        if ($rawStart === '' && $rawEnd === '') {
            return null;
        }

        if ($rawStart === '') {
            // Suffix range: the last N bytes.
            $suffix = (int) $rawEnd;

            if ($suffix <= 0) {
                return false;
            }

            return new self(max(0, $totalBytes - $suffix), $totalBytes - 1);
        }

        $start = (int) $rawStart;
        $end = $rawEnd === '' ? $totalBytes - 1 : min((int) $rawEnd, $totalBytes - 1);

        if ($start >= $totalBytes || $end < $start) {
            return false;
        }

        return new self($start, $end);
    }

    public function length(): int
    {
        return $this->end - $this->start + 1;
    }

    /** The `Content-Range` value for a 206 response. */
    public function contentRange(int $totalBytes): string
    {
        return sprintf('bytes %d-%d/%d', $this->start, $this->end, $totalBytes);
    }

    /** The `Range` request-header value an upstream HTTP backend expects. */
    public function toHttpHeader(): string
    {
        return sprintf('bytes=%d-%d', $this->start, $this->end);
    }
}
