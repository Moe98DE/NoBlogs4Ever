<?php

// Development only. Launch from the WordPress document root with php -S 127.0.0.1:8080.
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
if (preg_match('~(?:^|/)\.|/wp-content/uploads/.*\.(?:php|phtml|phar)~i', $path)) {
    http_response_code(403);
    exit;
}
if (str_starts_with($path, '/wp-content/uploads/')) {
    require getcwd().'/nbe-media.php';
    return true;
}
$file = realpath(getcwd().$path);
if ($file && str_starts_with($file, getcwd().'/') && (is_file($file) || is_dir($file))) {
    return false;
}
require getcwd().'/index.php';
