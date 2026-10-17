<?php

declare(strict_types=1);

namespace App\Mail;

use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\MimeTypes;

/**
 * Outgoing SMTP transport. Talks to the mail server directly (STARTTLS on 587,
 * implicit TLS on 465) and hides the mailer library from the rest of the app,
 * the same way {@see MailClient} hides the IMAP library.
 *
 * Note on error reporting: the transport's own exceptions can carry the raw
 * SMTP conversation, which includes the base64-encoded credentials. This class
 * therefore never forwards a transport message to the caller; a failed send
 * reports only a short reason derived from the reply code and the port we
 * configured, never the conversation itself.
 *
 * Not final so tests can substitute a capturing transport, the same way
 * {@see MailClient} is subclassed for the IMAP tests.
 */
class SmtpMailSender implements MailSender
{
    private ?TransportInterface $transport = null;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $username,
        private readonly string $password,
        private readonly bool $implicitTls = false,
        private readonly bool $validateCert = true,
    ) {
    }

    /**
     * @param string $encryption "ssl" for implicit TLS (port 465), anything else
     *                           for plain SMTP with opportunistic STARTTLS (port 587)
     */
    public static function create(
        string $host,
        int $port,
        string $encryption,
        string $username,
        string $password,
        bool $validateCert = true,
    ): self {
        return new self($host, $port, $username, $password, 'ssl' === strtolower(trim($encryption)), $validateCert);
    }

    public function send(OutgoingMessage $message): array
    {
        $email = $this->build($message);

        try {
            $this->transport()->send($email);
        } catch (\Throwable $e) {
            throw new MailException('The email could not be sent. '.$this->reason($e));
        }

        $messageId = $email->getHeaders()->get('Message-ID')?->getBodyAsString() ?? '';

        return ['message_id' => $messageId, 'raw' => $email->toString()];
    }

    public function check(): void
    {
        $transport = $this->transport();

        if (!$transport instanceof SmtpTransport) {
            throw new MailException('The outgoing mail server is not configured.');
        }

        try {
            // start() performs the full handshake: greeting, EHLO, STARTTLS when
            // offered, and authentication. No message is sent.
            $transport->start();
        } catch (\Throwable $e) {
            throw new MailException($this->reason($e));
        } finally {
            $transport->stop();
        }
    }

    /**
     * Explain a transport failure in one short sentence, so an operator can
     * tell a blocked port from a rejected password.
     *
     * The exception's own message is deliberately never forwarded: Symfony's
     * SMTP errors quote the server's reply and, in debug output, the raw
     * conversation — which carries the base64-encoded credentials. Only the
     * reply *code* and a few fixed phrases from the mailer library are read
     * here, and the text is built from our own configuration.
     */
    private function reason(\Throwable $error): string
    {
        $code = $error->getCode();

        // A numeric reply code means the server did answer and refused us, so
        // the credentials — or the account's permission to send — are the
        // likely cause. 535 is "authentication failed".
        if (\is_int($code) && $code >= 400 && $code < 600) {
            return sprintf(
                'The mail server rejected the connection (SMTP %d). Check the sending address and its mail password.',
                $code,
            );
        }

        $message = $error->getMessage();

        if (str_contains($message, 'TLS')) {
            return sprintf(
                'The connection to %s:%d could not be secured with TLS. The host may have outdated CA certificates.',
                $this->host,
                $this->port,
            );
        }

        if (false !== stripos($message, 'authenticat')) {
            return 'The mail server rejected the credentials. Check the sending address and its mail password.';
        }

        return sprintf(
            'The mail server could not be reached at %s:%d. Check that the host allows outgoing connections on this port; Infomaniak also accepts 465 with INFOMANIAK_SMTP_ENCRYPTION=ssl and 2525 with starttls.',
            $this->host,
            $this->port,
        );
    }

    private function build(OutgoingMessage $message): Email
    {
        $email = (new Email())
            ->from($this->username)
            ->subject($message->subject)
            ->to(...$message->to);

        if ([] !== $message->cc) {
            $email->cc(...$message->cc);
        }

        if ([] !== $message->bcc) {
            $email->bcc(...$message->bcc);
        }

        if (null !== $message->replyTo) {
            $email->replyTo($message->replyTo);
        }

        if ('' !== $message->text) {
            $email->text($message->text);
        }

        if ('' !== $message->html) {
            $email->html($message->html);
        }

        foreach ($message->attachments as $attachment) {
            $email->attach($attachment['content'], $attachment['name'], $this->contentType($attachment));
        }

        $headers = $email->getHeaders();
        $headers->addTextHeader('X-Mailer', 'infomaniak-mcp');
        $headers->addIdHeader('Message-ID', $email->generateMessageId());

        if (null !== $message->inReplyTo && '' !== trim($message->inReplyTo)) {
            $parent = self::stripBrackets($message->inReplyTo);
            $headers->addIdHeader('In-Reply-To', $parent);
            $headers->addIdHeader('References', $parent);
        }

        return $email;
    }

    /**
     * @param array{name: string, content: string, contentType: string} $attachment
     */
    private function contentType(array $attachment): string
    {
        if ('' !== $attachment['contentType']) {
            return $attachment['contentType'];
        }

        $extension = pathinfo($attachment['name'], \PATHINFO_EXTENSION);
        if ('' !== $extension) {
            $types = MimeTypes::getDefault()->getMimeTypes($extension);
            if ([] !== $types) {
                return $types[0];
            }
        }

        return 'application/octet-stream';
    }

    private function transport(): TransportInterface
    {
        return $this->transport ??= $this->createTransport();
    }

    /**
     * @internal overridden in tests to inject a transport that captures messages
     *           instead of connecting to a server
     */
    protected function createTransport(): TransportInterface
    {
        return Transport::fromDsn($this->dsn());
    }

    private function dsn(): string
    {
        $dsn = sprintf(
            '%s://%s:%s@%s:%d',
            $this->implicitTls ? 'smtps' : 'smtp',
            rawurlencode($this->username),
            rawurlencode($this->password),
            $this->host,
            $this->port,
        );

        return $this->validateCert ? $dsn : $dsn.'?verify_peer=0';
    }

    private static function stripBrackets(string $id): string
    {
        return trim($id, " \t\n\r\0\x0B<>");
    }
}
