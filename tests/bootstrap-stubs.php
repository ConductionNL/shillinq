<?php
declare(strict_types=1);
define('PHPUNIT_RUN', 1);
$autoloader = require __DIR__ . '/../vendor/autoload.php';
$autoloader->addPsr4('OCP\\', __DIR__ . '/../vendor/nextcloud/ocp/OCP/');
$autoloader->addPsr4('NCU\\', __DIR__ . '/../vendor/nextcloud/ocp/NCU/');
