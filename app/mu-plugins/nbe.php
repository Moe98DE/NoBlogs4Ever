<?php

/**
 * Plugin Name: NoBlogs4Ever Platform
 * Description: Tenant policy, privacy defaults, migration, analytics, discovery and operations for a managed WordPress Multisite publishing platform.
 * Version: 1.0.0-rc.1
 * License: GPL-2.0-or-later
 */

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'NBE\\')) {
        $file = __DIR__.'/nbe/'.substr($class, 4).'.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

\NBE\Platform::boot();
