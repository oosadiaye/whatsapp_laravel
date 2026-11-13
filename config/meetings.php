<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Meetings & calendar
    |--------------------------------------------------------------------------
    |
    | Reminder emails (24 hours and 10 minutes before) are OFF until invitation
    | email has been verified on a real mailbox (needs a real MAIL_MAILER).
    |
    */
    'reminders_enabled' => (bool) env('MEETINGS_REMINDERS', false),
];
