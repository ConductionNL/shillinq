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
        // Register the minimal OpenRegister stubs (Event\*, Db\ObjectEntity)
        // that the Listener / Integration unit tests typehint and instantiate.
        // They live under tests/stubs/OpenRegister/ but composer skips them at
        // dump time because the OCA\OpenRegister\ namespace does not match the
        // tests/ psr-4 rule, so the suite must register the namespace itself.
        // When run inside a deployed NC tree with OpenRegister installed,
        // base.php below provides the real classes and this entry is shadowed.
        $loader[0]->addPsr4('OCA\\OpenRegister\\', __DIR__ . '/stubs/OpenRegister/');
        break;
    }
}

// OCP\DB\QueryBuilder\IQueryBuilder declares class constants whose default
// expressions reference Doctrine\DBAL\ParameterType / ArrayParameterType. The
// bare unit env does not install doctrine/dbal, so any test that mocks
// IDBConnection / IQueryBuilder fatals with "Class Doctrine\DBAL\ParameterType
// not found" when PHPUnit reflects those types. Provide minimal constant-only
// stubs (values mirror doctrine/dbal) so the OCP interface loads. Skipped when
// the real doctrine classes are present (deployed NC tree).
if (class_exists('Doctrine\\DBAL\\ParameterType', false) === false) {
    eval(
        'namespace Doctrine\\DBAL; '
        . 'class ParameterType { '
        . 'const NULL = 0; const INTEGER = 1; const STRING = 2; '
        . 'const LARGE_OBJECT = 3; const BINARY = 4; const BOOLEAN = 5; '
        . 'const ASCII = 6; }'
    );
}

if (class_exists('Doctrine\\DBAL\\ArrayParameterType', false) === false) {
    eval(
        'namespace Doctrine\\DBAL; '
        . 'class ArrayParameterType { '
        . 'const INTEGER = 101; const STRING = 102; '
        . 'const ASCII = 103; const BINARY = 104; }'
    );
}

// \OC::$server is referenced from OCP\AppFramework\Http\Response::getHeaders()
// (Retry-After / request-id serialisation). Provide a minimal compatible stub
// so controller tests can serialise headers without a full Nextcloud
// environment. Skipped when the real \OC already exists (deployed NC tree).
if (class_exists('OC', false) === false) {
    /**
     * Minimal OC class stub for unit-test header serialisation.
     */
    final class OC
    {

        /**
         * Pseudo-server container — only get() is exercised.
         *
         * @var object
         */
        public static object $server;
    }//end class

    OC::$server = new class {
        /**
         * Resolve a service interface to a minimal stub.
         *
         * @param string $service The service id.
         *
         * @return object The stub.
         */
        public function get(string $service): object
        {
            if ($service === 'OCP\\IRequest') {
                return new class {
                    /**
                     * Return a deterministic request id stub.
                     *
                     * @return string
                     */
                    public function getId(): string
                    {
                        return 'stub-request-id';
                    }//end getId()
                };
            }

            if ($service === 'OCP\\IConfig') {
                return new class {
                    /**
                     * Return a system-value stub.
                     *
                     * @param string $key     The config key.
                     * @param mixed  $default The default.
                     *
                     * @return mixed The default.
                     */
                    public function getSystemValue(string $key, mixed $default=null): mixed
                    {
                        return $default;
                    }//end getSystemValue()
                };
            }

            return new class {
            };
        }//end get()
    };
}//end if

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
