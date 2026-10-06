<?php

declare(strict_types=1);

namespace App\Mail;

use RuntimeException;

/**
 * Raised for mail-level errors whose message is safe to surface to the client
 * (invalid input, unknown mailbox/message, authentication/setup failures).
 *
 * Messages never include the mailbox password or message content.
 */
final class MailException extends RuntimeException
{
}
