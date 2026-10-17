<?php

declare(strict_types=1);

namespace App\Mail;

use Webklex\PHPIMAP\Attachment;
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
 * fetches messages, reads attachments on demand and appends messages, and
 * normalizes the library objects into plain arrays so the rest of the app (and
 * its tests) never touch the vendor API.
 *
 * All reads use IMAP FT_PEEK, so reading a message through the MCP tools never
 * marks it as \Seen on the server. Attachment bodies are only decoded when
 * {@see self::getAttachmentContent()} is called; listing a message reports
 * metadata only.
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
        $message = $this->fetchMessage($folderPath, $uid);

        return null === $message ? null : $this->mapMessage($message, true);
    }

    /**
     * Fetch a single attachment, including its decoded content.
     *
     * `$selector` matches the attachment name (as returned by getMessage) or its
     * 1-based index in the same order. When it is omitted, the only PDF
     * attachment is used, or the only attachment if the message has just one.
     *
     * This is the only place in the client that looks at attachment bodies, so
     * they are only ever decoded when the caller explicitly asks for one.
     *
     * @return array{name: string, type: string, size: int, content: string}|null
     */
    public function getAttachmentContent(string $folderPath, int $uid, ?string $selector = null): ?array
    {
        $message = $this->fetchMessage($folderPath, $uid);
        if (null === $message) {
            return null;
        }

        $attachments = [];
        foreach ($message->getAttachments() as $attachment) {
            if ($attachment instanceof Attachment) {
                $attachments[] = $attachment;
            }
        }

        $attachment = $this->selectAttachment($attachments, $selector);
        if (null === $attachment) {
            return null;
        }

        return [
            'name' => (string) $attachment->name,
            'type' => self::mediaType((string) $attachment->content_type),
            'size' => (int) $attachment->size,
            'content' => (string) $attachment->content,
        ];
    }

    /**
     * Append a raw MIME message to a mailbox. Used to keep a copy of sent mail
     * in the account's Sent folder, which SMTP alone does not do.
     */
    public function appendMessage(string $folderPath, string $rawMessage, array $flags = ['\\Seen']): void
    {
        $this->guard(function () use ($folderPath, $rawMessage, $flags): void {
            $this->folder($folderPath)->appendMessage($rawMessage, $flags);
        });
    }

    /**
     * Resolve the mailbox that holds sent messages: the configured name when one
     * is given, otherwise the first folder that looks like a Sent folder.
     */
    public function findSentFolder(?string $preferred = null): ?string
    {
        if (null !== $preferred && '' !== trim($preferred)) {
            return trim($preferred);
        }

        $exact = [
            'sent', 'sent items', 'sent messages', 'sent mail', 'sent-mail',
            'envoyés', 'envoyes', 'gesendet', 'verzonden', 'posta inviata',
        ];

        $fallback = null;
        foreach ($this->listFolders() as $folder) {
            $name = mb_strtolower(trim($folder['name']));

            if (\in_array($name, $exact, true)) {
                return $folder['path'];
            }

            if (null === $fallback && str_contains($name, 'sent')) {
                $fallback = $folder['path'];
            }
        }

        return $fallback;
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
     * Attachment metadata for a message. Body content is deliberately not read
     * here; see {@see self::getAttachmentContent()}.
     *
     * @return list<array{index: int, name: string, size: int, type: string}>
     */
    private function attachments(Message $message): array
    {
        $result = [];
        $index = 0;

        foreach ($message->getAttachments() as $attachment) {
            ++$index;
            $result[] = [
                'index' => $index,
                'name' => (string) $attachment->name,
                'size' => (int) $attachment->size,
                'type' => self::mediaType((string) $attachment->content_type),
            ];
        }

        return $result;
    }

    /**
     * Fetch a message with its body, or null when it no longer exists.
     */
    private function fetchMessage(string $folderPath, int $uid): ?Message
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

        return $message instanceof Message ? $message : null;
    }

    /**
     * @param list<Attachment> $attachments
     */
    private function selectAttachment(array $attachments, ?string $selector): ?Attachment
    {
        $selector = null !== $selector ? trim($selector) : '';

        if ('' !== $selector) {
            foreach ($attachments as $index => $attachment) {
                if ($selector === (string) ($index + 1) || $selector === (string) $attachment->name) {
                    return $attachment;
                }
            }

            return null;
        }

        $pdfs = array_values(array_filter(
            $attachments,
            static fn (Attachment $attachment): bool => 'application/pdf' === self::mediaType((string) $attachment->content_type),
        ));

        if (1 === \count($pdfs)) {
            return $pdfs[0];
        }

        if (1 === \count($attachments)) {
            return $attachments[0];
        }

        if ([] === $attachments) {
            return null;
        }

        throw new MailException('This email has several attachments; specify which one to read by name or index.');
    }

    /**
     * Strip any parameters from a MIME type ("text/plain; charset=utf-8").
     */
    private static function mediaType(string $contentType): string
    {
        return strtolower(trim(explode(';', $contentType, 2)[0]));
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
