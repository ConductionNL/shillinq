<?php

declare(strict_types=1);

// Define that we're running PHPUnit.
define('PHPUNIT_RUN', 1);

// Include Composer's autoloader.
require_once __DIR__ . '/../vendor/autoload.php';

// Register OCP and NCU namespaces from the nextcloud/ocp stub package so the
// unit suite can mock/typehint OCP interfaces WITHOUT a full Nextcloud install
// (the ocp package ships no autoload of its own). This is what makes the
// phpunit-unit suite runnable in a bare ci-php container — the whole point of
// the "unit" config. PHPUnit's binary already required vendor/autoload.php, so
// the require above returns true (not the ClassLoader); fetch the registered
// Composer loader from the spl autoload stack instead. When run inside a
// deployed NC tree, base.php below declares the real OCP/OC and these psr4
// entries are simply shadowed.
foreach (spl_autoload_functions() as $loader) {
    if (is_array($loader) && $loader[0] instanceof \Composer\Autoload\ClassLoader) {
        $loader[0]->addPsr4('OCP\\', __DIR__ . '/../vendor/nextcloud/ocp/OCP/');
        $loader[0]->addPsr4('NCU\\', __DIR__ . '/../vendor/nextcloud/ocp/NCU/');
        break;
    }
}

// Bootstrap Nextcloud when running inside the Docker container / deployed tree —
// the full environment (including \OC::$server) is then available. Loaded AFTER
// the stub psr4 registration above; in CI this file is absent and the suite
// runs purely against the ocp stubs.
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
