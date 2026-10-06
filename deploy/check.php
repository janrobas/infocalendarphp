<?php

declare(strict_types=1);

/**
 * Deployment preflight check for the Infomaniak Calendar & Mail MCP server.
 *
 * Run it from anywhere in the project (locally, or over SSH on the host):
 *
 *   php deploy/check.php
 *
 * It verifies the PHP version, the required extensions, and writable storage,
 * so you can confirm a hosting account is ready before uploading/configure.
 * It is intentionally dependency-free (no autoload required).
 */

const REQUIRED_PHP = '8.3.0';

$requiredExtensions = [
    'curl' => 'CalDAV HTTP client',
    'mbstring' => 'iCalendar / UTF-8 handling',
    'openssl' => 'TLS and secure random values',
    'fileinfo' => 'required by the MCP SDK and IMAP',
    'dom' => 'CalDAV XML parsing',
    'xml' => 'XML',
    'xmlwriter' => 'XML',
    'json' => 'JSON',
    'ctype' => 'string handling',
    'iconv' => 'required by the IMAP library',
    'zip' => 'required by the IMAP library',
];

$root = dirname(__DIR__);

$lines = [];
$failures = 0;

$check = function (bool $ok, string $label, string $detail = '') use (&$lines, &$failures): void {
    $lines[] = sprintf('[%s] %s%s', $ok ? ' OK ' : 'FAIL', $label, '' === $detail ? '' : ' — '.$detail);
    if (!$ok) {
        ++$failures;
    }
};

echo "Infomaniak Calendar & Mail MCP — deployment check\n";
echo 'Project root: '.$root."\n";
echo 'PHP: '.php_sapi_name().' '.PHP_VERSION."\n\n";

$check(version_compare(PHP_VERSION, REQUIRED_PHP, '>='), 'PHP version', PHP_VERSION.' (requires '.REQUIRED_PHP.'+)');

foreach ($requiredExtensions as $extension => $purpose) {
    $check(extension_loaded($extension), 'ext/'.$extension, $purpose);
}

$check(
    is_file($root.'/vendor/autoload.php'),
    'vendor/autoload.php',
    'run "composer install --no-dev" locally and upload the vendor/ directory',
);

$check(
    is_file($root.'/.env'),
    '.env present',
    'copy .env.example to .env and fill in the values',
);

$dataDir = $root.'/data';
if (!is_dir($dataDir)) {
    @mkdir($dataDir, 0770, true);
}
$check(is_dir($dataDir) && is_writable($dataDir), 'data/ writable', $dataDir);

echo implode("\n", $lines)."\n";

echo "\nResult: ".(0 === $failures ? "all checks passed\n" : sprintf("%d problem(s) found\n", $failures));

exit(0 === $failures ? 0 : 1);
