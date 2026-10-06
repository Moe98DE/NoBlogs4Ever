<?php

declare(strict_types=1);
require __DIR__.'/../app/mu-plugins/nbe/Policy.php';
require __DIR__.'/../app/mu-plugins/nbe/Archive.php';
use NBE\Policy;
use NBE\Archive;

$count = 0;
function check(bool $ok, string $message): void
{
    global $count;
    $count++;
    if (!$ok) {
        throw new RuntimeException($message);
    }
}
function rejects(callable $f, string $message): void
{
    try {
        $f();
    } catch (Throwable $e) {
        check(true, $message);
        return;
    }check(false, $message);
}
foreach (['garden','a-bc','site1234'] as $s) {
    check(Policy::slug($s), 'valid slug '.$s);
}
foreach (['admin','www','mail','xn--test','foo.bar','-abc','abc-','école','a','ab','abc',str_repeat('a', 64),'UPPER'] as $s) {
    check(!Policy::slug($s), 'unsafe slug '.$s);
}
check(Policy::emailAllowed('a@safe.org', 'allowlist', ['safe.org'], []), 'allowlisted');
check(!Policy::emailAllowed('a@safe.org', 'allowlist', ['safe.org'], ['safe.org']), 'deny wins');
check(!Policy::emailAllowed('a@sub.safe.org', 'allowlist', ['safe.org'], []), 'exact domain');
check(Policy::emailAllowed('a@any.org', 'unrestricted', [], []), 'unrestricted');
check(!Policy::safeMeta('oauth_token'), 'secret metadata');
check(Policy::safeMeta('public_credit'), 'portable metadata');
check(count(Policy::sourceReferences('<img src="https://old.example/files/a.jpg">', 'old.example')) === 1, 'old domain detected');
check(count(Policy::sourceReferences('https://old.example.evil/a', 'old.example')) === 0, 'host boundary');
foreach (['../a','a/../../b','/etc/passwd','C:/file','a\\b','./x',"a\0b"] as $p) {
    check(!Policy::safePath($p), 'unsafe path');
}
$root = sys_get_temp_dir().'/nbe-unit-'.bin2hex(random_bytes(5));
mkdir($root, 0700, true);
function archiveCase(string $root, string $name, string $path, string $content, ?int $attributes = null): void
{
    $z = new ZipArchive();
    $file = $root.'/'.$name.'.zip';
    $z->open($file, ZipArchive::CREATE);
    $z->addFromString($path, $content);
    if ($attributes !== null) {
        $z->setExternalAttributesName($path, ZipArchive::OPSYS_UNIX, $attributes);
    }$z->close();
    rejects(fn () => Archive::extract($file, $root.'/'.$name), 'reject '.$name);
}
archiveCase($root, 'traversal', '../escaped.txt', 'evil');
archiveCase($root, 'absolute', '/tmp/escaped.txt', 'evil');
archiveCase($root, 'link', 'link', '../../etc/passwd', 0120777 << 16);
archiveCase($root, 'bomb', 'bomb.xml', str_repeat('A', 2000000));
foreach (['<!DOCTYPE rss [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><rss><channel>&xxe;</channel></rss>','<rss><channel>','<rss><channel/></rss>'] as $n => $xml) {
    $path = $root.'/bad'.$n.'.xml';
    file_put_contents($path, $xml);
    rejects(fn () => Archive::wxr($path), 'invalid XML');
}
$data = Archive::wxr(__DIR__.'/fixtures/publication.xml');
check(count($data['items']) === 6, 'fixture items');
check(count($data['items'][0]['comments']) === 2, 'nested comments parsed');
check($data['items'][0]['author'] === 'source-alice', 'source author');
check(str_contains($data['items'][0]['content'], 'Grüße'), 'unicode preserved');
rejects(fn () => Archive::wxr(__DIR__.'/fixtures/publication.xml', ['items' => 5]), 'configured item limit enforced');
rejects(fn () => Archive::wxr(__DIR__.'/fixtures/publication.xml', ['xml' => 67108864]), 'elevated limit requires operator acknowledgement');
check(count(Archive::wxr(__DIR__.'/fixtures/publication.xml', ['items' => 10, 'operator_ack' => true])['items']) === 6, 'operator limits retain parser protections');

// Slug edge cases: IDNA-reserved "--" positions and operator additions.
check(!Policy::slug('ab--cd'), 'IDNA-style double hyphen rejected');
check(!Policy::slug('newsroom', ['newsroom']), 'operator-reserved slug rejected');
check(Policy::slug('my-site'), 'ordinary hyphenated slug accepted');
check(!Policy::selfRegistrationOpen('invitation') && Policy::selfRegistrationOpen('approval'), 'registration modes');

// Aggregate analytics classification never returns identifiers.
$firefox = Policy::userAgentClass('Mozilla/5.0 (Android 14; Mobile; rv:128.0) Gecko/128.0 Firefox/128.0');
check($firefox === ['bot' => false, 'browser' => 'firefox', 'device' => 'mobile'], 'mobile Firefox classified');
check(Policy::userAgentClass('Mozilla/5.0 (Macintosh) AppleWebKit/605 (KHTML, like Gecko) Version/17 Safari/605')['browser'] === 'safari', 'Safari classified');
check(Policy::userAgentClass('Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537 Chrome/130 Safari/537 Edg/130')['browser'] === 'edge', 'Edge is not mistaken for Chrome');
check(Policy::userAgentClass('Googlebot/2.1 (+http://www.google.com/bot.html)')['bot'], 'crawler classified as automated');
check(Policy::userAgentClass('')['bot'], 'empty user agent treated as automated');

// Iframe allowlist.
check(Policy::iframeAllowed('https://www.openstreetmap.org/export/embed.html', ['www.openstreetmap.org']), 'exact iframe host allowed');
check(Policy::iframeAllowed('https://maps.example.org/x', ['.example.org']), 'domain suffix allowlist');
check(!Policy::iframeAllowed('https://example.org.evil.test/x', ['.example.org']), 'suffix boundary enforced');
check(!Policy::iframeAllowed('http://www.openstreetmap.org/', ['www.openstreetmap.org']), 'plain HTTP iframe refused');
check(!Policy::iframeAllowed('https://user@www.openstreetmap.org/', ['www.openstreetmap.org']), 'credentials in iframe URL refused');
check(!Policy::iframeAllowed('javascript:alert(1)', ['www.openstreetmap.org']), 'script URL refused');
check(!Policy::iframeAllowed('https://www.openstreetmap.org/', []), 'empty allowlist refuses everything');

// Imported CSS safety.
check(Policy::safeCss('body { color: #222; }'), 'plain CSS accepted');
check(!Policy::safeCss('</style><script>alert(1)</script>'), 'style breakout refused');
check(!Policy::safeCss('a { background: url(javascript:alert(1)) }'), 'javascript: URL refused');
check(!Policy::safeCss('a { width: expression(alert(1)) }'), 'CSS expression refused');

// Derivatives and author logins.
check(Policy::derivative('https://x/files/2020/photo-300x200.jpg') === ['base' => 'https://x/files/2020/photo.jpg', 'width' => 300, 'height' => 200], 'derivative parsed');
check(Policy::derivative('https://x/files/2020/photo.jpg') === null, 'original is not a derivative');
check(Policy::loginFromSource('Ana María') === 'anamara' && Policy::loginFromSource('jo') === 'authorjo', 'logins derived conservatively');

// Archives with several WXR files, a sitemap and site.json.
$set = $root.'/set';
mkdir($set);
$wxrA = file_get_contents(__DIR__.'/fixtures/publication.xml');
file_put_contents($set.'/a.xml', $wxrA);
file_put_contents($set.'/b.xml', str_replace(['<wp:post_id>10', 'story-10'], ['<wp:post_id>90', 'other-90'], $wxrA));
file_put_contents($set.'/sitemap.xml', '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>https://x/</loc></url></urlset>');
$merged = Archive::wxrSet([$set.'/a.xml', $set.'/b.xml', $set.'/sitemap.xml']);
check(count($merged['items']) === 12 && $merged['skipped'] === ['sitemap.xml'], 'multiple WXR files merge; non-WXR XML skipped');
rejects(fn () => Archive::wxrSet([$set.'/a.xml', $set.'/a.xml']), 'duplicate source IDs across files rejected');
file_put_contents($set.'/c.xml', str_replace('https://source.example', 'https://elsewhere.example', $wxrA));
rejects(fn () => Archive::wxrSet([$set.'/a.xml', $set.'/c.xml']), 'WXR files from different sources rejected');
$z = new ZipArchive();
$z->open($root.'/full.zip', ZipArchive::CREATE);
$z->addFile($set.'/a.xml', 'publication.xml');
$z->addFromString('site.json', '{"format":"noblogs4ever-site-archive/1"}');
$z->addFromString('uploads/2020/a b.png', 'x');
$z->addFromString('theme/functions.php', '<?php evil();');
$z->close();
$inv = Archive::extract($root.'/full.zip', $root.'/full');
check($inv['config'] === ['site.json' => 'site.json'] && $inv['media'] === ['uploads/2020/a b.png'] && $inv['ignored'] === ['theme/functions.php'], 'archive inventory separates config, media and ignored code');
check(!is_executable($root.'/full/theme/functions.php') && (fileperms($root.'/full/theme/functions.php') & 0777) === 0600, 'extracted files are private and non-executable');
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
    $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
}rmdir($root);
echo "PASS $count unit/security assertions\n";
