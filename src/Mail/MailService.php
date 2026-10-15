<?php

declare(strict_types=1);

namespace App\Mail;

use DateTimeImmutable;

/**
 * High-level mail operations exposed to the MCP tools. Input is validated and
 * normalized here; all IMAP work happens in {@see MailClient}. Message bodies
 * are converted to text and truncated before they are ever returned.
 */
final class MailService
{
    public const DEFAULT_MAILBOX = 'INBOX';

    public function __construct(
        private readonly MailClient $client,
        private readonly int $maxResults = 25,
        private readonly int $maxBodyChars = 20000,
    ) {
    }

    /**
     * @return array{mailboxes: list<array<string, mixed>>}
     */
    public function listMailboxes(): array
    {
        return ['mailboxes' => $this->client->listFolders()];
    }

    /**
     * List the most recent messages in a mailbox.
     *
     * @return array{messages: list<array<string, mixed>>, count: int, mailbox: string}
     */
    public function listEmails(
        ?string $mailbox,
        ?string $start,
        ?string $end,
        ?int $limit,
        bool $unreadOnly,
    ): array {
        return $this->search($mailbox, [], $start, $end, $limit, $unreadOnly);
    }

    /**
     * Search messages. At least one of query/from/subject must be provided.
     *
     * @return array{messages: list<array<string, mixed>>, count: int, mailbox: string}
     */
    public function searchEmails(
        string $query,
        ?string $mailbox,
        ?string $start,
        ?string $end,
        ?string $from,
        ?string $subject,
        ?int $limit,
        bool $unreadOnly,
    ): array {
        $criteria = [];
        if ('' !== trim($query)) {
            $criteria['text'] = trim($query);
        }
        if (null !== $from && '' !== trim($from)) {
            $criteria['from'] = trim($from);
        }
        if (null !== $subject && '' !== trim($subject)) {
            $criteria['subject'] = trim($subject);
        }

        if ([] === $criteria) {
            return ['messages' => [], 'count' => 0, 'mailbox' => $this->mailbox($mailbox)];
        }

        return $this->search($mailbox, $criteria, $start, $end, $limit, $unreadOnly);
    }

    /**
     * Fetch a single email, including a text version of its body. Attachments
     * are reported by name only; their content is never downloaded.
     *
     * @return array{email: array<string, mixed>}
     */
    public function getEmail(string $uid, ?string $mailbox, ?int $maxChars): array
    {
        $uidInt = filter_var($uid, \FILTER_VALIDATE_INT);
        if (false === $uidInt || $uidInt < 1) {
            throw new MailException('Invalid email uid.');
        }

        $message = $this->client->getMessage($this->mailbox($mailbox), $uidInt);
        if (null === $message) {
            throw new MailException('Email not found.');
        }

        $limit = (null !== $maxChars && $maxChars > 0) ? min($maxChars, $this->maxBodyChars) : $this->maxBodyChars;

        $text = (string) ($message['text'] ?? '');
        if ('' === trim($text) && \is_string($message['html'] ?? null)) {
            $text = $this->htmlToText($message['html']);
        }

        $truncated = mb_strlen($text) > $limit;
        if ($truncated) {
            $text = mb_substr($text, 0, $limit).'…';
        }

        unset($message['html']);
        $message['body'] = $text;
        $message['truncated'] = $truncated;

        return ['email' => $message];
    }

    /**
     * @param array{text?: string, from?: string, subject?: string} $criteria
     *
     * @return array{messages: list<array<string, mixed>>, count: int, mailbox: string}
     */
    private function search(
        ?string $mailbox,
        array $criteria,
        ?string $start,
        ?string $end,
        ?int $limit,
        bool $unreadOnly,
    ): array {
        $path = $this->mailbox($mailbox);

        $since = $this->parseDate($start, 'start');
        $before = $this->parseDate($end, 'end');
        if (null !== $since && null !== $before && $since > $before) {
            throw new MailException('Invalid date range: start must be before end.');
        }

        if (null !== $since) {
            $criteria['since'] = $since->format('Y-m-d');
        }
        if (null !== $before) {
            $criteria['before'] = $before->format('Y-m-d');
        }
        if ($unreadOnly) {
            $criteria['unseen'] = true;
        }

        $messages = $this->client->searchMessages($path, $criteria, $this->normalizeLimit($limit));

        if ($unreadOnly) {
            $messages = array_values(array_filter($messages, static fn (array $m): bool => false === ($m['seen'] ?? false)));
        }

        return ['messages' => $messages, 'count' => \count($messages), 'mailbox' => $path];
    }

    /**
     * Normalize a mailbox argument into the readable UTF-8 name the IMAP client
     * expects. Accepts both the readable name (`ŠCKR TODO`) and the wire form
     * used by IMAP itself (`&AWA-CKR TODO`), so a `path` taken straight from
     * list_mailboxes works as-is.
     */
    private function mailbox(?string $mailbox): string
    {
        $mailbox = null !== $mailbox ? trim($mailbox) : '';

        return '' === $mailbox ? self::DEFAULT_MAILBOX : ModifiedUtf7::decode($mailbox);
    }

    private function normalizeLimit(?int $limit): int
    {
        if (null === $limit || $limit < 1) {
            return $this->maxResults;
        }

        return min($limit, $this->maxResults);
    }

    private function parseDate(?string $value, string $label): ?DateTimeImmutable
    {
        if (null === $value || '' === trim($value)) {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Exception) {
            throw new MailException(sprintf('Invalid %s date: dates must be ISO 8601.', $label));
        }
    }

    private function htmlToText(string $html): string
    {
        $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
        $html = preg_replace('#<(br|/p|/div|/tr|/li|/h[1-6])\b[^>]*>#i', "\n", $html) ?? $html;

        $text = html_entity_decode(strip_tags($html), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t]+\n/", "\n", $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }
}
