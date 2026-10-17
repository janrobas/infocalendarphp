<?php

declare(strict_types=1);

namespace App\Mail;

use App\Storage\RateLimiter;
use App\Support\Bytes;
use DateTimeImmutable;

/**
 * High-level mail operations exposed to the MCP tools. Input is validated and
 * normalized here; all IMAP and SMTP work happens in {@see MailClient} and
 * {@see MailSender}.
 *
 * Message bodies are converted to text and truncated before they are returned,
 * and attachments are only parsed when the caller explicitly asks for one: the
 * message path never touches a PDF.
 */
final class MailService
{
    public const DEFAULT_MAILBOX = 'INBOX';

    private const SEND_RATE_KEY = 'outgoing-mail';

    public function __construct(
        private readonly MailClient $client,
        private readonly int $maxResults = 25,
        private readonly int $maxBodyChars = 20000,
        private readonly ?PdfTextExtractor $pdf = null,
        private readonly ?MailSender $sender = null,
        private readonly ?RateLimiter $sendLimiter = null,
        private readonly bool $saveSent = false,
        private readonly ?string $sentMailbox = null,
        private readonly int $maxRecipients = 10,
        private readonly int $maxSendBytes = 10485760,
    ) {
    }

    /**
     * Verify that the mailbox is reachable and the credentials work. Used by the
     * /health/mail endpoint; throws {@see MailException} when it is not.
     */
    public function check(): void
    {
        $this->client->check();
    }

    /**
     * Verify that the outgoing server is reachable and accepts the credentials.
     * Throws {@see MailException} when sending is disabled or broken.
     */
    public function checkSender(): void
    {
        if (null === $this->sender) {
            throw new MailException('Sending email is not enabled.');
        }

        $this->sender->check();
    }

    /**
     * Whether outgoing mail is configured. When false, the send tool is not
     * registered at all.
     */
    public function canSend(): bool
    {
        return null !== $this->sender;
    }

    /**
     * Whether PDF attachments can be read. When false, the read tool is not
     * registered at all.
     */
    public function canReadAttachments(): bool
    {
        return null !== $this->pdf;
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
     * are reported by name, type, size and index only; their content is never
     * read, and no PDF is ever parsed. Use readAttachment() to read a PDF.
     *
     * @return array{email: array<string, mixed>}
     */
    public function getEmail(string $uid, ?string $mailbox, ?int $maxChars): array
    {
        $message = $this->client->getMessage($this->mailbox($mailbox), $this->uid($uid));
        if (null === $message) {
            throw new MailException('Email not found.');
        }

        $limit = $this->bodyLimit($maxChars);

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
     * Read the text of a PDF attachment.
     *
     * This is the only operation in the app that parses an attachment, and
     * nothing calls it implicitly: the contents of a PDF are extracted when, and
     * only when, the model asks for that specific attachment.
     *
     * `$attachment` is the attachment name or its 1-based index as returned by
     * getEmail. When omitted, the message's only PDF (or its only attachment) is
     * used.
     *
     * @return array{attachment: array<string, mixed>}
     */
    public function readAttachment(string $uid, ?string $attachment, ?string $mailbox, ?int $maxChars): array
    {
        if (null === $this->pdf) {
            throw new MailException('Reading attachments is not available.');
        }

        $stored = $this->client->getAttachmentContent($this->mailbox($mailbox), $this->uid($uid), $attachment);
        if (null === $stored) {
            throw new MailException('Attachment not found.');
        }

        if ('application/pdf' !== $stored['type']) {
            throw new MailException(sprintf(
                'Only PDF attachments can be read; "%s" is %s.',
                $stored['name'],
                '' === $stored['type'] ? 'of an unknown type' : $stored['type'],
            ));
        }

        $extracted = $this->pdf->extract($stored['content'], $this->bodyLimit($maxChars));

        return ['attachment' => [
            'name' => $stored['name'],
            'type' => $stored['type'],
            'size' => $stored['size'],
            'text' => $extracted['text'],
            'pages_read' => $extracted['pages_read'],
            'pages_total' => $extracted['pages_total'],
            'truncated' => $extracted['truncated'],
        ]];
    }

    /**
     * Send an email.
     *
     * Every address is validated, so the tool can never be used to inject
     * headers: addresses are plain addresses (no display names), and the
     * subject and attachment names are rejected if they contain control
     * characters. A copy is appended to the account's Sent mailbox when that is
     * enabled, because SMTP alone does not record sent messages.
     *
     * @param string|list<string>|null                                  $to
     * @param string|list<string>|null                                  $cc
     * @param string|list<string>|null                                  $bcc
     * @param list<array{name?: string, content_base64?: string, content_type?: string}> $attachments
     *
     * @return array<string, mixed>
     */
    public function sendEmail(
        array|string|null $to,
        ?string $subject = null,
        ?string $text = null,
        ?string $html = null,
        array|string|null $cc = null,
        array|string|null $bcc = null,
        ?string $replyTo = null,
        ?string $inReplyTo = null,
        array $attachments = [],
    ): array {
        if (null === $this->sender) {
            throw new MailException('Sending email is not enabled.');
        }

        if (null !== $this->sendLimiter && $this->sendLimiter->tooManyAttempts(self::SEND_RATE_KEY)) {
            throw new MailException(sprintf(
                'Too many emails have been sent in the last %d seconds. Please try again later.',
                $this->sendLimiter->window(),
            ));
        }

        $tos = $this->addresses($to, 'to', true);
        $ccs = $this->addresses($cc, 'cc');
        $bccs = $this->addresses($bcc, 'bcc');

        if (\count($tos) + \count($ccs) + \count($bccs) > $this->maxRecipients) {
            throw new MailException(sprintf('Too many recipients; at most %d are allowed.', $this->maxRecipients));
        }

        $subject = trim((string) $subject);
        if ('' === $subject) {
            throw new MailException('A subject is required.');
        }
        if (self::hasControlChars($subject)) {
            throw new MailException('The subject contains invalid characters.');
        }
        if (\strlen($subject) > 998) {
            throw new MailException('The subject is too long.');
        }

        $text = (string) $text;
        $html = (string) $html;
        if ('' === trim($text) && '' === trim($html)) {
            throw new MailException('A message body (text or html) is required.');
        }

        if (null !== $replyTo && '' !== trim($replyTo)) {
            $replyTo = $this->addresses($replyTo, 'reply_to', true)[0];
        } else {
            $replyTo = null;
        }

        $inReplyTo = $this->messageId($inReplyTo);
        $attachments = $this->outgoingAttachments($attachments);

        $message = new OutgoingMessage(
            to: $tos,
            subject: $subject,
            text: $text,
            html: $html,
            cc: $ccs,
            bcc: $bccs,
            replyTo: $replyTo,
            inReplyTo: $inReplyTo,
            attachments: $attachments,
        );

        $this->sendLimiter?->record(self::SEND_RATE_KEY);

        $result = $this->sender->send($message);

        return [
            'status' => 'sent',
            'message_id' => $result['message_id'],
            'to' => $tos,
            'cc' => $ccs,
            'bcc' => $bccs,
            'subject' => $subject,
            'saved_to_sent' => $this->saveSent ? $this->appendToSent($result['raw']) : false,
        ];
    }

    /**
     * Best-effort copy of a sent message into the account's Sent folder. The
     * message has already left by the time this runs, so a failure here is
     * reported (as `saved_to_sent: false`) rather than thrown.
     */
    private function appendToSent(string $raw): bool
    {
        try {
            $folder = $this->client->findSentFolder($this->sentMailbox);
            if (null === $folder) {
                return false;
            }

            $this->client->appendMessage($folder, $raw);

            return true;
        } catch (\Throwable) {
            return false;
        }
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

    private function uid(string $uid): int
    {
        $value = filter_var($uid, \FILTER_VALIDATE_INT);
        if (false === $value || $value < 1) {
            throw new MailException('Invalid email uid.');
        }

        return $value;
    }

    private function bodyLimit(?int $maxChars): int
    {
        return (null !== $maxChars && $maxChars > 0) ? min($maxChars, $this->maxBodyChars) : $this->maxBodyChars;
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

    /**
     * Accept a single address or a list, split on commas/semicolons, validate
     * each one and reject anything that could smuggle a header.
     *
     * @return list<string>
     */
    private function addresses(mixed $value, string $label, bool $required = false): array
    {
        if (null === $value || '' === $value || [] === $value) {
            if ($required) {
                throw new MailException(sprintf('At least one %s address is required.', $label));
            }

            return [];
        }

        if (\is_string($value)) {
            $value = preg_split('/[;,]/', $value) ?: [];
        }

        if (!\is_array($value)) {
            throw new MailException(sprintf('The %s addresses must be a string or a list of strings.', $label));
        }

        $addresses = [];
        foreach ($value as $address) {
            if (!\is_string($address)) {
                throw new MailException(sprintf('Every %s address must be a string.', $label));
            }

            $address = trim($address);
            if ('' === $address) {
                continue;
            }

            if (self::hasControlChars($address)) {
                throw new MailException(sprintf('A %s address contains invalid characters.', $label));
            }

            if (false === filter_var($address, \FILTER_VALIDATE_EMAIL)) {
                throw new MailException(sprintf('"%s" is not a valid %s email address.', $address, $label));
            }

            $addresses[] = $address;
        }

        if ([] === $addresses && $required) {
            throw new MailException(sprintf('At least one %s address is required.', $label));
        }

        return array_values(array_unique($addresses));
    }

    /**
     * Validate and decode the attachments of an outgoing message. Their combined
     * size is capped so a runaway request cannot exhaust memory.
     *
     * @param array<mixed> $attachments
     *
     * @return list<array{name: string, content: string, contentType: string}>
     */
    private function outgoingAttachments(array $attachments): array
    {
        $result = [];
        $total = 0;

        foreach ($attachments as $attachment) {
            if (!\is_array($attachment)) {
                throw new MailException('Every attachment must be an object with a name and content_base64.');
            }

            $name = \is_string($attachment['name'] ?? null) ? trim($attachment['name']) : '';
            if ('' === $name) {
                throw new MailException('Every attachment needs a file name.');
            }
            if (self::hasControlChars($name) || \strlen($name) > 255) {
                throw new MailException('An attachment name contains invalid characters or is too long.');
            }

            $encoded = \is_string($attachment['content_base64'] ?? null) ? $attachment['content_base64'] : '';
            if ('' === $encoded) {
                throw new MailException(sprintf('The attachment "%s" has no content_base64.', $name));
            }

            $content = base64_decode(preg_replace('/\s+/', '', $encoded) ?? $encoded, true);
            if (false === $content) {
                throw new MailException(sprintf('The attachment "%s" is not valid base64.', $name));
            }
            if ('' === $content) {
                throw new MailException(sprintf('The attachment "%s" has no content.', $name));
            }

            $total += \strlen($content);
            if ($total > $this->maxSendBytes) {
                throw new MailException(sprintf(
                    'The attachments exceed the %s limit for a single message.',
                    Bytes::format($this->maxSendBytes),
                ));
            }

            $contentType = \is_string($attachment['content_type'] ?? null) ? trim($attachment['content_type']) : '';
            if ('' !== $contentType && 1 !== preg_match('#^[\w.+-]+/[\w.+-]+$#', $contentType)) {
                throw new MailException(sprintf('The attachment "%s" has an invalid content_type.', $name));
            }

            $result[] = ['name' => $name, 'content' => $content, 'contentType' => $contentType];
        }

        return $result;
    }

    /**
     * Normalize an optional Message-ID used for threading ("<id@host>").
     */
    private function messageId(?string $value): ?string
    {
        if (null === $value || '' === trim($value)) {
            return null;
        }

        $id = trim(trim($value), '<>');

        if ('' === $id || self::hasControlChars($id) || !str_contains($id, '@') || str_contains($id, ' ')) {
            throw new MailException('The in_reply_to value must be a Message-ID, such as <abc@example.com>.');
        }

        return $id;
    }

    private static function hasControlChars(string $value): bool
    {
        return 1 === preg_match('/[\x00-\x1F\x7F]/', $value);
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
