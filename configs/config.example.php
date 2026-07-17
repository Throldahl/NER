<?php
declare(strict_types=1);

define('DB_HOST', 'localhost');
define('DB_NAME', 'YOUR_DATABASE_NAME');
define('DB_USER', 'YOUR_DATABASE_USERNAME');
define('DB_PASS', 'YOUR_DATABASE_PASSWORD');
define('DB_CHARSET', 'utf8mb4');

define('SESSION_NAME', 'captionerner_session');
define('DEFAULT_ASSESSMENT_SLUG', 'winter_ner_2026');

// Google Identity Services OAuth client ID. This is not a client secret.
define('GOOGLE_CLIENT_ID', 'YOUR_GOOGLE_OAUTH_CLIENT_ID.apps.googleusercontent.com');
define('GOOGLE_AUTH_REQUIRED_DOMAIN', '3playmedia.com');
define('DEFAULT_ADMIN_EMAIL', 'YOUR_ADMIN_EMAIL');

return [
    'alert_from_name' => 'Derek Throldahl',
    'alert_from_email' => 'Derek@dereksprojects.com',
    'alert_smtp_host' => 'smtp.titan.email',
    'alert_smtp_port' => 465,
    'alert_smtp_encryption' => 'ssl',
    'alert_smtp_username' => 'Derek@dereksprojects.com',
    'alert_smtp_password' => 'YOUR_TITAN_EMAIL_PASSWORD',
    'alert_smtp_timeout' => 15,
];
