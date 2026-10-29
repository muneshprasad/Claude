<?php
// Copy this file to config.php and fill in the values from cPanel > MySQL Databases.
return [
    'db_host' => 'localhost',
    'db_name' => 'cpanelusername_bulagolf',
    'db_user' => 'cpanelusername_bulagolf',
    'db_pass' => 'CHANGE_ME',

    // One-time key to open setup.php and create the first manager login. Delete setup.php afterwards.
    'setup_key' => 'CHANGE_ME_TO_A_LONG_RANDOM_STRING',

    // Shared secret for cron/gps_sync.php when called over HTTP (leave '' to allow CLI only).
    'cron_key' => '',

    // Signed-agreement emails. Each course (and head office) sets its sender in Settings > Email; these are only the fallbacks.
    'email_signed_copy' => true,                 // false switches emailing off everywhere
    'mail_from' => '',                           // e.g. 'noreply@yourdomain.com' (blank = noreply@ the site's domain)
    'mail_from_name' => '',
    'mail_reply_to' => '',
    // Extra secret mixed into the key that encrypts saved SMTP passwords. Set once to a long random string and never change it
    // (changing it means SMTP passwords must be re-entered).
    'secret_key' => 'CHANGE_ME_TO_ANOTHER_LONG_RANDOM_STRING',

    'timezone' => 'Pacific/Fiji',
    'currency' => 'FJD',
    'course_name' => 'Bula Golf',

    // GPS provider: 'none' | 'simulated' | 'http' (see cron/gps_sync.php)
    'gps_provider' => 'none',
    'gps_api_url' => '',
    'gps_api_key' => '',
];
