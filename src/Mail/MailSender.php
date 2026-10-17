<?php

declare(strict_types=1);

namespace App\Mail;

/**
 * Outgoing mail transport.
 *
 * Kept as an interface so the mail logic (and its tests) never touch an SMTP
 * library, mirroring how {@see MailClient} hides the IMAP library. Implemented
 * by {@see SmtpMailSender}; a missing sender means sending is disabled.
 */
interface MailSender
{
    /**
     * Send the message and return its Message-ID plus the raw MIME that was
     * transmitted (so a copy can be appended to the mailbox's Sent folder).
     *
     * @return array{message_id: string, raw: string}
     *
     * @throws MailException when the message cannot be sent
     */
    public function send(OutgoingMessage $message): array;

    /**
     * Verify that the outgoing server is reachable and the credentials are
     * accepted, without sending anything.
     *
     * @throws MailException when it is not
     */
    public function check(): void;
}
