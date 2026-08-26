<?php

declare(strict_types=1);

namespace App\Calendar;

use RuntimeException;

final class CalDavException extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $status = null)
    {
        parent::__construct($message);
    }

    public static function forStatus(int $status, string $notFoundMessage = 'Calendar not found.'): self
    {
        return new self(match ($status) {
            401 => 'Infomaniak authentication failed.',
            403 => 'Infomaniak denied the request (HTTP 403). The application password may not have write permission, or the calendar is read-only.',
            404 => $notFoundMessage,
            412 => 'Event was modified by another client.',
            default => sprintf('Calendar server unavailable (HTTP %d).', $status),
        }, $status);
    }
}
