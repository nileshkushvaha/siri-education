<?php

use App\Console\Commands\AutoCompleteLessons;
use App\Console\Commands\PublishScheduledContent;
use App\Console\Commands\ReleaseExpiredBookingReservations;
use App\Console\Commands\ReleaseInstructorEarnings;
use App\Models\LoginHistory;
use App\Models\SchedulerHistory;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Auto-publish scheduled Pages and Posts every minute.
// The command is idempotent: it only touches records whose published_at <= now().
// runInBackground() removed: ScheduledTaskFinished fires immediately after fork,
// recording ~5ms instead of actual duration. withoutOverlapping() prevents concurrency.
app(Schedule::class)
    ->command(PublishScheduledContent::class)
    ->everyMinute()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/scheduled-publishing.log'));

// Prune scheduler_histories older than 30 days (MassPrunable trait on the model).
app(Schedule::class)
    ->command('model:prune', ['--model' => SchedulerHistory::class])
    ->daily()
    ->appendOutputTo(storage_path('logs/model-prune.log'));

// Prune login_histories older than 90 days (MassPrunable trait on the model).
app(Schedule::class)
    ->command('model:prune', ['--model' => LoginHistory::class])
    ->daily()
    ->appendOutputTo(storage_path('logs/model-prune.log'));

// Clean activity_log entries older than clean_after_days (config/activitylog.php, default 365 days).
app(Schedule::class)
    ->command('activitylog:clean')
    ->weekly()
    ->appendOutputTo(storage_path('logs/activitylog-clean.log'));

// Finalize open lessons past the auto-completion grace period (24h after
// ends_at): recorded no-shows become no-show outcomes, the rest complete.
// Idempotent: finalized lessons never re-enter the sweep.
app(Schedule::class)
    ->command(AutoCompleteLessons::class)
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/lessons-auto-complete.log'));

// Release instructor earnings whose hold period (dispute window) has
// lapsed. Idempotent; gated by InstructorEarningSettings; no external
// payout runs — settlement stays a manual admin action.
app(Schedule::class)
    ->command(ReleaseInstructorEarnings::class)
    ->hourly()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/instructor-earnings-release.log'));

// Release unpaid booking reservations whose payment hold has lapsed.
// Idempotent: only touches pending bookings with reserved_until < now().
app(Schedule::class)
    ->command(ReleaseExpiredBookingReservations::class)
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/booking-reservations.log'));
