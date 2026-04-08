<?php

declare(strict_types=1);

// Define that we're running PHPUnit.
define('PHPUNIT_RUN', 1);

// Include Composer's autoloader.
require_once __DIR__ . '/../vendor/autoload.php';

// Register the OCP namespace from nextcloud/ocp so unit tests that
// mock Nextcloud interfaces work without a full Nextcloud installation.
$loader = new \Composer\Autoload\ClassLoader();
$ocpDir = __DIR__ . '/../vendor/nextcloud/ocp';
if (is_dir($ocpDir . '/OCP')) {
    $loader->addPsr4('OCP\\', $ocpDir . '/OCP/');
}
if (is_dir($ocpDir . '/NCU')) {
    $loader->addPsr4('NCU\\', $ocpDir . '/NCU/');
}
$loader->register(true);

// Bootstrap Nextcloud — since we run inside the Docker container,
// the full environment (including \OC::$server) is available.
if (file_exists(__DIR__ . '/../../../lib/base.php')) {
    require_once __DIR__ . '/../../../lib/base.php';
}

// Register Test\ namespace for NC test classes.
$serverTestsLib = __DIR__ . '/../../../tests/lib/';
if (is_dir($serverTestsLib)) {
    $loader = new \Composer\Autoload\ClassLoader();
    $loader->addPsr4('Test\\', $serverTestsLib);
    $loader->register(true);
}
