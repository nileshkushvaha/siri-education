<?php

declare(strict_types=1);

namespace App\Jobs\Auth;

use App\Models\User;
use App\Services\AuditTrailService;
use App\Services\Profile\ProfileCompletionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Copies the Google profile picture into the user's `avatar` media
 * collection — once, and only while they have no avatar of their own.
 * Best-effort: a failure here must never affect the sign-in that queued it.
 */
class ImportGoogleAvatarJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 120, 600];

    public int $uniqueFor = 600;

    /** Only Google's own image CDN is ever fetched (SSRF guard). */
    private const array ALLOWED_HOST_SUFFIXES = ['googleusercontent.com', 'ggpht.com'];

    public function __construct(
        public readonly int $userId,
        public readonly string $pictureUrl,
    ) {
        $this->onQueue('notifications');
    }

    public function uniqueId(): string
    {
        return (string) $this->userId;
    }

    public static function isAllowedUrl(string $url): bool
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));

        if (($parts['scheme'] ?? '') !== 'https' || $host === '') {
            return false;
        }

        foreach (self::ALLOWED_HOST_SUFFIXES as $suffix) {
            if ($host === $suffix || str_ends_with($host, '.'.$suffix)) {
                return true;
            }
        }

        return false;
    }

    public function handle(AuditTrailService $audit, ProfileCompletionService $completion): void
    {
        $user = User::query()->with('profile')->find($this->userId);

        if ($user === null || $user->profile === null || $user->profile->hasMedia('avatar')) {
            return;
        }

        if (! self::isAllowedUrl($this->pictureUrl)) {
            Log::notice('Google avatar import skipped: URL not on the Google image CDN.', ['user_id' => $this->userId]);

            return;
        }

        // Google appends a size directive ("=s96-c"); ask for a larger
        // square so the stored original and its thumb conversion stay sharp.
        $url = preg_replace('/=s\d+(-c)?$/', '=s400-c', $this->pictureUrl) ?? $this->pictureUrl;

        try {
            $user->profile
                ->addMediaFromUrl($url, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'])
                ->usingFileName('google-avatar-'.$this->userId.'.jpg')
                ->toMediaCollection('avatar');
        } catch (Throwable $e) {
            Log::warning('Google avatar import failed.', ['user_id' => $this->userId, 'exception' => $e::class, 'message' => $e->getMessage()]);

            return;
        }

        $audit->logSystem('profile', 'avatar_changed', 'Profile photo imported from Google account', $user, ['source' => 'google']);
        $completion->recalculateAndStore($user);
    }
}
