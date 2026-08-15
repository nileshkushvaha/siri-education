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

    // ── TZ-5 additions ──────────────────────────────────────────────────

    public function test_filament_never_filters_a_utc_timestamp_by_utc_calendar_day(): void
    {
        // `whereDate('created_at', $date)` asks MySQL for the UTC day,
        // which is neither the admin's day nor the reporting day
        // (TZ-AUD-008). AdminDayRange turns a selected date into a
        // half-open UTC range in whichever calendar actually owns it.
        //
        // Not a global ban: whereDate() stays correct for DATE columns
        // (holidays.date, effective_from, period_start), which is why
        // this guard is scoped to app/Filament and to *_at columns.
        $offenders = [];

        foreach ($this->filamentFiles() as $file) {
            if (preg_match('/whereDate\s*\(\s*[\'"]\w*_at[\'"]/', $this->strippedSource($file)) === 1) {
                $offenders[] = str_replace(base_path().'/', '', $file);
            }
        }

        $this->assertSame([], $offenders, implode(' ', [
            'A Filament filter compared a UTC timestamp against a calendar date. Use',
            'AdminDayRange::viewerDay()/reportingDay() so the selected date means a real local day.',
            'Offending files:', implode(', ', $offenders),
        ]));
    }

    public function test_filament_closures_render_instants_through_the_viewer_presenter(): void
    {
        // The TZ-4 residual: Placeholder/tooltip/summary closures are not
        // dateTime() components, so FilamentTimezone never reaches them
        // and they rendered raw app-timezone values beside now-localized
        // columns.
        $offenders = [];

        foreach ($this->filamentFiles() as $file) {
            $source = $this->strippedSource($file);

            if (preg_match('/->\w*_at(\?)?->format\s*\(/', $source) === 1) {
                $offenders[] = str_replace(base_path().'/', '', $file);
            }
        }

        $this->assertSame([], $offenders, implode(' ', [
            'A Filament closure formatted an instant directly. Use ViewerDateTime so it matches the',
            'admin-local timestamps rendered around it. Offending files:', implode(', ', $offenders),
        ]));
    }

    public function test_reporting_never_freezes_a_single_offset_for_a_whole_period(): void
    {
        // The TZ-AUD-010 anti-pattern: one offset captured at period
        // start and reused for every row, which is an hour wrong after a
        // DST transition. LocalDaySql segments the period instead.
        $offenders = [];

        foreach ($this->reportingFiles() as $file) {
            $source = $this->strippedSource($file);

            if (str_contains($source, "start->format('P')") || str_contains($source, 'CONVERT_TZ')) {
                $offenders[] = str_replace(base_path().'/', '', $file);
            }
        }

        $this->assertSame([], $offenders, implode(' ', [
            'Reporting reused a period-start offset (or CONVERT_TZ, which needs MySQL timezone tables',
            'this deployment does not have). Use LocalDaySql. Offending files:', implode(', ', $offenders),
        ]));
    }

    /** @return list<string> */
    private function filamentFiles(): array
    {
        return $this->phpFilesIn(app_path('Filament'));
    }

    /** @return list<string> */
    private function reportingFiles(): array
    {
        return array_values(array_filter(
            $this->phpFilesIn(app_path('Reporting')),
            static fn (string $file): bool => ! str_ends_with($file, 'LocalDaySql.php'),
        ));
    }

    /** @return list<string> */
    private function phpFilesIn(string $path): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /** Executable source only, so comments describing a banned pattern never trip a scan. */
    private function strippedSource(string $file): string
    {
        $kept = '';

        foreach (token_get_all((string) file_get_contents($file)) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }

                $kept .= $token[1];

                continue;
            }

            $kept .= $token;
        }

        return $kept;
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
