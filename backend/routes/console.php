<?php
// Author: Saleh Akbari <saleh.akbari.programmer@gmail.com>

use Illuminate\Support\Facades\Schedule;

/**
 * Process the notification_outbox every minute. The DB also has a 5-minute
 * scheduled event (evt_reset_stuck_processing) that re-queues rows stuck in
 * 'processing' state, so worker crashes self-heal.
 *
 * To activate the scheduler in dev:
 *   php artisan schedule:work
 *
 * In production, register a system cron:
 *   * * * * * cd /path/to/backend && php artisan schedule:run >> /dev/null 2>&1
 */
Schedule::command('outbox:process')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();
