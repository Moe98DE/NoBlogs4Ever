<?php

// WP-CLI-only path for operator-reviewed archives mounted read-only at /imports.
if (!defined('ABSPATH') || !defined('WP_CLI')) {
    throw new RuntimeException('Run through WP-CLI.');
}

/** Print an error and stop with a non-zero exit code (no stack trace). */
function nbe_cli_fail(string $message): void
{
    fwrite(STDERR, 'Error: '.$message."\n");
    exit(1);
}
if (getenv('NBE_OPERATOR_IMPORT_ACK') !== 'reviewed-offline-archive') {
    nbe_cli_fail('Set NBE_OPERATOR_IMPORT_ACK=reviewed-offline-archive after reviewing the source and limits.');
}
$archive = getenv('NBE_IMPORT_ARCHIVE') ?: '';
$real = realpath($archive);
if (!$real || !str_starts_with($real, '/imports/') || !is_file($real)) {
    nbe_cli_fail('NBE_IMPORT_ARCHIVE must resolve to a regular file below the read-only /imports mount.');
}
$site = (int)(getenv('NBE_IMPORT_SITE_ID') ?: 0);
$user = (int)(getenv('NBE_IMPORT_USER_ID') ?: 0);
if (!$site || !$user || !user_can_for_site($user, $site, 'manage_options')) {
    nbe_cli_fail('NBE_IMPORT_SITE_ID and NBE_IMPORT_USER_ID must identify a destination and its administrator.');
}
$integer = static function (string $name, int $default, int $ceiling): int {
    $value = (int)(getenv($name) ?: $default);
    if ($value < 1 || $value > $ceiling) {
        nbe_cli_fail($name.' exceeds the hard safety ceiling.');
    }
    return $value;
};
$limits = [
    'input' => $integer('NBE_IMPORT_MAX_INPUT_BYTES', 1073741824, 2147483648),
    'bytes' => $integer('NBE_IMPORT_MAX_EXTRACTED_BYTES', 4294967296, 8589934592),
    'files' => $integer('NBE_IMPORT_MAX_FILES', 50000, 100000),
    'file' => $integer('NBE_IMPORT_MAX_FILE_BYTES', 268435456, 1073741824),
    'xml' => $integer('NBE_IMPORT_MAX_XML_BYTES', 67108864, 134217728),
    'items' => $integer('NBE_IMPORT_MAX_ITEMS', 100000, 200000),
    'operator_ack' => true,
];
$required = filesize($real) + $limits['bytes'] + 1073741824;
$free = disk_free_space(\NBE\Migration::root());
if ($free === false || $free < $required) {
    nbe_cli_fail('Storage preflight failed: require archive + maximum expansion + 1 GiB working reserve.');
}
$authors = [];
$mapFile = getenv('NBE_IMPORT_AUTHOR_MAP') ?: '';
if ($mapFile !== '') {
    $mapReal = realpath($mapFile);
    if (!$mapReal || !str_starts_with($mapReal, '/imports/') || !is_file($mapReal)) {
        nbe_cli_fail('NBE_IMPORT_AUTHOR_MAP must be a file below /imports.');
    }
    $authors = json_decode(file_get_contents($mapReal), true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($authors)) {
        nbe_cli_fail('Author map must be a JSON object.');
    }
}
try {
    $job = \NBE\Migration::intake($real, $site, $user, $authors, $limits);
} catch (RuntimeException $e) {
    nbe_cli_fail($e->getMessage());
}
echo "Operator import accepted as job $job. The same archive/XML/path protections remain active.\n";
echo $authors ? "Author mapping supplied; worker may proceed.\n" : "Inventory will pause for author mapping in the site UI.\n";
