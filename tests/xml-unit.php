<?php

declare(strict_types=1);
require __DIR__.'/../app/mu-plugins/nbe/Policy.php';
require __DIR__.'/../app/mu-plugins/nbe/Archive.php';

use NBE\Archive;
use NBE\Policy;

$count = 0;
function verifyXml(bool $condition, string $label): void
{
    global $count;
    ++$count;
    if (!$condition) {
        throw new RuntimeException($label);
    }
}
function rejectXml(callable $operation, string $label): void
{
    try {
        $operation();
    } catch (Throwable $error) {
        verifyXml(true, $label);
        return;
    }
    verifyXml(false, $label);
}

$fixture = __DIR__.'/fixtures/publication.xml';
$data = Archive::wxr($fixture);
verifyXml(count($data['items']) === 6, 'all fixture items parsed');
verifyXml($data['items'][0]['author'] === 'source-alice', 'author preserved');
verifyXml(count($data['items'][0]['comments']) === 2, 'nested comments parsed');
verifyXml(str_contains($data['items'][0]['content'], 'Grüße'), 'Unicode preserved');
verifyXml(!Policy::safeMeta('oauth_token'), 'credential metadata denied');
verifyXml(Policy::safeMeta('public_credit'), 'portable metadata allowed');
verifyXml(!Policy::safePath('../escape'), 'archive traversal denied');
verifyXml(!Policy::safePath('/absolute'), 'absolute archive path denied');
rejectXml(fn () => Archive::wxr($fixture, ['items' => 5]), 'item limit');
rejectXml(fn () => Archive::wxr($fixture, ['xml' => 67108864]), 'elevated XML limit requires acknowledgement');

$temp = tempnam(sys_get_temp_dir(), 'nbe-xml-');
try {
    foreach ([
        '<!DOCTYPE rss [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><rss><channel>&xxe;</channel></rss>',
        '<rss><channel>',
        '<rss><channel/></rss>',
    ] as $index => $xml) {
        file_put_contents($temp, $xml);
        rejectXml(fn () => Archive::wxr($temp), 'malicious/malformed XML '.$index);
    }
} finally {
    unlink($temp);
}
echo "PASS $count XML/policy assertions\n";
