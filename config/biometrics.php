<?php

/*
 * The office biometrics feed (spec 012). The key itself lives in services.biometrics_feed.
 */
return [
    // Lines one punches post may carry; more is refused whole with 422.
    'max_lines' => 1000,

    // How long a post waits for another import to finish before it answers 503.
    'lock_wait_seconds' => 20,

    // A punch before this moment, or more than this many hours after now, is refused.
    'earliest_punch' => '2020-01-01 00:00:00',
    'future_hours' => 24,

    // What the device holds and when the page warns about it.
    'device_capacity' => 50000,
    'device_warn_at' => 40000,

    // "No contact since HH:MM" after this many minutes of silence, between these Manila hours.
    'silence_minutes' => 10,
    'watch_from' => '08:00',
    'watch_until' => '20:00',

    // A clock drift above this many seconds is shown as a warning.
    'max_clock_drift_seconds' => 60,

    // Agent runs that changed nothing are kept this many hours, then removed.
    'quiet_run_hours' => 24,
];
