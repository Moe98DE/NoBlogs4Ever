<?php

declare(strict_types=1);
$root = dirname(__DIR__);
$runtime = json_decode(file_get_contents($root.'/docs/evidence/runtime-versions.json'), true, 512, JSON_THROW_ON_ERROR);
$dependencies = json_decode(file_get_contents($root.'/dependencies.lock.json'), true, 512, JSON_THROW_ON_ERROR);
$digest = getenv('IMAGE_DIGEST') ?: '';
if (!preg_match('/^sha256:[a-f0-9]{64}$/', $digest)) {
    fwrite(STDERR, "IMAGE_DIGEST must be the pushed OCI digest.\n");
    exit(2);
}
$manifest = [
    'schema' => 1,
    'release' => getenv('RELEASE_TAG') ?: '',
    'source_commit' => getenv('SOURCE_COMMIT') ?: '',
    'image_digest' => $digest,
    'runtime' => $runtime,
    'dependency_lock_sha256' => hash_file('sha256', $root.'/dependencies.lock.json'),
    'dependencies' => array_map(fn (array $item): array => ['kind' => $item['kind'], 'slug' => $item['slug'], 'version' => $item['version'], 'sha256' => $item['sha256']], $dependencies),
    'reproducibility' => ['source_archive' => 'git archive plus gzip -n', 'image' => 'base and downloads pinned; Debian/Alpine package repository snapshots remain non-reproducible inputs'],
];
echo json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
