<?php

declare(strict_types=1);

require_once './vendor/autoload.php';

use Nextcloud\CodingStandard\Config;

$config = new Config();
$config
    ->getFinder()
    ->ignoreVCSIgnored(true)
    ->in([
        __DIR__ . '/app/lib',
        __DIR__ . '/app/appinfo',
        __DIR__ . '/tests',
    ]);

return $config;
