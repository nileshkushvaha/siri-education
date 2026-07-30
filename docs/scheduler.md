# Scheduler Monitor

## Overview

Admin page at `/admin/scheduler`. System group, sort 2.

Displays all scheduled tasks with last run status, next run time, cron expression (human-readable via `lorisleiva/cron-translator`), mutex lock state, and run history.

## Key files

| File | Purpose |
|---|---|
| `app/Services/SchedulerService.php` | Task list, runNow(), getRecentHistory() |
| `app/Models/SchedulerHistory.php` | Run history model, MassPrunable (30-day TTL) |
| `app/Filament/Pages/SchedulerMonitorPage.php` | Filament page, System group sort 2 |
| `app/Policies/SchedulerMonitorPolicy.php` | viewPage, runTask |

## Run history

`SchedulerHistory` is written by three listeners registered in `AppServiceProvider`:

- `ScheduledTaskFinished` → status `success`
- `ScheduledTaskFailed` → status `failed`
- `ScheduledTaskSkipped` → status `skipped` (mutex overlap)

Records prune after 30 days via `MassPrunable`. Scheduled: `php artisan model:prune --model=SchedulerHistory` daily.

## Manual run

`SchedulerService::runNow($taskId)` executes the task immediately, records history, and logs via `AuditTrailService`:
- Authenticated user → `logUser()` with `scheduler_monitor` / `manually_ran`
- No user (CLI) → `logSystem()` with same channel/event

## Mutex detection

Tasks using `withoutOverlapping()` show a lock indicator when the mutex is held (`Cache::has($event->mutexName())`).

## Scheduled tasks

All scheduled tasks are defined in `routes/console.php` — that file is the authoritative, current list (it currently schedules over 20 commands spanning content publishing, lesson lifecycle, instructor earnings/payout reconciliation, booking reservation cleanup, meeting sync, and record pruning). Don't duplicate that list here; it changes often enough to go stale immediately. To see it:

```bash
grep -n "->command(" routes/console.php
```

The Scheduler Monitor page (above) reflects whatever's currently registered there automatically — no separate config to keep in sync.
