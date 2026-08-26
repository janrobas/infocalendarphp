<?php

declare(strict_types=1);

/**
 * Generate a bcrypt password hash for OAUTH_PASSWORD_HASH.
 *
 * Usage:
 *   php bin/generate-password.php "your-password"
 *   php bin/generate-password.php           # prompts on STDIN
 */

require __DIR__.'/../vendor/autoload.php';

$password = $argv[1] ?? null;

if (null === $password || '' === $password) {
    fwrite(\STDERR, 'Password: ');
    $password = fgets(\STDIN);
    if (false === $password || '' === trim($password)) {
        fwrite(\STDERR, "No password provided.\n");

        exit(1);
    }
}

echo password_hash(trim($password), \PASSWORD_DEFAULT).\PHP_EOL;
