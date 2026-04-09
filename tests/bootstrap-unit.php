<?php

declare(strict_types=1);

// Define that we're running PHPUnit.
define('PHPUNIT_RUN', 1);

// Include Composer's autoloader.
$autoloader = require_once __DIR__ . '/../vendor/autoload.php';

// Register OCP/NCU stubs so unit tests run without a live Nextcloud install.
// The stubs are provided by nextcloud/ocp (pulled in as a dev dependency for PHPStan).
$ocpStubsPath = __DIR__ . '/../vendor/nextcloud/ocp';
if (is_dir($ocpStubsPath)) {
    $autoloader->addPsr4('OCP\\', $ocpStubsPath . '/OCP/');
    $autoloader->addPsr4('NCU\\', $ocpStubsPath . '/NCU/');
}

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
