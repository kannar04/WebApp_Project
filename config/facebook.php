<?php
declare(strict_types=1);
$app=require __DIR__.'/app.php';
return [
    'enabled'=>filter_var(getenv('FACEBOOK_ENABLED')?:'false',FILTER_VALIDATE_BOOL),
    'app_id'=>trim(getenv('FACEBOOK_APP_ID')?:''),
    'app_secret'=>trim(getenv('FACEBOOK_APP_SECRET')?:''),
    'redirect_uri'=>trim(getenv('FACEBOOK_REDIRECT_URI')?:($app['url']!==''?$app['url'].'/auth/facebook/callback':'')),
    'version'=>trim(getenv('FACEBOOK_GRAPH_VERSION')?:'v26.0'),
];
