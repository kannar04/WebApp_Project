<?php
declare(strict_types=1);

// SMTP is recommended; the legacy PHP mail transport remains available explicitly.
$app=require __DIR__.'/app.php';
return [
    'enabled'=>filter_var(getenv('MAIL_ENABLED')?:'false',FILTER_VALIDATE_BOOL),
    'transport'=>strtolower(trim((string)(getenv('MAIL_TRANSPORT')?:'mail'))),
    'from'=>trim((string)(getenv('MAIL_FROM')?:'')),
    'from_name'=>trim((string)(getenv('MAIL_FROM_NAME')?:'Home2Home')),
    'base_url'=>rtrim((string)(getenv('MAIL_BASE_URL')?:$app['url']),'/'),
    'reset_base_url'=>rtrim(getenv('PASSWORD_RESET_BASE_URL')?:'','/'),
    'smtp_host'=>trim((string)(getenv('SMTP_HOST')?:'')),
    'smtp_port'=>(int)(getenv('SMTP_PORT')?:587),
    'smtp_username'=>trim((string)(getenv('SMTP_USERNAME')?:'')),
    'smtp_password'=>(string)(getenv('SMTP_PASSWORD')?:''),
    'smtp_encryption'=>strtolower(trim((string)(getenv('SMTP_ENCRYPTION')?:'tls'))),
    'smtp_ca_file'=>trim((string)(getenv('SMTP_CA_FILE')?:'')),
];
