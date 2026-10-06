<?php

declare(strict_types=1);

namespace App\Tests;

use App\Mail\MailClient;
use App\Mail\MailException;
use App\Mail\MailService;

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
}

final class FakeMailClient extends MailClient
{
    /** @var array<string, mixed> */
    public array $lastSearch = [];

    /**
     * @param list<array<string, mixed>>      $folders
     * @param list<array<string, mixed>>      $searchResults
     * @param array<string, mixed>|null       $message
     */
    public function __construct(
        private readonly array $folders = [],
        private readonly array $searchResults = [],
        private readonly ?array $message = null,
    ) {
        parent::__construct([]);
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
        return $this->message;
    }
}
