<?php

declare(strict_types=1);

namespace App\Mail;

/**
 * A validated message ready to be handed to an outgoing transport. Built by
 * {@see MailService}, which is responsible for all input validation; the
 * transport only has to encode and send it.
 *
 * The sender identity (`From`) is not part of the message: it belongs to the
 * transport, which owns the SMTP credentials.
 */
final class OutgoingMessage
{
    /**
     * @param list<string>                                                    $to
     * @param list<string>                                                    $cc
     * @param list<string>                                                    $bcc
     * @param list<array{name: string, content: string, contentType: string}> $attachments
     */
    public function __construct(
        public readonly array $to,
        public readonly string $subject,
        public readonly string $text,
        public readonly string $html,
        public readonly array $cc = [],
        public readonly array $bcc = [],
        public readonly ?string $replyTo = null,
        public readonly ?string $inReplyTo = null,
        public readonly array $attachments = [],
    ) {
    }
}
