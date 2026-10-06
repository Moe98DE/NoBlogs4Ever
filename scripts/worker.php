<?php

/**
 * One pass of background work; the worker container runs this every 30 s.
 * See NBE\Worker for what a pass does.
 */

declare(strict_types=1);

require (getenv('WP_ROOT') ?: '/var/www/html').'/wp-load.php';
\NBE\Worker::run();
