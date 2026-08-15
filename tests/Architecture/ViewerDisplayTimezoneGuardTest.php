<?php

declare(strict_types=1);

namespace Tests\Architecture;

use App\Support\Timezone\ViewerDateTime;
use Filament\Support\Facades\FilamentTimezone;
use Tests\TestCase;

/**
 * TZ-4 permanent guard: authenticated portal views render the VIEWER's
 * clock, and Filament keeps its per-viewer timezone registration.
 *
 * The pattern this stops is the one TZ-AUD-004 was made of:
 *
 *     {{ $booking->starts_at->format('M j, Y') }}
 *
 * — an absolute instant formatted with no timezone at all, so the reader
 * gets UTC. And the subtler one from TZ-AUD-011:
 *
 *     {{ $booking->starts_at->timezone($booking->timezone)->format(...) }}
 *
 * — converted, but into the booking's ORIGIN snapshot rather than the
 * person reading the screen.
 *
 * Scoped to authenticated portal directories. Public marketing pages,
 * notification templates (which have their own recipient abstraction),
 * and admin Blade are all deliberately outside it.
 */
class ViewerDisplayTimezoneGuardTest extends TestCase
{
    /** Authenticated Student/Instructor/shared portal surfaces. */
    private const array PORTAL_VIEW_DIRECTORIES = [
        'resources/views/livewire/frontend',
        'resources/views/dashboard',
    ];

    /**
     * Date-ONLY model attributes. These have no timezone to convert
     * from, so `->format()` on them is correct and converting them would
     * slide the date a day for viewers west of UTC.
     */
    private const array DATE_ONLY_ATTRIBUTES = [
        'period_start',
        'period_end',
        'date_of_birth',
        'effective_from',
        'effective_until',
        'start_date',
        'end_date',
    ];

    public function test_the_viewer_presenter_and_helpers_exist(): void
    {
        $this->assertTrue(class_exists(ViewerDateTime::class));
        $this->assertTrue(function_exists('viewer_datetime'));
        $this->assertTrue(function_exists('viewer_date'));
        $this->assertTrue(function_exists('viewer_time'));
        $this->assertTrue(function_exists('viewer_datetime_labelled'));
    }

    public function test_portal_views_never_format_an_instant_without_a_timezone(): void
    {
        $offenders = [];

        foreach ($this->portalViews() as $file) {
            foreach ($this->offendingLines($file) as $line) {
                $offenders[] = str_replace(base_path().'/', '', $file).':'.$line;
            }
        }

        $this->assertSame([], $offenders, implode(' ', [
            'An authenticated portal view formatted an absolute instant without converting it to the',
            'viewer\'s timezone. Use viewer_datetime()/viewer_date()/viewer_time() or',
            '<x-ui.local-datetime> (TZ-AUD-004). Offending lines:', implode(', ', $offenders),
        ]));
    }

    public function test_portal_views_never_use_a_record_snapshot_as_the_viewer_timezone(): void
    {
        // Booking/Lesson/Meeting `timezone` is booking-origin provenance
        // (TZ-1), not "how this reader should see it". Displaying the
        // column as a labelled value is fine; using it to CONVERT is not.
        $offenders = [];

        foreach ($this->portalViews() as $file) {
            $contents = (string) file_get_contents($file);

            if (preg_match('/->timezone\s*\(\s*\$\w+(->|\[[\'"])(booking->)?timezone/', $contents) === 1) {
                $offenders[] = str_replace(base_path().'/', '', $file);
            }
        }

        $this->assertSame([], $offenders, implode(' ', [
            'A portal view converted an instant into a record\'s origin-snapshot timezone instead of the',
            'viewer\'s (TZ-AUD-011). Offending files:', implode(', ', $offenders),
        ]));
    }

    public function test_the_admin_panel_registers_a_dynamic_viewer_timezone(): void
    {
        // Pinning the single registration is worth more than scanning
        // 169 resources: Filament threads it through every date-time
        // column and picker, so if it is removed they all silently
        // revert to UTC at once.
        $source = (string) file_get_contents(base_path('app/Providers/Filament/AdminPanelProvider.php'));

        $this->assertStringContainsString('FilamentTimezone::set(', $source);
        $this->assertStringContainsString('ViewerDateTime::timezoneFor()', $source);
        $this->assertMatchesRegularExpression(
            '/FilamentTimezone::set\(\s*static fn/',
            $source,
            'Must be a Closure — a resolved string would freeze at boot, before anyone is authenticated.',
        );

        $this->assertTrue(class_exists(FilamentTimezone::class));
    }

    /**
     * Lines formatting a `*_at` attribute (or a `$…StartsAt`-style
     * variable) with no conversion. Date-only attributes are excluded,
     * as are lines already routed through the viewer helpers.
     *
     * @return list<int>
     */
    private function offendingLines(string $file): array
    {
        $lines = [];

        foreach (file($file) ?: [] as $index => $line) {
            if (str_contains($line, 'viewer_') || str_contains($line, 'ViewerDateTime') || str_contains($line, 'local-datetime')) {
                continue;
            }

            if (preg_match('/->\w*_at(\?)?->format\s*\(/', $line) !== 1) {
                continue;
            }

            foreach (self::DATE_ONLY_ATTRIBUTES as $dateOnly) {
                if (str_contains($line, $dateOnly.'->format') || str_contains($line, $dateOnly.'?->format')) {
                    continue 2;
                }
            }

            // Already converted explicitly (e.g. against a viewer
            // timezone the component resolved) — allowed.
            if (preg_match('/->timezone\s*\(/', $line) === 1) {
                continue;
            }

            $lines[] = $index + 1;
        }

        return $lines;
    }

    /** @return list<string> */
    private function portalViews(): array
    {
        $files = [];

        foreach (self::PORTAL_VIEW_DIRECTORIES as $directory) {
            $path = base_path($directory);

            if (! is_dir($path)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                    $files[] = $file->getPathname();
                }
            }
        }

        sort($files);

        return $files;
    }
}
