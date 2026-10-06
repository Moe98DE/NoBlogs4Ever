<?php

/**
 * Every request below /wp-content/uploads/ is rewritten here (Apache
 * .htaccess and the native development router) so that tenant privacy,
 * draft/private attachments and the MIME policy are enforced before any byte
 * of an uploaded file is served. See NBE\Media::serve().
 */

declare(strict_types=1);

require __DIR__.'/wp-load.php';
\NBE\Media::serve((string) ($_SERVER['REQUEST_URI'] ?? '/'));
