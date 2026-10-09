<?php
declare(strict_types=1);

// PHP's configured mail transport (XAMPP sendmail/SMTP setup is external to the repository).
// Disabled by default: never pretend delivery succeeded or expose tokens in HTML/logs.
return ['enabled'=>filter_var(getenv('MAIL_ENABLED')?:'false',FILTER_VALIDATE_BOOL),
    'from'=>getenv('MAIL_FROM')?:'', 'reset_base_url'=>rtrim(getenv('PASSWORD_RESET_BASE_URL')?:'','/')];
