<?php

return [

    /*
    | A punch after midnight is the previous work date's time out when it falls before the end
    | of this window on the next date: "until", or "hours_before_next_shift" before the shift
    | start of the next date, whichever is earlier. The end itself is outside the window.
    */
    'overnight' => [
        'until'                   => env('ATTENDANCE_OVERNIGHT_UNTIL', '06:00'),
        'hours_before_next_shift' => (int) env('ATTENDANCE_OVERNIGHT_HOURS_BEFORE_NEXT_SHIFT', 3),
    ],

];
