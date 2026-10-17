<?php

declare(strict_types=1);

namespace App\Tests;

use App\Calendar\CalDavClient;
use App\Calendar\CalendarService;
use App\Mail\MailClient;
use App\Mail\MailSender;
use App\Mail\MailService;
use App\Mail\OutgoingMessage;
use App\Mail\PdfTextExtractor;
use App\Mcp\McpServer;
use Http\Discovery\Psr17Factory;
use Mcp\Server;
use Mcp\Server\Transport\StreamableHttpTransport;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;

/**
 * Drives the built MCP server over its Streamable HTTP transport, so the tool
 * schemas and their registration are exercised the way a client sees them.
 */
final class McpServerTest extends TestCase
{
    private Server $server;

    /**
     * The sender handed to the mail service; `sent` records what was sent.
     */
    private object $sender;

    public function testItListsTheCalendarAndMailTools(): void
    {
        $names = $this->toolNames();

        foreach ([
            'list_calendars', 'get_events', 'search_events', 'find_free_slots',
            'create_event', 'update_event', 'delete_event',
            'list_mailboxes', 'list_emails', 'search_emails', 'get_email',
            'read_attachment', 'send_email',
        ] as $name) {
            self::assertContains($name, $names);
        }
    }

    public function testSendEmailIsNotRegisteredWhenSendingIsDisabled(): void
    {
        $names = $this->toolNames(sending: false);

        self::assertContains('read_attachment', $names);
        self::assertNotContains('send_email', $names);
    }

    public function testItSendsAnEmailThroughTheTool(): void
    {
        $result = $this->callTool('send_email', [
            'to' => ['alice@example.com', 'bob@example.com'],
            'subject' => 'Design review',
            'text' => 'See you at 2pm.',
            'cc' => 'carol@example.com',
        ]);

        self::assertArrayNotHasKey('error', $result, json_encode($result));
        self::assertFalse($result['result']['isError'] ?? false, $this->text($result));
        self::assertSame('sent', $result['result']['structuredContent']['status']);

        $sent = $this->sender->sent;

        self::assertCount(1, $sent);
        self::assertSame(['alice@example.com', 'bob@example.com'], $sent[0]->to);
        self::assertSame(['carol@example.com'], $sent[0]->cc);
        self::assertSame('Design review', $sent[0]->subject);
        self::assertSame('See you at 2pm.', $sent[0]->text);
    }

    public function testItReadsAPdfAttachmentThroughTheTool(): void
    {
        $result = $this->callTool('read_attachment', ['uid' => '42', 'attachment' => 'invoice.pdf']);

        self::assertFalse($result['result']['isError'] ?? false, $this->text($result));

        $attachment = $result['result']['structuredContent']['attachment'];

        self::assertSame('invoice.pdf', $attachment['name']);
        self::assertSame('application/pdf', $attachment['type']);
        self::assertSame('Total: 42 EUR', $attachment['text']);
        self::assertSame(1, $attachment['pages_total']);
    }

    public function testDomainErrorsComeBackAsToolErrors(): void
    {
        $result = $this->callTool('send_email', ['to' => 'nope', 'subject' => 'Hi', 'text' => 'Body']);

        self::assertTrue($result['result']['isError'] ?? false);
        self::assertStringContainsString('not a valid to email address', $this->text($result));
    }

    public function testInvalidArgumentsAreRejectedBeforeTheToolRuns(): void
    {
        $result = $this->callTool('read_attachment', []);

        self::assertArrayHasKey('error', $result);
        self::assertStringContainsString('uid', (string) json_encode($result['error']));
    }

    public function testAttachmentsArePassedThroughToTheTransport(): void
    {
        $result = $this->callTool('send_email', [
            'to' => 'alice@example.com',
            'subject' => 'Invoice',
            'text' => 'Attached.',
            'attachments' => [
                ['name' => 'invoice.pdf', 'content_base64' => base64_encode(PdfFixture::create('Total: 42 EUR'))],
            ],
        ]);

        self::assertFalse($result['result']['isError'] ?? false, $this->text($result));

        $attachments = $this->sender->sent[0]->attachments;

        self::assertCount(1, $attachments);
        self::assertSame('invoice.pdf', $attachments[0]['name']);
        self::assertStringStartsWith('%PDF-1.4', $attachments[0]['content']);
    }

    /**
     * list_emails and search_emails report the uid as a number, so a client
     * that hands that value straight back must not be turned away by the input
     * schema of the tools that take a uid.
     */
    public function testEmailUidIsAcceptedAsANumberAndAsAString(): void
    {
        $tools = $this->toolSchemas();

        foreach (['get_email', 'read_attachment'] as $name) {
            $types = $tools[$name]['inputSchema']['properties']['uid']['type'] ?? null;

            self::assertIsArray($types, "The uid of {$name} should accept more than one type.");
            self::assertContains('string', $types, $name);
            self::assertContains('integer', $types, $name);
        }
    }

    public function testItReadsAnEmailWithANumericUid(): void
    {
        $result = $this->callTool('get_email', ['uid' => 20988]);

        self::assertArrayNotHasKey('error', $result, json_encode($result));
        self::assertFalse($result['result']['isError'] ?? false, $this->text($result));

        $email = $result['result']['structuredContent']['email'];

        self::assertSame(20988, $email['uid']);
        self::assertSame('Order received.', $email['body']);
    }

    public function testItReadsAnAttachmentWithANumericUid(): void
    {
        $result = $this->callTool('read_attachment', ['uid' => 42, 'attachment' => 'invoice.pdf']);

        self::assertArrayNotHasKey('error', $result, json_encode($result));
        self::assertFalse($result['result']['isError'] ?? false, $this->text($result));
        self::assertSame('Total: 42 EUR', $result['result']['structuredContent']['attachment']['text']);
    }

    /**
     * @return list<string>
     */
    private function toolNames(bool $sending = true): array
    {
        $decoded = $this->post(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], $sending);

        return array_column($decoded['result']['tools'] ?? [], 'name');
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function toolSchemas(bool $sending = true): array
    {
        $decoded = $this->post(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], $sending);

        $schemas = [];
        foreach ($decoded['result']['tools'] ?? [] as $tool) {
            $schemas[$tool['name']] = $tool;
        }

        return $schemas;
    }


    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function callTool(string $name, array $arguments): array
    {
        return $this->post([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => $name, 'arguments' => $arguments],
        ]);
    }

    /**
     * @param array<string, mixed> $result
     */
    private function text(array $result): string
    {
        return (string) ($result['result']['content'][0]['text'] ?? '');
    }

    /**
     * Build a server, initialize a session against it and send one request.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function post(array $payload, bool $sending = true): array
    {
        $this->server = $this->buildServer($sending);

        $factory = new Psr17Factory();

        $initialize = $factory->createServerRequest('POST', 'https://localhost/mcp')
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Accept', 'application/json, text/event-stream')
            ->withBody($factory->createStream((string) json_encode([
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'initialize',
                'params' => [
                    'protocolVersion' => '2025-06-18',
                    'capabilities' => new \stdClass(),
                    'clientInfo' => ['name' => 'phpunit', 'version' => '1'],
                ],
            ])));

        $session = $this->dispatch(new StreamableHttpTransport($initialize, logger: new NullLogger()))
            ->getHeaderLine('Mcp-Session-Id');

        $request = $factory->createServerRequest('POST', 'https://localhost/mcp')
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Accept', 'application/json, text/event-stream')
            ->withHeader('Mcp-Session-Id', $session)
            ->withBody($factory->createStream((string) json_encode($payload)));

        $body = (string) $this->dispatch(new StreamableHttpTransport($request, logger: new NullLogger()))->getBody();

        // Responses may arrive as a single SSE event.
        if (str_contains($body, 'data:')) {
            $body = trim(substr($body, strpos($body, 'data:') + 5));
        }

        return json_decode($body, true) ?? [];
    }

    private function dispatch(StreamableHttpTransport $transport): ResponseInterface
    {
        $response = $this->server->run($transport);

        self::assertInstanceOf(ResponseInterface::class, $response);

        return $response;
    }

    private function buildServer(bool $sending): Server
    {
        $client = new class extends MailClient {
            public function __construct()
            {
                parent::__construct([]);
            }

            public function getAttachmentContent(string $folderPath, int $uid, ?string $selector = null): ?array
            {
                return [
                    'name' => null === $selector ? 'invoice.pdf' : $selector,
                    'type' => 'application/pdf',
                    'size' => 128,
                    'content' => PdfFixture::create('Total: 42 EUR'),
                ];
            }

            public function getMessage(string $folderPath, int $uid): ?array
            {
                return [
                    'uid' => $uid,
                    'subject' => 'Your order',
                    'from' => 'shop@example.com',
                    'to' => 'me@example.com',
                    'date' => '2026-10-17T11:23:34+02:00',
                    'seen' => true,
                    'flagged' => false,
                    'has_attachments' => false,
                    'cc' => '',
                    'message_id' => '<order@example.com>',
                    'in_reply_to' => '',
                    'text' => 'Order received.',
                    'html' => null,
                    'attachments' => [],
                ];
            }

            public function findSentFolder(?string $preferred = null): ?string
            {
                return 'Sent';
            }

            public function appendMessage(string $folderPath, string $rawMessage, array $flags = ['\\Seen']): void
            {
            }
        };

        $sender = new class implements MailSender {
            /** @var list<OutgoingMessage> */
            public array $sent = [];

            public function send(OutgoingMessage $message): array
            {
                $this->sent[] = $message;

                return ['message_id' => '<unit@example.com>', 'raw' => 'Subject: '.$message->subject];
            }

            public function check(): void
            {
            }
        };

        $this->sender = $sender;

        $mail = new MailService(
            client: $client,
            pdf: new PdfTextExtractor(),
            sender: $sending ? $sender : null,
        );

        $calendar = new CalendarService(CalDavClient::create('https://sync.infomaniak.com/', 'user', 'password'));

        return McpServer::build($calendar, $mail, $this->tempDir.'/mcp-sessions');
    }
}
