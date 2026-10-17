<?php

declare(strict_types=1);

namespace App\Tests;

use App\Mail\MailException;
use App\Mail\OutgoingMessage;
use App\Mail\SmtpMailSender;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/**
 * Covers what the SMTP sender prepares for the server. A capturing transport is
 * injected, so no connection is made; the connection itself is exercised by
 * /health/mail against a real account.
 */
final class SmtpMailSenderTest extends TestCase
{
    public function testItSendsTheMessageFromTheAccountAddress(): void
    {
        $transport = new CapturingTransport();
        $sender = $this->sender($transport);

        $result = $sender->send($this->message());

        self::assertCount(1, $transport->sent);

        $email = $transport->sent[0];
        self::assertInstanceOf(Email::class, $email);
        self::assertSame('me@example.com', $email->getFrom()[0]->getAddress());
        self::assertSame(['alice@example.com'], array_map(static fn ($a) => $a->getAddress(), $email->getTo()));
        self::assertSame(['carol@example.com'], array_map(static fn ($a) => $a->getAddress(), $email->getCc()));
        self::assertSame('Design review', $email->getSubject());
        self::assertSame('See you at 2pm.', $email->getTextBody());
        self::assertSame('infomaniak-mcp', $email->getHeaders()->get('X-Mailer')?->getBodyAsString());

        $messageId = $email->getHeaders()->get('Message-ID')?->getBodyAsString();
        self::assertIsString($messageId);
        self::assertSame($messageId, $result['message_id']);
        self::assertStringContainsString('Subject: Design review', $result['raw']);
    }

    public function testItSetsTheThreadingHeaders(): void
    {
        $transport = new CapturingTransport();
        $sender = $this->sender($transport);

        $sender->send($this->message(inReplyTo: 'abc@example.com'));

        $headers = $transport->sent[0]->getHeaders();

        self::assertSame('<abc@example.com>', $headers->get('In-Reply-To')?->getBodyAsString());
        self::assertSame('<abc@example.com>', $headers->get('References')?->getBodyAsString());
    }

    public function testTheSentCopyNeverCarriesTheBccHeader(): void
    {
        $transport = new CapturingTransport();
        $sender = $this->sender($transport);

        $result = $sender->send($this->message(bcc: ['hidden@example.com']));

        self::assertStringNotContainsString('Bcc', $result['raw']);
        self::assertStringNotContainsString('hidden@example.com', $result['raw']);
    }

    public function testItAttachesContentAndGuessesTheContentType(): void
    {
        $transport = new CapturingTransport();
        $sender = $this->sender($transport);

        $result = $sender->send($this->message(attachments: [
            ['name' => 'report.pdf', 'content' => '%PDF-1.4 payload', 'contentType' => ''],
            ['name' => 'data.bin', 'content' => 'binary', 'contentType' => 'application/octet-stream'],
        ]));

        self::assertStringContainsString('Content-Type: application/pdf', $result['raw']);
        self::assertStringContainsString('name=report.pdf', $result['raw']);
        self::assertStringContainsString('application/octet-stream', $result['raw']);
        self::assertStringContainsString(base64_encode('%PDF-1.4 payload'), $result['raw']);
    }

    public function testItSendsHtmlOnlyMessages(): void
    {
        $transport = new CapturingTransport();
        $sender = $this->sender($transport);

        $sender->send($this->message(text: '', html: '<p>Hello</p>'));

        self::assertStringContainsString('<p>Hello</p>', $transport->sent[0]->getBody()?->toString() ?? '');
    }

    public function testItNeverLeaksTransportDetailsWhenSendingFails(): void
    {
        $transport = new CapturingTransport(new TransportException(
            'Failed to authenticate. AUTH LOGIN (base64-of-the-password-in-debug)',
        ));
        $sender = $this->sender($transport);

        try {
            $sender->send($this->message());
            self::fail('The failure should have been turned into a MailException.');
        } catch (MailException $e) {
            self::assertStringNotContainsString('base64-of-the-password-in-debug', $e->getMessage());
            self::assertStringNotContainsString('AUTH LOGIN', $e->getMessage());
        }
    }

    public function testCheckRefusesATransportThatIsNotSmtp(): void
    {
        $sender = $this->sender(new CapturingTransport());

        $this->expectException(MailException::class);
        $this->expectExceptionMessage('The outgoing mail server is not configured.');
        $sender->check();
    }

    public function testItRoundTripsCredentialsThatContainUrlCharacters(): void
    {
        // A mail password is user-chosen and may contain @, :, /, # and ?.
        // They must survive the trip through the mailer DSN, or authentication
        // fails in a way that is very hard to diagnose.
        $sender = new SmtpMailSender(
            host: 'mail.infomaniak.com',
            port: 465,
            username: 'me+sorted@example.com',
            password: 'p@ss:w/rd#1?x',
            implicitTls: true,
        );

        $dsn = Dsn::fromString((new \ReflectionMethod($sender, 'dsn'))->invoke($sender));

        self::assertSame('smtps', $dsn->getScheme());
        self::assertSame(465, $dsn->getPort());
        self::assertSame('me+sorted@example.com', $dsn->getUser());
        self::assertSame('p@ss:w/rd#1?x', $dsn->getPassword());
    }

    private function sender(TransportInterface $transport): SmtpMailSender
    {
        return new class($transport) extends SmtpMailSender {
            public function __construct(private readonly TransportInterface $capturing)
            {
                parent::__construct(
                    host: 'mail.infomaniak.com',
                    port: 587,
                    username: 'me@example.com',
                    password: 'secret',
                    implicitTls: false,
                );
            }

            protected function createTransport(): TransportInterface
            {
                return $this->capturing;
            }
        };
    }

    /**
     * @param list<string>                                                    $cc
     * @param list<string>                                                    $bcc
     * @param list<array{name: string, content: string, contentType: string}> $attachments
     */
    private function message(
        string $text = 'See you at 2pm.',
        string $html = '',
        array $cc = ['carol@example.com'],
        array $bcc = [],
        ?string $inReplyTo = null,
        array $attachments = [],
    ): OutgoingMessage {
        return new OutgoingMessage(
            to: ['alice@example.com'],
            subject: 'Design review',
            text: $text,
            html: $html,
            cc: $cc,
            bcc: $bcc,
            inReplyTo: $inReplyTo,
            attachments: $attachments,
        );
    }
}

final class CapturingTransport implements TransportInterface
{
    /** @var list<RawMessage> */
    public array $sent = [];

    public function __construct(private readonly ?\Throwable $error = null)
    {
    }

    public function send(RawMessage $message, ?\Symfony\Component\Mailer\Envelope $envelope = null): ?\Symfony\Component\Mailer\SentMessage
    {
        if (null !== $this->error) {
            throw $this->error;
        }

        $this->sent[] = $message;

        return null;
    }

    public function __toString(): string
    {
        return 'capturing://';
    }
}
