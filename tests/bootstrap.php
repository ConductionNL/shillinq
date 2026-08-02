<?php

declare(strict_types=1);

/*
 * PHPUnit bootstrap for `phpunit.xml`.
 *
 * This file used to require nothing but `vendor/autoload.php`, on the comment
 * "provides OCA\Shillinq\* and OCP\* stubs". It does not: the `nextcloud/ocp`
 * package ships NO autoload section of its own, so no `OCP\*` interface was
 * ever resolvable through it. Every test file that typehints one died at
 * COLLECTION time — PHPUnit aborted with
 *   Interface "OCP\Http\Client\IResponse" not found
 * and exit 255, so ZERO tests ran on all six PHP/NC matrix legs.
 *
 * It stayed invisible for two reasons: the shared CI workflow prefers
 * `phpunit.xml` when it exists, while `composer test:unit` runs
 * `phpunit-unit.xml` -> `tests/bootstrap-unit.php`, which DOES register those
 * namespaces; and the Code Quality workflow itself had been unresolvable
 * (`uses:` pointed at an org that does not exist) so the job produced no jobs
 * and therefore no output at all.
 *
 * There is one bootstrap now rather than two that drift. `bootstrap-unit.php`
 * defines PHPUNIT_RUN itself.
 */

require_once __DIR__ . '/bootstrap-unit.php';
