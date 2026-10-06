<?php

// Run with WP-CLI. PHPMailer reads the password from the mounted secret; this script never prints it.
if (!defined('ABSPATH') || !defined('WP_CLI')) {
    throw new RuntimeException('Run through WP-CLI.');
}

/** Print an error and stop with a non-zero exit code (no stack trace). */
function nbe_cli_fail(string $message): void
{
    fwrite(STDERR, 'Error: '.$message."\n");
    exit(1);
}
if (getenv('APP_ENV') !== 'production') {
    nbe_cli_fail('SMTP qualification must use the production configuration.');
}
$host = strtolower((string)getenv('SMTP_HOST'));
if (!$host || in_array($host, ['mail', 'mailpit', 'localhost'], true) || !getenv('SMTP_USER') || !in_array(getenv('SMTP_TLS'), ['tls', 'ssl'], true) || (int)getenv('SMTP_PORT') === 1025 || !is_email((string)getenv('SMTP_FROM'))) {
    nbe_cli_fail('Production SMTP host, authentication, TLS, port, and sender are not fully configured.');
}
$recipient = getenv('SMTP_TEST_RECIPIENT') ?: '';
if (!is_email($recipient) || str_ends_with(strtolower($recipient), '@example.invalid')) {
    nbe_cli_fail('Set SMTP_TEST_RECIPIENT to an operator-controlled mailbox.');
}
$id = wp_generate_uuid4();
$sent = wp_mail($recipient, 'NoBlogs4Ever production mail qualification '.$id, "Provider-neutral SMTP qualification.\nMessage ID: $id\nUTC: ".gmdate('c'));
if (!$sent) {
    nbe_cli_fail('SMTP submission failed. Inspect provider-side sanitized delivery logs.');
}
echo "SMTP submission accepted. Confirm receipt and headers for message $id. No credentials were logged.\n";
