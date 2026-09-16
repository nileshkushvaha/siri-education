# Recurring bookings — production verification runbook

The gate for `recurring_future_generation_enabled` and
`recurring_wallet_auto_settle_enabled`.

Both settings ship `false`. They are **fail-closed release gates**, not
feature toggles: with them off the platform refuses to make a promise it
may not be able to keep, and says so to the student. They are turned on
only after the steps below have been performed **on the target
environment** and the evidence recorded.

**A passing test suite is not evidence for anything on this page.** The
suite proves the code is correct. It cannot prove that this server's
cron fires, that this supervisor restarts this worker, or that this
mail provider delivers. Every step below therefore observes the running
system; none of them may be substituted with `php artisan test`.

Run steps in order. Stop at the first failure — later steps assume the
earlier ones hold.

---

## 0. Before you start

```bash
cd /path/to/app

# Confirm both gates are still closed.
php artisan tinker --execute='$s=app(\App\Settings\BookingSettings::class);
 printf("future_generation=%s auto_settle=%s horizon_days=%d\n",
 var_export($s->recurring_future_generation_enabled,true),
 var_export($s->recurring_wallet_auto_settle_enabled,true),
 $s->recurring_confirmation_horizon_days);'
```

Expected: `future_generation=false auto_settle=false horizon_days=60`.

Record: environment name, hostname, app version/commit, operator, date.

---

## Part 1 — items 1–8: does generation actually run here?

Gates `recurring_future_generation_enabled`.

### Item 1 — the Laravel scheduler is actually being invoked

**Find the mechanism before checking it.** This deployment supervises
its long-running processes with Supervisor (see
`deployment/pulse-check.conf.example` for the house style and
`deployment/recording-cutover.md`, which stops `siri-recordings`), so
**`crontab -l` may legitimately be empty and that is not evidence of a
problem.** Establish which of these is in use:

```bash
# A. Supervisor — the mechanism this deployment uses for long-running work
sudo supervisorctl status
sudo grep -rn "schedule:work\|schedule:run" /etc/supervisor/conf.d/

# B. systemd timer / service
systemctl list-timers --all | grep -i schedule
systemctl status <unit>

# C. cron (user or system)
crontab -l; sudo crontab -l; cat /etc/crontab; ls -la /etc/cron.d/
```

Exactly **one** of these should own scheduling. Two mechanisms running
together double every scheduled command; `withoutOverlapping()` prevents
concurrent execution of the same command but not two schedulers taking
turns.

Record for the one you find: program/unit name, exact command, user,
`autostart`/`autorestart`, log paths, uptime and restart count.

For the Supervisor case:

```bash
sudo supervisorctl status <scheduler-program>
sudo cat /etc/supervisor/conf.d/<scheduler-program>.conf
```

**Evidence:** `RUNNING` with an uptime of hours or days, a low restart
count, the correct application `directory=` and `user=`, and no fatal
output in its `stderr_logfile`.
**Fails if:** the process is `FATAL`/`BACKOFF`, or restarting every few
seconds — a crash loop looks like "configured" from a distance.

> `php artisan schedule:work` is a foreground loop that internally ticks
> every minute; it is the correct Supervisor-managed equivalent of the
> one-minute cron entry. Do **not** add a cron entry as well.

### Item 2 — `booking:generate-series` is invoked BY the scheduler

Registration first:

```bash
php artisan schedule:list | grep booking:generate-series
```

Expected: `0 * * * *` with a "Next Due" in the future.

Registration is not execution. Prove the scheduler actually fires it —
**a manual `php artisan booking:generate-series` proves the command
works and proves nothing about the schedule.**

```bash
# Global heartbeat: is ANY scheduled task running right now?
php artisan tinker --execute='echo \App\Models\SchedulerHistory::query()
 ->where("triggered_by","!=","manual")->orderByDesc("ran_at")->limit(5)
 ->get(["command","status","ran_at"])->toJson(JSON_PRETTY_PRINT);'

# This command specifically.
php artisan tinker --execute='echo \App\Models\SchedulerHistory::query()
 ->where("command","like","%generate-series%")->where("triggered_by","!=","manual")
 ->orderByDesc("ran_at")->limit(5)
 ->get(["status","duration_ms","ran_at"])->toJson(JSON_PRETTY_PRINT);'

tail -n 40 storage/logs/booking-series-generation.log
```

**Evidence:** a heartbeat row within the last few minutes, and **two**
`booking:generate-series` rows roughly 60 minutes apart with
`triggered_by` other than `manual`. One row proves it ran once; two
prove a *schedule*.
**Fails if:** the heartbeat is stale (the scheduler is down) or the
generate-series rows are absent while other commands are present (the
command is not reaching the schedule).

Or ask the platform directly — this is the same question, answered in
one command:

```bash
php artisan platform:health-check
```

### Item 3 — queue workers are supervised and survive restarts

`booking:generate-series` does **not** generate anything itself: it
dispatches one `GenerateBookingSeriesOccurrences` job per active
schedule onto the **`notifications`** queue, and that job re-dispatches
itself while the horizon still has room. So a healthy scheduler with no
worker on that queue produces a growing backlog and **zero** new
classes.

The queue name matters. A worker consuming only `default` — which is
what `php artisan queue:listen` gives you with no arguments — will never
touch these jobs.

The same `notifications` queue carries
`SettleSeriesPrepaymentOnWalletRechargeSucceeded`, which spends a "pay for
all classes" top-up on the classes it was raised for. Without a worker
those classes are still confirmed by the browser's verified return and,
failing that, by `booking:settle-series-prepayments` every five minutes —
but a stopped worker is still a fault to fix, not a mode to run in.

```bash
sudo supervisorctl status                 # find the worker program
sudo grep -rn "queue:work" /etc/supervisor/conf.d/   # confirm --queue includes notifications
php artisan queue:monitor notifications
```

Process-death test:

```bash
pgrep -af 'queue:work.*notifications'
sudo kill -9 <pid>
sleep 15
pgrep -af 'queue:work.*notifications'     # must show a NEW pid
```

Server-restart test (schedule a maintenance window):

```bash
sudo reboot
# after it comes back:
pgrep -af 'queue:work.*notifications'
crontab -l | grep schedule:run
```

**Evidence:** a new pid after the kill, and a running worker plus intact
cron after a full reboot.
**Fails if:** the worker must be started by hand — generation would then
stop silently at the next deploy or reboot.

### Item 4 — a controlled series beyond the horizon receives new bookings

Create a **real** series in production owned by an internal test student,
with an instructor whose availability you control.

Temporarily enable the first gate for the duration of this test only:

```bash
php artisan tinker --execute='$s=app(\App\Settings\BookingSettings::class);
 $s->recurring_future_generation_enabled=true; $s->save();'
```

Book, through the UI, a **weekly** schedule of ~20 classes (well past the
60-day horizon). Record the series id, then:

```bash
SERIES=<series-id>

php artisan tinker --execute="\$s=\App\Models\BookingSeries::find('$SERIES');
 printf(\"bookings=%d generated_through=%s last_generated=%s failures=%d\n\",
 \$s->bookings()->count(), \$s->generated_through_date, \$s->last_generated_at, \$s->generation_failures);"
```

Note the count. **Wait for at least one scheduled hourly run** — do not
run the command yourself. Re-run the query.

**Evidence:** `bookings` has increased and `generated_through_date` has
advanced, with `last_generated_at` matching a scheduler run.
**Fails if:** nothing changed — generation is not reaching this series.

> Turn the gate back off after Part 1 unless you are proceeding straight
> to the release decision.

### Item 5 — conflict and recovery when an occurrence cannot be generated

Make a future, not-yet-generated occurrence impossible, then let the
sweep reach it. Using the instructor from item 4:

1. In the instructor's Leave/Time-off, block the whole day of an
   occurrence that is **beyond** `generated_through_date`.
2. Wait for the next hourly run.

```bash
php artisan tinker --execute="echo \App\Models\BookingSeriesException::query()
 ->where('booking_series_id','$SERIES')->get(['local_date','action','reason','notified_at'])
 ->toJson(JSON_PRETTY_PRINT);"
```

**Evidence:** a row with `action = conflict`, a human-readable `reason`,
and `notified_at` set.

Then recover: remove the leave, open the class in **My Bookings →
Repeating schedule**, and use **Try again**.

**Evidence:** a booking now exists for that date and the exception row is
gone.
**Why this matters:** the hourly sweep will never revisit that date on
its own — it sits behind the generation watermark — so recovery is
operator/student-driven by design.

### Item 6 — the lost-class notification reaches a real channel

The exception row above should have produced a message.

```bash
php artisan tinker --execute='echo \DB::table("notifications")
 ->orderByDesc("created_at")->limit(3)
 ->get(["type","notifiable_id","created_at"])->toJson(JSON_PRETTY_PRINT);'
```

That row is **not sufficient**. Confirm delivery on the configured
channel:

- open the test student's real mailbox and find the message;
- cross-check the provider dashboard (Resend) for the send;
- confirm the message names the affected date and time.

**Evidence:** a screenshot or provider log id for the delivered message.
**Fails if:** the database row exists but nothing was delivered — the
worker is running but mail transport is broken.

### Item 7 — failures are visible

Confirm each surface shows a real failure rather than silence:

```bash
# Queue failures
php artisan queue:failed

# Generation failures per series
php artisan tinker --execute='echo \App\Models\BookingSeries::query()
 ->where("status","active")
 ->orderByDesc("generation_failures")->limit(10)
 ->get(["id","generation_failures","last_generated_at"])->toJson(JSON_PRETTY_PRINT);'

# Scheduler failures
php artisan tinker --execute='echo \App\Models\SchedulerHistory::query()
 ->where("status","!=","success")->orderByDesc("ran_at")->limit(10)
 ->get(["command","status","ran_at"])->toJson(JSON_PRETTY_PRINT);'
```

Also confirm a human actually looks: **/admin → Scheduler Monitor** lists
`booking:generate-series` with a recent successful run, and **/admin →
Booking payment reconciliation issues** is reachable by the on-call role.

**Evidence:** each command returns (empty is fine), the admin pages load,
and someone owns the alert.
**Deliberately not claimed:** there is no automatic alert on a *stalled*
schedule. Add a monitor on "an active series whose `last_generated_at` is
older than 3 hours" if you want paging — see §Recovery.

### Item 8 — repeated execution creates no duplicates

```bash
php artisan tinker --execute="echo \App\Models\Booking::where('booking_series_id','$SERIES')->count();"

php artisan booking:generate-series --series=$SERIES --sync
php artisan booking:generate-series --series=$SERIES --sync
php artisan booking:generate-series --series=$SERIES --sync

php artisan tinker --execute="echo \App\Models\Booking::where('booking_series_id','$SERIES')->count();"
```

**Evidence:** the count is identical before and after.

Confirm the database-level guarantee is present, not just the behaviour:

```bash
php artisan tinker --execute='echo collect(\DB::select("SHOW INDEX FROM bookings"))
 ->pluck("Key_name")->unique()->filter(fn($k)=>str_contains($k,"series"))->values()->toJson();'
```

**Evidence:** `bookings_series_occurrence_unique` is present. This is
what makes generation idempotent under retries and concurrent workers —
the behaviour above follows from it rather than from convention.

---

## Part 2 — items 9–12: auto-settlement from wallet

Gates `recurring_wallet_auto_settle_enabled`. **Do not start Part 2
until Part 1 is fully green.**

Enable both gates for the duration of this test only, and opt the test
series in through **My Bookings → Repeating schedule → "Use my balance to
confirm future classes automatically"** (never by editing the column —
the point is to test the consent path a student uses).

```bash
php artisan tinker --execute='$s=app(\App\Settings\BookingSettings::class);
 $s->recurring_wallet_auto_settle_enabled=true; $s->save();'
```

### Item 9 — sufficient balance confirms exactly once, for the right amount

Fund the test student's wallet with **exactly one class price**, note the
balance, and wait for the next hourly run.

```bash
php artisan tinker --execute="\$u=<student-id>;
 \$w=\App\Models\Wallet::where('user_id',\$u)->first();
 printf(\"balance=%d\n\", \$w->available_balance_minor);
 echo \App\Models\Booking::where('booking_series_id','$SERIES')
   ->orderByDesc('created_at')->limit(3)
   ->get(['reference','payment_status','price','starts_at'])->toJson(JSON_PRETTY_PRINT);"
```

**Evidence, all three:**
- the newest generated class is `payment_status = paid`;
- the wallet fell by **exactly** that class's price, not more;
- exactly one captured `booking_payments` row and one
  `wallet_ledger_entries` debit exist for it:

```bash
php artisan tinker --execute="\$b='<booking-id>';
 echo \App\Models\BookingPayment::where('booking_id',\$b)->get(['provider','amount_minor','status'])->toJson();
 echo \App\Models\WalletLedgerEntry::where('source_id', \App\Models\BookingPayment::where('booking_id',\$b)->value('id'))
   ->get(['entry_type','direction','amount_minor'])->toJson();"
```

### Item 10 — insufficient balance debits nothing

Leave the wallet with **less** than one class price. Wait for the next
run.

**Evidence:**
- the newly generated class is `payment_status = pending` (payment due);
- the wallet balance is **unchanged** — no partial debit;
- no `booking_payments` row exists for that booking.

**Why this is a hard requirement:** a partial settlement would drain the
balance to zero *and* leave the class unpaid, which is worse than doing
nothing.

### Item 11 — auto-settlement is idempotent under retry and concurrency

```bash
# Fund for exactly one class, then force repeated passes.
php artisan booking:generate-series --series=$SERIES --sync
php artisan booking:generate-series --series=$SERIES --sync

# And concurrently:
php artisan booking:generate-series --series=$SERIES --sync &
php artisan booking:generate-series --series=$SERIES --sync &
wait
```

**Evidence:** the wallet fell by one class price in total; exactly one
captured payment and one ledger debit exist per booking; no booking is
`paid` twice and `queue:failed` is empty.

### Item 12 — recovery procedures are documented and rehearsed

Perform each one once, so it is known to work here:

| Situation | Action |
|---|---|
| Generation stalled for one series | `php artisan booking:generate-series --series=<id> --sync` |
| Generation stalled for all | `php artisan booking:generate-series` (queued) |
| Queue jobs failed | `php artisan queue:retry all`, then `php artisan queue:failed` |
| A class could not be booked | My Bookings → Repeating schedule → **Try again** on that date |
| Student wants auto-settle stopped | My Bookings → untick. Works even with the gate off |
| Stop new long/ongoing schedules platform-wide | set `recurring_future_generation_enabled=false` — existing series keep generating |
| Stop all unattended spending platform-wide | set `recurring_wallet_auto_settle_enabled=false` — takes effect on the next pass |
| Refund owed but stuck | /admin → Booking payment reconciliation issues → `Refund not completed` |

**Evidence:** each row exercised at least once, with the operator named.

---

## Rolling back

Both gates are safe to switch off at any time:

- `recurring_future_generation_enabled=false` — blocks **creation** of new
  schedules that need future generation. Existing schedules continue to
  generate; nothing already promised is withdrawn.
- `recurring_wallet_auto_settle_enabled=false` — stops all unattended
  settlement on the next pass. Per-student consent is retained, so
  re-enabling does not require students to opt in again.

Neither requires a deploy, a migration, or a queue drain.

---

## Monitoring: what to watch, and how

The dangerous failure is not a crash — those are recorded and visible.
It is the scheduler quietly **stopping**, which produces no failed row,
no exception and no log line. Silence looked exactly like health.

`platform:health-check` is the signal for that. It is read-only, adds no
table and no daemon, and computes its verdict from records the platform
already keeps (`scheduler_histories`, `jobs`, `failed_jobs`,
`booking_series`, reconciliation issues).

```bash
php artisan platform:health-check          # human-readable table
php artisan platform:health-check --json   # for a monitor to parse
```

Exit codes are the contract:

| Code | Meaning | Suggested action |
|---|---|---|
| `0` | ok | none |
| `1` | warning — failures recorded, or money owed | look within the working day |
| `2` | critical — something has stopped, or never ran | page |

Checks performed:

| Check | Critical when |
|---|---|
| `scheduler_heartbeat` | no non-manual scheduled task for >15 min, or none ever |
| `recurring_generation_scheduled` | `booking:generate-series` not scheduled-run for >150 min, or never |
| `queue_backlog` | oldest waiting job older than 30 min (nothing is draining) |
| `recurring_schedules` | a series with `generation_failures > 3` (warning if merely stalled 6h) |
| `scheduler_failures_24h` / `failed_jobs_24h` / `reconciliation_issues` | warning only |

A **manual** run never counts as a heartbeat. Counting it would let a
dead scheduler look alive for as long as an operator kept poking it.

**What must still be wired up externally** — this command reports, it
does not page. Point whatever the deployment already uses at it:

```bash
# Example: cron entry on the app server, alerting on non-zero exit.
*/10 * * * * cd <APP_PATH> && php artisan platform:health-check --json > /tmp/siri-health.json 2>&1 || <your-alert-command>
```

Also confirm **Supervisor itself is externally monitored** — if the
scheduler program dies and nothing watches Supervisor, the health check
dies with it. A process-level monitor and this application-level check
answer different questions and you want both.
