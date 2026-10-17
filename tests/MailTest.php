<?php

declare(strict_types=1);

namespace App\Tests;

use App\Mail\MailClient;
use App\Mail\MailException;
use App\Mail\MailSender;
use App\Mail\MailService;
use App\Mail\OutgoingMessage;
use App\Mail\PdfTextExtractor;
use App\Storage\FileStore;
use App\Storage\RateLimiter;

final class MailTest extends TestCase
{
    public function testListMailboxes(): void
    {
        $client = new FakeMailClient([
            ['name' => 'Inbox', 'path' => 'INBOX', 'delimiter' => '/', 'selectable' => true],
            ['name' => 'Sent', 'path' => 'INBOX/Sent', 'delimiter' => '/', 'selectable' => true],
        ]);

        $service = new MailService($client);
        $result = $service->listMailboxes();

        self::assertCount(2, $result['mailboxes']);
        self::assertSame('INBOX/Sent', $result['mailboxes'][1]['path']);
    }

    public function testListEmailsDefaultsToInboxAndCapsLimit(): void
    {
        $client = new FakeMailClient([], [
            ['uid' => 10, 'subject' => 'A', 'from' => 'a@example.com', 'to' => 'me@example.com', 'date' => '2026-08-26T10:00:00+00:00', 'seen' => true, 'flagged' => false, 'has_attachments' => false],
        ]);

        $service = new MailService($client, maxResults: 5);
        $result = $service->listEmails(null, null, null, 999, false);

        self::assertSame('INBOX', $result['mailbox']);
        self::assertSame(5, $client->lastSearch['limit']);
        self::assertCount(1, $result['messages']);
        self::assertSame(10, $result['messages'][0]['uid']);
    }

    public function testSearchEmailsWithNoCriteriaReturnsEmpty(): void
    {
        $client = new FakeMailClient([], []);
        $service = new MailService($client);

        $result = $service->searchEmails('   ', null, null, null, null, null, null, false);

        self::assertSame([], $result['messages']);
        self::assertSame([], $client->lastSearch);
    }

    public function testSearchEmailsBuildsCriteriaAndFormatsDates(): void
    {
        $client = new FakeMailClient([], []);
        $service = new MailService($client);

        $service->searchEmails(
            'invoice',
            'INBOX/Sent',
            '2026-08-01T00:00:00Z',
            '2026-08-31T00:00:00Z',
            'alice@example.com',
            'August',
            10,
            true,
        );

        self::assertSame('INBOX/Sent', $client->lastSearch['folder']);
        self::assertSame(10, $client->lastSearch['limit']);

        $criteria = $client->lastSearch['criteria'];
        self::assertSame('invoice', $criteria['text']);
        self::assertSame('alice@example.com', $criteria['from']);
        self::assertSame('August', $criteria['subject']);
        self::assertSame('2026-08-01', $criteria['since']);
        self::assertSame('2026-08-31', $criteria['before']);
        self::assertTrue($criteria['unseen']);
    }

    public function testSearchEmailsRejectsInvalidDate(): void
    {
        $client = new FakeMailClient([], []);
        $service = new MailService($client);

        $this->expectException(MailException::class);
        $this->expectExceptionMessage('Invalid start date');
        $service->searchEmails('x', null, 'not-a-date', null, null, null, null, false);
    }

    public function testSearchEmailsRejectsInvertedRange(): void
    {
        $client = new FakeMailClient([], []);
        $service = new MailService($client);

        $this->expectException(MailException::class);
        $this->expectExceptionMessage('start must be before end');
        $service->searchEmails('x', null, '2026-09-01', '2026-08-01', null, null, null, false);
    }

    public function testGetEmailReturnsBodyAndAttachments(): void
    {
        $client = new FakeMailClient([], [], [
            'uid' => 42,
            'subject' => 'Report',
            'from' => 'boss@example.com',
            'to' => 'me@example.com',
            'cc' => '',
            'date' => '2026-08-26T09:00:00+00:00',
            'seen' => false,
            'flagged' => false,
            'has_attachments' => true,
            'message_id' => '<abc@example.com>',
            'in_reply_to' => null,
            'text' => 'Please review the attached report.',
            'html' => '<p>Please review</p>',
            'attachments' => [['name' => 'report.pdf', 'size' => 1234, 'type' => 'application/pdf']],
        ]);

        $service = new MailService($client);
        $result = $service->getEmail('42', null, null);

        self::assertSame('Report', $result['email']['subject']);
        self::assertSame('Please review the attached report.', $result['email']['body']);
        self::assertFalse($result['email']['truncated']);
        self::assertSame('report.pdf', $result['email']['attachments'][0]['name']);
        self::assertArrayNotHasKey('html', $result['email']);
    }

    public function testGetEmailFallsBackToHtmlAndTruncates(): void
    {
        $client = new FakeMailClient([], [], [
            'uid' => 7,
            'subject' => 'HTML only',
            'from' => 'x@example.com',
            'to' => 'me@example.com',
            'date' => null,
            'seen' => true,
            'flagged' => false,
            'has_attachments' => false,
            'text' => '',
            'html' => '<p>Hello <b>world</b>, this is a longer HTML body.</p>',
            'attachments' => [],
        ]);

        $service = new MailService($client);
        $result = $service->getEmail('7', 'INBOX', 12);

        self::assertStringStartsWith('Hello world', $result['email']['body']);
        self::assertTrue($result['email']['truncated']);
        self::assertStringEndsWith('…', $result['email']['body']);
    }

    public function testCheckDelegatesToTheClient(): void
    {
        $client = new FakeMailClient();
        $service = new MailService($client);

        $service->check();

        self::assertSame(1, $client->checkCount);
    }

    public function testCheckPropagatesConnectionFailure(): void
    {
        $service = new MailService(new FakeMailClient(checkError: new MailException('Mail server unavailable.')));

        $this->expectException(MailException::class);
        $this->expectExceptionMessage('Mail server unavailable.');
        $service->check();
    }

    public function testMailboxPathFromListMailboxesIsAcceptedByListEmails(): void
    {
        $client = new FakeMailClient([
            ['name' => 'ŠCKR TODO', 'path' => '&AWA-CKR TODO', 'delimiter' => '/', 'selectable' => true],
        ]);

        $service = new MailService($client);
        $path = $service->listMailboxes()['mailboxes'][0]['path'];

        self::assertSame('&AWA-CKR TODO', $path);

        $result = $service->listEmails($path, null, null, null, false);

        self::assertSame('ŠCKR TODO', $client->lastSearch['folder']);
        self::assertSame('ŠCKR TODO', $result['mailbox']);
    }

    public function testMailboxAcceptsReadableNameAndWirePath(): void
    {
        $client = new FakeMailClient([], []);
        $service = new MailService($client);

        $service->listEmails('ŠCKR TODO', null, null, null, false);
        self::assertSame('ŠCKR TODO', $client->lastSearch['folder']);

        $service->searchEmails('x', '&AWA-CKR TODO', null, null, null, null, null, false);
        self::assertSame('ŠCKR TODO', $client->lastSearch['folder']);
    }

    public function testMailboxDecodesEveryPathSegment(): void
    {
        $client = new FakeMailClient([], []);
        $service = new MailService($client);

        $service->listEmails('INBOX/&AWA-CKR TODO/&-', null, null, null, false);

        self::assertSame('INBOX/ŠCKR TODO/&', $client->lastSearch['folder']);
    }

    public function testGetEmailDecodesMailboxPath(): void
    {
        $client = new FakeMailClient([], [], ['uid' => 3, 'subject' => 'S', 'text' => 'body']);
        $service = new MailService($client);

        $service->getEmail('3', '&AWA-CKR TODO', null);

        self::assertSame('ŠCKR TODO', $client->lastMessage['folder']);
    }

    public function testGetEmailRejectsInvalidUid(): void
    {
        $service = new MailService(new FakeMailClient());

        $this->expectException(MailException::class);
        $this->expectExceptionMessage('Invalid email uid.');
        $service->getEmail('nope', null, null);
    }

    public function testGetEmailNotFound(): void
    {
        $service = new MailService(new FakeMailClient([], [], null));

        $this->expectException(MailException::class);
        $this->expectExceptionMessage('Email not found.');
        $service->getEmail('1', null, null);
    }

    public function testCheckSenderIsUnavailableWhenSendingIsDisabled(): void
    {
        $service = new MailService(new FakeMailClient());

        $this->expectException(MailException::class);
        $this->expectExceptionMessage('Sending email is not enabled.');
        $service->checkSender();
    }

    public function testCheckSenderDelegatesToTheTransport(): void
    {
        $sender = new FakeMailSender();
        $service = new MailService(new FakeMailClient(), sender: $sender);

        $service->checkSender();

        self::assertSame(1, $sender->checkCount);
        self::assertTrue($service->canSend());
    }

    // -----------------------------------------------------------------------
    // read_attachment
    // -----------------------------------------------------------------------

    public function testReadAttachmentExtractsTheSelectedPdf(): void
    {
        $client = new FakeMailClient(attachment: [
            'name' => 'invoice.pdf',
            'type' => 'application/pdf',
            'size' => 42,
            'content' => 'PDF BYTES',
        ]);
        $pdf = new FakePdfTextExtractor(['text' => 'Total: 42 EUR', 'pages_read' => 1, 'pages_total' => 3, 'truncated' => true]);

        $service = new MailService($client, pdf: $pdf, maxBodyChars: 5000);
        $result = $service->readAttachment('9', 'invoice.pdf', 'INBOX/Sent', null);

        self::assertSame(['folder' => 'INBOX/Sent', 'uid' => 9, 'selector' => 'invoice.pdf'], $client->lastAttachmentRequest);
        self::assertSame('PDF BYTES', $pdf->lastContent, 'the attachment content is handed to the extractor');
        self::assertSame(5000, $pdf->lastMaxChars, 'the character limit is capped server-side');

        self::assertSame('invoice.pdf', $result['attachment']['name']);
        self::assertSame('application/pdf', $result['attachment']['type']);
        self::assertSame(42, $result['attachment']['size']);
        self::assertSame('Total: 42 EUR', $result['attachment']['text']);
        self::assertSame(3, $result['attachment']['pages_total']);
        self::assertTrue($result['attachment']['truncated']);
    }

    public function testReadAttachmentAllowsOmittingTheSelectorAndDecodesTheMailbox(): void
    {
        $client = new FakeMailClient(attachment: ['name' => 'a.pdf', 'type' => 'application/pdf', 'size' => 1, 'content' => 'x']);
        $pdf = new FakePdfTextExtractor();

        $service = new MailService($client, pdf: $pdf);
        $service->readAttachment('3', null, '&AWA-CKR TODO', null);

        self::assertSame('ŠCKR TODO', $client->lastAttachmentRequest['folder']);
        self::assertNull($client->lastAttachmentRequest['selector']);
    }

    public function testReadAttachmentCapsTheCharactersToTheBodyLimit(): void
    {
        $client = new FakeMailClient(attachment: ['name' => 'a.pdf', 'type' => 'application/pdf', 'size' => 1, 'content' => 'x']);
        $pdf = new FakePdfTextExtractor();

        $service = new MailService($client, pdf: $pdf, maxBodyChars: 100);
        $service->readAttachment('3', null, null, 999999);

        self::assertSame(100, $pdf->lastMaxChars);
    }

    public function testReadAttachmentRejectsANonPdfAttachment(): void
    {
        $client = new FakeMailClient(attachment: ['name' => 'photo.png', 'type' => 'image/png', 'size' => 10, 'content' => 'x']);
        $service = new MailService($client, pdf: new FakePdfTextExtractor());

        $this->expectException(MailException::class);
        $this->expectExceptionMessage('Only PDF attachments can be read; "photo.png" is image/png.');
        $service->readAttachment('1', 'photo.png', null, null);
    }

    public function testReadAttachmentReportsAMissingAttachment(): void
    {
        $service = new MailService(new FakeMailClient(attachment: null), pdf: new FakePdfTextExtractor());

        $this->expectException(MailException::class);
        $this->expectExceptionMessage('Attachment not found.');
        $service->readAttachment('1', 'nope.pdf', null, null);
    }

    public function testReadAttachmentRejectsAnInvalidUid(): void
    {
        $service = new MailService(new FakeMailClient(), pdf: new FakePdfTextExtractor());

        $this->expectException(MailException::class);
        $this->expectExceptionMessage('Invalid email uid.');
        $service->readAttachment('abc', null, null, null);
    }

    public function testReadAttachmentPropagatesExtractionErrors(): void
    {
        $client = new FakeMailClient(attachment: ['name' => 'a.pdf', 'type' => 'application/pdf', 'size' => 1, 'content' => 'x']);
        $service = new MailService($client, pdf: new FakePdfTextExtractor(error: new MailException('Unable to read the PDF.')));

        $this->expectException(MailException::class);
        $this->expectExceptionMessage('Unable to read the PDF.');
        $service->readAttachment('1', null, null, null);
    }

    public function testReadAttachmentIsUnavailableWithoutAnExtractor(): void
    {
        $service = new MailService(new FakeMailClient());

        $this->expectException(MailException::class);
        $this->expectExceptionMessage('Reading attachments is not available.');
        $service->readAttachment('1', null, null, null);
    }

    // -----------------------------------------------------------------------
    // send_email
    // -----------------------------------------------------------------------

    public function testSendEmailSendsTheMessageAndReportsTheResult(): void
    {
        $sender = new FakeMailSender();
        $service = new MailService(new FakeMailClient(), sender: $sender);

        $result = $service->sendEmail(
            to: 'alice@example.com, bob@example.com',
            subject: 'Hello',
            text: 'Body',
        );

        self::assertSame([
            'status' => 'sent',
            'message_id' => '<test@example.com>',
            'to' => ['alice@example.com', 'bob@example.com'],
            'cc' => [],
            'bcc' => [],
            'subject' => 'Hello',
            'saved_to_sent' => false,
        ], $result);

        self::assertCount(1, $sender->sent);
        self::assertSame(['alice@example.com', 'bob@example.com'], $sender->sent[0]->to);
        self::assertSame('Body', $sender->sent[0]->text);
        self::assertSame('', $sender->sent[0]->html);
    }

    public function testSendEmailAcceptsListsOfRecipients(): void
    {
        $sender = new FakeMailSender();
        $service = new MailService(new FakeMailClient(), sender: $sender);

        $result = $service->sendEmail(
            to: ['a@example.com'],
            subject: 'S',
            text: 'B',
            cc: ['c@example.com'],
            bcc: 'd@example.com',
        );

        self::assertSame(['c@example.com'], $result['cc']);
        self::assertSame(['d@example.com'], $result['bcc']);
        self::assertSame(['a@example.com'], $result['to']);
        self::assertSame(['c@example.com'], $sender->sent[0]->cc);
        self::assertSame(['d@example.com'], $sender->sent[0]->bcc);
    }

    public function testSendEmailIsDisabledWithoutATransport(): void
    {
        $service = new MailService(new FakeMailClient());

        self::assertFalse($service->canSend());

        $this->expectException(MailException::class);
        $this->expectExceptionMessage('Sending email is not enabled.');
        $service->sendEmail('a@example.com', 'S', 'B');
    }

    public function testSendEmailRequiresARecipient(): void
    {
        $service = new MailService(new FakeMailClient(), sender: new FakeMailSender());

        $this->expectException(MailException::class);
        $this->expectExceptionMessage('At least one to address is required.');
        $service->sendEmail(null, 'S', 'B');
    }

    public function testSendEmailRequiresASubject(): void
    {
        $service = new MailService(new FakeMailClient(), sender: new FakeMailSender());

        $this->expectException(MailException::class);
        $this->expectExceptionMessage('A subject is required.');
        $service->sendEmail('a@example.com', '   ', 'B');
    }

    public function testSendEmailRequiresABody(): void
    {
        $service = new MailService(new FakeMailClient(), sender: new FakeMailSender());

        $this->expectException(MailException::class);
        $this->expectExceptionMessage('A message body (text or html) is required.');
        $service->sendEmail('a@example.com', 'S', '  ', null);
    }

    public function testSendEmailRejectsAnInvalidAddress(): void
    {
        $service = new MailService(new FakeMailClient(), sender: new FakeMailSender());

        $this->expectException(MailException::class);
        $this->expectExceptionMessage('is not a valid to email address');
        $service->sendEmail('not-an-address', 'S', 'B');
    }

    public function testSendEmailRejectsAnAddressThatSmugglesAHeader(): void
    {
        $service = new MailService(new FakeMailClient(), sender: new FakeMailSender());

        $this->expectException(MailException::class);
        $this->expectExceptionMessage('contains invalid characters');
        $service->sendEmail("a@example.com\r\nBcc: evil@example.com", 'S', 'B');
    }

    public function testSendEmailRejectsASubjectThatSmugglesAHeader(): void
    {
        $service = new MailService(new FakeMailClient(), sender: new FakeMailSender());

        $this->expectException(MailException::class);
        $this->expectExceptionMessage('The subject contains invalid characters.');
        $service->sendEmail('a@example.com', "Hi\r\nBcc: evil@example.com", 'B');
    }

    public function testSendEmailCapsTheNumberOfRecipients(): void
    {
        $service = new MailService(new FakeMailClient(), sender: new FakeMailSender(), maxRecipients: 2);

        $this->expectException(MailException::class);
        $this->expectExceptionMessage('Too many recipients; at most 2 are allowed.');
        $service->sendEmail(['a@example.com', 'b@example.com'], 'S', 'B', cc: ['c@example.com']);
    }

    public function testSendEmailDecodesAttachments(): void
    {
        $sender = new FakeMailSender();
        $service = new MailService(new FakeMailClient(), sender: $sender);

        $service->sendEmail('a@example.com', 'S', 'B', attachments: [
            ['name' => 'report.pdf', 'content_base64' => base64_encode('%PDF-1.4'), 'content_type' => 'application/pdf'],
            ['name' => 'notes.txt', 'content_base64' => base64_encode('hi')],
        ]);

        $attachments = $sender->sent[0]->attachments;

        self::assertCount(2, $attachments);
        self::assertSame('report.pdf', $attachments[0]['name']);
        self::assertSame('%PDF-1.4', $attachments[0]['content']);
        self::assertSame('application/pdf', $attachments[0]['contentType']);
        self::assertSame('', $attachments[1]['contentType'], 'an unknown type is left for the transport to guess');
    }

    public function testSendEmailRejectsInvalidBase64(): void
    {
        $service = new MailService(new FakeMailClient(), sender: new FakeMailSender());

        $this->expectException(MailException::class);
        $this->expectExceptionMessage('is not valid base64');
        $service->sendEmail('a@example.com', 'S', 'B', attachments: [
            ['name' => 'x.pdf', 'content_base64' => 'not base64!!'],
        ]);
    }

    public function testSendEmailRejectsOversizedAttachments(): void
    {
        $service = new MailService(new FakeMailClient(), sender: new FakeMailSender(), maxSendBytes: 8);

        $this->expectException(MailException::class);
        $this->expectExceptionMessage('exceed the 8 B limit');
        $service->sendEmail('a@example.com', 'S', 'B', attachments: [
            ['name' => 'x.bin', 'content_base64' => base64_encode('123456789')],
        ]);
    }

    public function testSendEmailRejectsAnAttachmentWithoutAName(): void
    {
        $service = new MailService(new FakeMailClient(), sender: new FakeMailSender());

        $this->expectException(MailException::class);
        $this->expectExceptionMessage('Every attachment needs a file name.');
        $service->sendEmail('a@example.com', 'S', 'B', attachments: [
            ['content_base64' => base64_encode('x')],
        ]);
    }

    public function testSendEmailNormalizesTheInReplyToMessageId(): void
    {
        $sender = new FakeMailSender();
        $service = new MailService(new FakeMailClient(), sender: $sender);

        $service->sendEmail('a@example.com', 'S', 'B', inReplyTo: '<abc@example.com>');

        self::assertSame('abc@example.com', $sender->sent[0]->inReplyTo);
    }

    public function testSendEmailRejectsAnInvalidInReplyTo(): void
    {
        $service = new MailService(new FakeMailClient(), sender: new FakeMailSender());

        $this->expectException(MailException::class);
        $this->expectExceptionMessage('must be a Message-ID');
        $service->sendEmail('a@example.com', 'S', 'B', inReplyTo: 'nonsense');
    }

    public function testSendEmailValidatesTheReplyToAddress(): void
    {
        $service = new MailService(new FakeMailClient(), sender: new FakeMailSender());

        $this->expectException(MailException::class);
        $this->expectExceptionMessage('is not a valid reply_to email address');
        $service->sendEmail('a@example.com', 'S', 'B', replyTo: 'nope');
    }

    public function testSendEmailAppendsACopyToTheSentFolder(): void
    {
        $client = new FakeMailClient(sentFolder: 'INBOX/Sent');
        $service = new MailService($client, sender: new FakeMailSender(), saveSent: true);

        $result = $service->sendEmail('a@example.com', 'S', 'B');

        self::assertTrue($result['saved_to_sent']);
        self::assertCount(1, $client->appended);
        self::assertSame('INBOX/Sent', $client->appended[0]['folder']);
        self::assertStringContainsString('Subject: S', $client->appended[0]['message']);
        self::assertSame(['\Seen'], $client->appended[0]['flags']);
    }

    public function testSendEmailPrefersTheConfiguredSentFolder(): void
    {
        $client = new FakeMailClient(sentFolder: 'Sent');
        $service = new MailService($client, sender: new FakeMailSender(), saveSent: true, sentMailbox: 'INBOX/Outbox');

        $service->sendEmail('a@example.com', 'S', 'B');

        self::assertSame('INBOX/Outbox', $client->appended[0]['folder']);
    }

    public function testSendEmailReportsWhenTheSentCopyCouldNotBeSaved(): void
    {
        $client = new FakeMailClient(appendError: new MailException('Mailbox not found.'));
        $service = new MailService($client, sender: new FakeMailSender(), saveSent: true);

        $result = $service->sendEmail('a@example.com', 'S', 'B');

        self::assertFalse($result['saved_to_sent'], 'a failed copy must not fail the send');
        self::assertSame('sent', $result['status']);
    }

    public function testSendEmailDoesNotSaveACopyWhenDisabled(): void
    {
        $client = new FakeMailClient();
        $service = new MailService($client, sender: new FakeMailSender());

        $result = $service->sendEmail('a@example.com', 'S', 'B');

        self::assertFalse($result['saved_to_sent']);
        self::assertSame([], $client->appended);
    }

    public function testSendEmailStopsOnceTheRateLimitIsReached(): void
    {
        $sender = new FakeMailSender();
        $limiter = new RateLimiter(new FileStore($this->dataDir().'/rate_limit'), 3600, 1);
        $service = new MailService(new FakeMailClient(), sender: $sender, sendLimiter: $limiter);

        $service->sendEmail('a@example.com', 'First', 'B');

        $this->expectException(MailException::class);
        $this->expectExceptionMessage('Too many emails have been sent in the last 3600 seconds.');

        try {
            $service->sendEmail('a@example.com', 'Second', 'B');
        } finally {
            self::assertCount(1, $sender->sent, 'the blocked message must never reach the transport');
        }
    }

    public function testSendEmailPropagatesTransportFailures(): void
    {
        $service = new MailService(
            new FakeMailClient(),
            sender: new FakeMailSender(sendError: new MailException('The email could not be sent.')),
        );

        $this->expectException(MailException::class);
        $this->expectExceptionMessage('The email could not be sent.');
        $service->sendEmail('a@example.com', 'S', 'B');
    }

    // -----------------------------------------------------------------------
    // Sent-folder detection
    // -----------------------------------------------------------------------

    public function testFindSentFolderPrefersTheConfiguredMailbox(): void
    {
        $client = new FolderListingMailClient([
            ['name' => 'INBOX', 'path' => 'INBOX', 'delimiter' => '/', 'selectable' => true],
        ]);

        self::assertSame('INBOX/Archive', $client->findSentFolder('INBOX/Archive'));
    }

    public function testFindSentFolderMatchesWellKnownNames(): void
    {
        $client = new FolderListingMailClient([
            ['name' => 'INBOX', 'path' => 'INBOX', 'delimiter' => '/', 'selectable' => true],
            ['name' => 'Envoyés', 'path' => '&AOk-nvoy&AOk-s', 'delimiter' => '/', 'selectable' => true],
        ]);

        self::assertSame('&AOk-nvoy&AOk-s', $client->findSentFolder(null));
    }

    public function testFindSentFolderFallsBackToANameContainingSent(): void
    {
        $client = new FolderListingMailClient([
            ['name' => 'Sent Archive', 'path' => 'INBOX/Sent Archive', 'delimiter' => '/', 'selectable' => true],
        ]);

        self::assertSame('INBOX/Sent Archive', $client->findSentFolder(null));
    }

    public function testFindSentFolderReturnsNullWhenThereIsNone(): void
    {
        $client = new FolderListingMailClient([
            ['name' => 'INBOX', 'path' => 'INBOX', 'delimiter' => '/', 'selectable' => true],
        ]);

        self::assertNull($client->findSentFolder(null));
    }
}

final class FolderListingMailClient extends MailClient
{
    /**
     * @param list<array<string, mixed>> $folders
     */
    public function __construct(private readonly array $folders)
    {
        parent::__construct([]);
    }

    public function listFolders(): array
    {
        return $this->folders;
    }
}

final class FakeMailClient extends MailClient
{
    /** @var array<string, mixed> */
    public array $lastSearch = [];

    /** @var array<string, mixed> */
    public array $lastMessage = [];

    /** @var array<string, mixed> */
    public array $lastAttachmentRequest = [];

    /** @var list<array<string, mixed>> */
    public array $appended = [];

    public int $checkCount = 0;

    /**
     * @param list<array<string, mixed>>                              $folders
     * @param list<array<string, mixed>>                              $searchResults
     * @param array<string, mixed>|null                               $message
     * @param array{name: string, type: string, size: int, content: string}|null $attachment
     */
    public function __construct(
        private readonly array $folders = [],
        private readonly array $searchResults = [],
        private readonly ?array $message = null,
        private readonly ?MailException $checkError = null,
        private readonly ?array $attachment = null,
        private readonly ?string $sentFolder = 'Sent',
        private readonly ?MailException $appendError = null,
    ) {
        parent::__construct([]);
    }

    public function check(): void
    {
        ++$this->checkCount;

        if (null !== $this->checkError) {
            throw $this->checkError;
        }
    }

    public function listFolders(): array
    {
        return $this->folders;
    }

    public function searchMessages(string $folderPath, array $criteria, int $limit): array
    {
        $this->lastSearch = ['folder' => $folderPath, 'criteria' => $criteria, 'limit' => $limit];

        return \array_slice($this->searchResults, 0, $limit);
    }

    public function getMessage(string $folderPath, int $uid): ?array
    {
        $this->lastMessage = ['folder' => $folderPath, 'uid' => $uid];

        return $this->message;
    }

    public function getAttachmentContent(string $folderPath, int $uid, ?string $selector = null): ?array
    {
        $this->lastAttachmentRequest = ['folder' => $folderPath, 'uid' => $uid, 'selector' => $selector];

        return $this->attachment;
    }

    public function findSentFolder(?string $preferred = null): ?string
    {
        if (null !== $preferred && '' !== trim($preferred)) {
            return trim($preferred);
        }

        return $this->sentFolder;
    }

    public function appendMessage(string $folderPath, string $rawMessage, array $flags = ['\\Seen']): void
    {
        $this->appended[] = ['folder' => $folderPath, 'message' => $rawMessage, 'flags' => $flags];

        if (null !== $this->appendError) {
            throw $this->appendError;
        }
    }
}

final class FakeMailSender implements MailSender
{
    /** @var list<OutgoingMessage> */
    public array $sent = [];

    public int $checkCount = 0;

    public function __construct(
        private readonly ?MailException $sendError = null,
        private readonly ?MailException $checkError = null,
    ) {
    }

    public function send(OutgoingMessage $message): array
    {
        if (null !== $this->sendError) {
            throw $this->sendError;
        }

        $this->sent[] = $message;

        return [
            'message_id' => '<test@example.com>',
            'raw' => 'Subject: '.$message->subject."\r\n\r\n".$message->text,
        ];
    }

    public function check(): void
    {
        ++$this->checkCount;

        if (null !== $this->checkError) {
            throw $this->checkError;
        }
    }
}

final class FakePdfTextExtractor extends PdfTextExtractor
{
    public string $lastContent = '';

    public int $lastMaxChars = 0;

    /**
     * @param array{text: string, pages_read: int, pages_total: int, truncated: bool} $result
     */
    public function __construct(
        private readonly array $result = ['text' => 'extracted text', 'pages_read' => 1, 'pages_total' => 1, 'truncated' => false],
        private readonly ?MailException $error = null,
    ) {
        parent::__construct();
    }

    public function extract(string $content, int $maxChars): array
    {
        $this->lastContent = $content;
        $this->lastMaxChars = $maxChars;

        if (null !== $this->error) {
            throw $this->error;
        }

        return $this->result;
    }
}
