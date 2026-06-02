<?php

/**
 * Standalone unit test bootstrap — loads OCP interfaces without full NC init.
 *
 * Used by phpunit-unit.xml when NC core is available but not installed/configured.
 */

declare(strict_types=1);

define('PHPUNIT_RUN', 1);

// 3rdparty autoloader provides PSR and other deps.
if (file_exists('/srv/nextcloud/3rdparty/autoload.php')) {
    require_once '/srv/nextcloud/3rdparty/autoload.php';
}

// App's own vendor autoloader.
require_once __DIR__ . '/../vendor/autoload.php';

// Register OCP + OCA namespace autoloaders.
spl_autoload_register(static function (string $class): void {
    // OCP classes live in NC's lib/public/.
    if (str_starts_with(haystack: $class, needle: 'OCP\\')) {
        $relative = str_replace(search: ['OCP\\', '\\'], replace: ['', '/'], subject: $class);
        $path     = '/srv/nextcloud/lib/public/' . $relative . '.php';
        if (file_exists(filename: $path)) {
            require_once $path;
            return;
        }
    }

    // OC_ prefix or OC\ classes.
    if (str_starts_with(haystack: $class, needle: 'OC\\') || str_starts_with(haystack: $class, needle: 'OC_')) {
        $relative = str_replace(search: ['OC\\', '\\'], replace: ['', '/'], subject: $class);
        $path     = '/srv/nextcloud/lib/private/' . $relative . '.php';
        if (file_exists(filename: $path)) {
            require_once $path;
        }
    }
});
