<?php

declare(strict_types=1);

namespace App\OAuth;

use RuntimeException;

final class OAuthException extends RuntimeException
{
    public function __construct(
        public readonly string $error,
        string $description,
        public readonly int $status = 400,
    ) {
        parent::__construct($description);
    }

    public static function invalidRequest(string $description): self
    {
        return new self('invalid_request', $description, 400);
    }

    public static function invalidClient(string $description = 'Client authentication failed.'): self
    {
        return new self('invalid_client', $description, 401);
    }

    public static function invalidGrant(string $description = 'The provided authorization grant is invalid.'): self
    {
        return new self('invalid_grant', $description, 400);
    }

    public static function invalidScope(string $description = 'The requested scope is invalid.'): self
    {
        return new self('invalid_scope', $description, 400);
    }

    public static function accessDenied(string $description = 'The user denied the request.'): self
    {
        return new self('access_denied', $description, 400);
    }
}
