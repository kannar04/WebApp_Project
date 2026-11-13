<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="app-base-url" content="<?= e(url('/')) ?>">
<meta name="csrf-token" content="<?= e(\Core\Csrf::token()) ?>">
<meta name="color-scheme" content="light dark">
<title><?= e(__($title??'Home2Home')) ?> · Home2Home</title>
<script src="<?= e(asset('js/theme.js')) ?>"></script>
<link rel="stylesheet" href="<?= e(asset('css/fonts.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('vendor/bootstrap.min.css')) ?>">
<?php $appCssVersion = filemtime(dirname(__DIR__, 3) . '/public/assets/css/app.css'); ?>
<link rel="stylesheet" href="<?= e(asset('css/app.css') . '?v=' . $appCssVersion) ?>">
<link rel="stylesheet" href="<?= e(asset('css/figma.css')) ?>">
<?php $experienceCssVersion = filemtime(dirname(__DIR__, 3) . '/public/assets/css/experience.css'); ?>
<link rel="stylesheet" href="<?= e(asset('css/experience.css') . '?v=' . $experienceCssVersion) ?>">
<link rel="stylesheet" href="<?= e(asset('css/focused.css')) ?>">

<meta name="application-name" content="Home2Home">
<meta name="theme-color" content="#285947">
<link rel="icon" type="image/svg+xml" href="<?= e(asset('images/favicon.svg')) ?>">
<link rel="icon" type="image/png" sizes="512x512" href="<?= e(asset('images/app-icon-512.png')) ?>">
<link rel="apple-touch-icon" sizes="180x180" href="<?= e(asset('images/apple-touch-icon.png')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/brand.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/decoration.css')) ?>">
