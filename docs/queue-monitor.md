# Queue Monitor

## Overview

Admin page at `/admin/queue-monitor`. System group, sort 3.

Displays pending and failed job counts by queue name.

## Key files

| File | Purpose |
|---|---|
| `app/Filament/Pages/QueueMonitorPage.php` | Filament page, System group sort 3 |
| `app/Policies/QueueMonitorPolicy.php` | viewPage |

## Gate ability

`queue_monitor.view` — defined in `AppServiceProvider`, uses `QueueMonitorPolicy::viewPage`.

## Queue configuration

`QUEUE_CONNECTION` in `.env`. In tests, set to `sync` in `phpunit.xml` so queued listeners run inline.

The `notifications` queue is used by `NotifyAdminsOnActivity`. Production should run a dedicated worker for this queue.

The `recordings` and `ai` queues each have their own connection (their `retry_after` must stay above the corresponding job timeout) and each needs its own worker:

```bash
php artisan queue:work recordings --queue=recordings
php artisan queue:work ai --queue=ai
```
