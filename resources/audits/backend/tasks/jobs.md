# Task: Queues and scheduler

## Mission

Check that queued jobs and scheduled tasks behave well when they fail, retry, overlap or run long.

## Inputs

- `app/Jobs`, `app/Listeners` (queued), `routes/console.php`, `bootstrap/app.php` (`withSchedule`), `app/Console`
- `config/queue.php` (`retry_after`), `.env.example`, deployment scripts if present

## Checklist

- Queue robustness: jobs that are not idempotent; missing `$tries` / `$backoff` / `$timeout`; `$timeout` at or above the connection's `retry_after` (the job is picked up twice); `ShouldBeUnique` missing where duplicates hurt; large models serialized into the payload; no `failed()` handling where a failure leaves state behind. (`queue-robustness`)
- Scheduler: long or critical tasks without `withoutOverlapping()` / `onOneServer()`; tasks that dispatch jobs which then overlap. (`scheduler-overlap`)
