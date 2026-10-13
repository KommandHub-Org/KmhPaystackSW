<?php

// If running in CI → skip Shopware bootstrap
if (getenv('CI') === 'true') {
    require __DIR__ . '/../vendor/autoload.php';

    return;
}

// Otherwise (local dev) → use Shopware bootstrap
require __DIR__ . '/TestBootstrap.php';
