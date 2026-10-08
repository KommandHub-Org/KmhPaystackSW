<?php

declare(strict_types=1);

namespace Kommandhub\PaystackSW\Tests;

$loader = (new TestBootstrapper())
    ->setPlatformEmbedded(true)
    ->addCallingPlugin()
    ->setForceInstallPlugins(true)
    ->addActivePlugins(
        'KmhPaystackSW',
    )
    ->bootstrap()
    ->getClassLoader();

$loader->addPsr4('Kommandhub\\PaystackSW\\Tests\\', __DIR__);
