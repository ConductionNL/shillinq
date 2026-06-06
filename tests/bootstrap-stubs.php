<?php

/**
 * PHPUnit bootstrap for standalone unit tests — wires OCP stubs without full NC environment.
 *
 * @author  Conduction Development Team <info@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);
define('PHPUNIT_RUN', 1);
$autoloader = include __DIR__.'/../vendor/autoload.php';
$autoloader->addPsr4('OCP\\', __DIR__.'/../vendor/nextcloud/ocp/OCP/');
$autoloader->addPsr4('NCU\\', __DIR__.'/../vendor/nextcloud/ocp/NCU/');
// Register OpenRegister app classes so tests that stub OR events compile without a full NC install.
$orLibPath = __DIR__.'/../../openregister/lib/';
if (is_dir($orLibPath) === false) {
    $orLibPath = '/srv/nextcloud/apps/openregister/lib/';
}
if (is_dir($orLibPath) === true) {
    $autoloader->addPsr4('OCA\\OpenRegister\\', $orLibPath);
}
