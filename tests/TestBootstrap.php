<?php

declare(strict_types=1);

namespace Kommandhub\PaystackSW\Tests;

use Shopware\Core\TestBootstrapper;

$loader = (new TestBootstrapper())
    ->setPlatformEmbedded(true)
    ->addCallingPlugin()
    ->addActivePlugins('KmhPaystackSW')
    ->setForceInstallPlugins(true)
    ->bootstrap()
    ->getClassLoader();

$loader->addPsr4('Kommandhub\\PaystackSW\\', \dirname(__DIR__) . '/src');
$loader->addPsr4('Kommandhub\\PaystackSW\\Tests\\', __DIR__);
