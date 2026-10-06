<?php

declare(strict_types=1);

namespace App\Mail;

use Webklex\PHPIMAP\Attribute;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Config;
use Webklex\PHPIMAP\Exceptions\AuthFailedException;
use Webklex\PHPIMAP\Exceptions\ConnectionFailedException;
use Webklex\PHPIMAP\Exceptions\FolderFetchingException;
use Webklex\PHPIMAP\Exceptions\GetMessagesFailedException;
use Webklex\PHPIMAP\Exceptions\MessageHeaderFetchingException;
use Webklex\PHPIMAP\Exceptions\MessageNotFoundException;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\IMAP;
use Webklex\PHPIMAP\Message;

/**
 * Thin IMAP client built on webklex/php-imap, speaking IMAP directly (no
 * ext-imap). Opens a single connection lazily, lists mailboxes, searches and
 * fetches messages, and normalizes the library objects into plain arrays so
 * the rest of the app (and its tests) never touch the vendor API.
 *
 * All fetches use IMAP FT_PEEK, so reading a message through the MCP tools
 * never marks it as \Seen on the server.
 */
class MailClient
{
    private ?Client $client = null;

    /**
     * @param array<string, mixed> $account webklex account configuration
     */
    public function __construct(private readonly array $account)
    {
    }

    public static function create(
        string $host,
        int $port,
        string $username,
        string $password,
        bool $validateCert = true,
    ): self {
        return new self([
            'host' => $host,
            'port' => $port,
            'protocol' => 'imap',
            'encryption' => 'ssl',
            'validate_cert' => $validateCert,
            'username' => $username,
            'password' => $password,
            'authentication' => null,
        ]);
    }

    /**
     * Verify that the credentials/connection work. Throws MailException on failure.
     */
    public function check(): void
    {
        $this->guard(function (): void {
            $this->connection()->checkConnection();
        });
    }

    /**
     * @return list<array{name: string, path: string, delimiter: string, selectable: bool}>
     */
    public function listFolders(): array
    {
        return $this->guard(function (): array {
            $folders = $this->connection()->getFolders(false, null, true);

            $result = [];
            foreach ($folders as $folder) {
                $result[] = [
                    'name' => (string) $folder->name,
                    'path' => (string) $folder->path,
                    'delimiter' => (string) $folder->delimiter,
                    'selectable' => !$folder->no_select,
                ];
            }

            usort($result, static fn (array $a, array $b): int => strcasecmp($a['path'], $b['path']));

            return $result;
        });
    }

    /**
     * Search messages in a mailbox. Only headers are fetched.
     *
     * @param array{text?: string, from?: string, to?: string, subject?: string, since?: string, before?: string, unseen?: bool} $criteria
     *
     * @return list<array<string, mixed>>
     */
    public function searchMessages(string $folderPath, array $criteria, int $limit): array
    {
        return $this->guard(function () use ($folderPath, $criteria, $limit): array {
            $query = $this->folder($folderPath)->query();

            $used = false;
            foreach (['text' => 'whereText', 'from' => 'whereFrom', 'to' => 'whereTo', 'subject' => 'whereSubject'] as $key => $method) {
                $value = $criteria[$key] ?? null;
                if (\is_string($value) && '' !== $value) {
                    $query->{$method}($value);
                    $used = true;
                }
            }
            if (\is_string($criteria['since'] ?? null) && '' !== $criteria['since']) {
                $query->whereSince($criteria['since']);
                $used = true;
            }
            if (\is_string($criteria['before'] ?? null) && '' !== $criteria['before']) {
                $query->whereBefore($criteria['before']);
                $used = true;
            }
            if (true === ($criteria['unseen'] ?? false)) {
                $query->whereUnseen();
                $used = true;
            }
            if (!$used) {
                $query->whereAll();
            }

            $messages = $query
                ->setFetchBody(false)
                ->setFetchOrderDesc()
                ->leaveUnread()
                ->limit($limit)
                ->get();

            $result = [];
            foreach ($messages as $message) {
                if ($message instanceof Message) {
                    $result[] = $this->mapMessage($message, false);
                }
            }

            return $result;
        });
    }

    /**
     * Fetch a single message (including body) by UID. Returns null when it does
     * not exist.
     *
     * @return array<string, mixed>|null
     */
    public function getMessage(string $folderPath, int $uid): ?array
    {
        try {
            $message = $this->folder($folderPath)->query()
                ->setFetchBody(true)
                ->setFetchOrderAsc()
                ->leaveUnread()
                ->getMessageByUid($uid);
        } catch (MessageNotFoundException|MessageHeaderFetchingException) {
            return null;
        } catch (\Throwable $e) {
            throw $this->translate($e);
        }

        if (!$message instanceof Message) {
            return null;
        }

        return $this->mapMessage($message, true);
    }

    protected function connection(): Client
    {
        if (null === $this->client) {
            try {
                $client = new Client(Config::make([
                    'accounts' => ['default' => $this->account],
                    'options' => [
                        'fetch' => IMAP::FT_PEEK,
                        'sequence' => IMAP::ST_UID,
                        'fetch_body' => false,
                        'fetch_flags' => true,
                        'soft_fail' => true,
                        'message_key' => 'list',
                        'fetch_order' => 'desc',
                    ],
                    'security' => [
                        'detect_spoofing' => false,
                    ],
                ]));
                $client->connect();
            } catch (\Throwable $e) {
                throw $this->translate($e);
            }

            $this->client = $client;
        }

        return $this->client;
    }

    private function folder(string $path): Folder
    {
        $client = $this->connection();

        $folder = $client->getFolderByPath($path, false, true)
            ?? $client->getFolderByName($path, true);

        if (null === $folder) {
            throw new MailException('Mailbox not found.');
        }

        return $folder;
    }

    /**
     * @return array<string, mixed>
     */
    private function mapMessage(Message $message, bool $withBody): array
    {
        $flags = $message->getFlags();

        $data = [
            'uid' => (int) $message->get('uid'),
            'subject' => $this->attribute($message->get('subject')),
            'from' => $this->attribute($message->get('from')),
            'to' => $this->attribute($message->get('to')),
            'date' => $this->date($message->get('date')),
            'seen' => $flags->has('seen'),
            'flagged' => $flags->has('flagged'),
            'has_attachments' => $message->hasAttachments(),
        ];

        if ($withBody) {
            $data['cc'] = $this->attribute($message->get('cc'));
            $data['message_id'] = $this->attribute($message->get('message_id'));
            $data['in_reply_to'] = $this->attribute($message->get('in_reply_to'));
            $data['text'] = $message->hasTextBody() ? $message->getTextBody() : '';
            $data['html'] = $message->hasHTMLBody() ? $message->getHTMLBody() : null;
            $data['attachments'] = $this->attachments($message);
        }

        return $data;
    }

    /**
     * @return list<array{name: string, size: int, type: string}>
     */
    private function attachments(Message $message): array
    {
        $result = [];
        foreach ($message->getAttachments() as $attachment) {
            $result[] = [
                'name' => (string) $attachment->name,
                'size' => (int) $attachment->size,
                'type' => (string) $attachment->content_type,
            ];
        }

        return $result;
    }

    private function attribute(mixed $value): string
    {
        if ($value instanceof Attribute) {
            return $value->toString();
        }
        if (\is_scalar($value)) {
            return (string) $value;
        }

        return '';
    }

    private function date(mixed $value): ?string
    {
        if (!$value instanceof Attribute) {
            return null;
        }

        try {
            return $value->toDate()->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }

    private function guard(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            throw $this->translate($e);
        }
    }

    private function translate(\Throwable $e): MailException
    {
        if ($e instanceof MailException) {
            return $e;
        }

        return match (true) {
            $e instanceof AuthFailedException => new MailException('Mail authentication failed. Check INFOMANIAK_MAIL_ADDRESS and INFOMANIAK_MAIL_PASSWORD.'),
            $e instanceof ConnectionFailedException => new MailException('Mail server unavailable.'),
            $e instanceof FolderFetchingException => new MailException('Mailbox not found.'),
            $e instanceof MessageNotFoundException,
            $e instanceof MessageHeaderFetchingException => new MailException('Email not found.'),
            $e instanceof GetMessagesFailedException => new MailException('Mail search failed.'),
            default => new MailException('Mail operation failed.'),
        };
    }
}
