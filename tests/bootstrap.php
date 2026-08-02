<?php

declare(strict_types=1);

// PHPUnit bootstrap for the default `phpunit.xml` configuration.
//
// This file used to do nothing but `require vendor/autoload.php`, on the stated
// assumption that the Composer autoloader "provides OCA\Shillinq\* and OCP\*
// stubs". That assumption was false: the `nextcloud/ocp` package ships the OCP\
// sources but declares an EMPTY Composer autoload block (the real Nextcloud
// server injects those classes at runtime), so nothing ever registered the OCP\
// namespace. The whole suite therefore ABORTED during collection — PHPUnit
// exited before running a single test with `Interface
// "OCP\Http\Client\IResponse" not found` — which is indistinguishable from a
// passing run if you only read the exit summary.
//
// `tests/bootstrap-unit.php` already does this wiring correctly and is what
// `phpunit-unit.xml` (and therefore `composer test:unit` / `composer test:all`)
// uses. Rather than maintain a second, silently-diverging copy, the default
// bootstrap now delegates to it, so a bare `vendor/bin/phpunit` — which picks
// up `phpunit.xml` — runs the same suite in the same environment as CI.
//
// bootstrap-unit.php defines PHPUNIT_RUN itself.
require_once __DIR__ . '/bootstrap-unit.php';
