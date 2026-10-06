<?php

/**
 * Download every dependency in dependencies.lock.json, verify its SHA-256 and
 * unpack it into a WordPress tree. Used by the Dockerfile; run manually with
 *   php scripts/fetch.php /path/to/empty/wordpress
 *
 * Kinds: core → the target itself, plugin → wp-content/plugins,
 * theme → wp-content/themes, language → wp-content/languages.
 * These are trusted, checksum-locked operator inputs, never tenant uploads.
 */

declare(strict_types=1);

$target = rtrim($argv[1] ?? '/var/www/html', '/');
$lock = json_decode((string) file_get_contents(__DIR__.'/../dependencies.lock.json'), true, 512, JSON_THROW_ON_ERROR);
usort($lock, fn ($a, $b) => ($a['kind'] === 'core' ? 0 : 1) <=> ($b['kind'] === 'core' ? 0 : 1));

foreach ($lock as $dep) {
    $tmp = (string) tempnam(sys_get_temp_dir(), 'nbe-');
    $handle = fopen($tmp, 'wb');
    $curl = curl_init($dep['url']);
    curl_setopt_array($curl, [
        CURLOPT_FILE => $handle,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_FAILONERROR => true,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_TIMEOUT => 600,
    ]);
    $ok = curl_exec($curl);
    $error = curl_error($curl);
    curl_close($curl);
    fclose($handle);
    if (!$ok) {
        throw new RuntimeException("Dependency download failed: {$dep['slug']} ({$error})");
    }
    $actual = hash_file('sha256', $tmp);
    if (!hash_equals($dep['sha256'], (string) $actual)) {
        throw new RuntimeException("Dependency checksum mismatch: {$dep['slug']} expected {$dep['sha256']} got {$actual}. See docs/contributing/dependencies.md.");
    }
    $dest = match ($dep['kind']) {
        'core' => dirname($target),
        'plugin' => $target.'/wp-content/plugins',
        'theme' => $target.'/wp-content/themes',
        'language' => $target.'/wp-content/languages',
        default => throw new RuntimeException('Unknown dependency kind: '.$dep['kind']),
    };
    if (!is_dir($dest) && !mkdir($dest, 0755, true)) {
        throw new RuntimeException('Cannot create '.$dest);
    }
    $zip = new ZipArchive();
    if ($zip->open($tmp) !== true || !$zip->extractTo($dest)) {
        throw new RuntimeException('Dependency extraction failed: '.$dep['slug']);
    }
    $zip->close();
    unlink($tmp);
    if ($dep['kind'] === 'core') {
        if (basename($target) !== 'wordpress') {
            rename(dirname($target).'/wordpress', $target);
        }
        // Only curated, locked plugins and themes may exist in the image.
        foreach (['plugins', 'themes'] as $folder) {
            $path = $target.'/wp-content/'.$folder;
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
        }
    }
    echo "{$dep['kind']} {$dep['slug']} {$dep['version']} verified\n";
}
