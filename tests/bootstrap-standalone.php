<?php

declare(strict_types=1);

// Standalone (no-NC-bootstrap) test bootstrap for unit tests that only need
// composer + the app's own classes + OCP API stubs.
//
// Used by the slice-09 local verification loop where the worktree lives
// outside the Nextcloud tree (`/tmp/build-runs/...`) and the regular
// tests/bootstrap.php cannot find `lib/base.php` to load NC core.
//
// We register a simple PSR-4-ish autoloader over the `nextcloud/ocp`
// package so `createMock(IJobList::class)` etc. work without booting NC.
define('PHPUNIT_RUN', 1);
require_once __DIR__ . '/../vendor/autoload.php';

$ocpRoot = __DIR__ . '/../vendor/nextcloud/ocp';
spl_autoload_register(static function (string $class) use ($ocpRoot): void {
	if (str_starts_with($class, 'OCP\\') === false && str_starts_with($class, 'NCU\\') === false) {
		return;
	}

	$relative = str_replace('\\', '/', $class) . '.php';
	$candidate = $ocpRoot . '/' . $relative;
	if (is_file($candidate) === true) {
		require_once $candidate;
	}
});
