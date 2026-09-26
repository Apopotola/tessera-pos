<?php

return [
    'name' => 'Notifications',

    /*
     * log — demo / development: the SMS or WhatsApp message is written to the log, nothing is
     *       sent (the outbox shows driver "log"). A real provider (e.g. Africa's Talking for SMS,
     *       Meta Cloud API for WhatsApp) plugs in here once chosen and a sender ID is registered.
     * Email uses Laravel mail (MAIL_MAILER).
     */
    'sms_driver' => env('NOTIFY_SMS_DRIVER', 'log'),
    'whatsapp_driver' => env('NOTIFY_WHATSAPP_DRIVER', 'log'),
];
