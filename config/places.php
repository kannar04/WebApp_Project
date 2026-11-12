<?php
declare(strict_types=1);

return [
    'api_key' => trim((string) (getenv('GEOAPIFY_API_KEY') ?: '')),
];
