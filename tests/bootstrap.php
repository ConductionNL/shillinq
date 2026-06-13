<?php

declare(strict_types=1);

// Define that we're running PHPUnit.
define('PHPUNIT_RUN', 1);

// Include Composer's autoloader — provides OCA\Shillinq\* and OCP\* stubs.
// Unit tests use mocks exclusively and do not need the full Nextcloud bootstrap.
require_once __DIR__ . '/../vendor/autoload.php';
