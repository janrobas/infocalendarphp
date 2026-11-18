<?php

declare(strict_types=1);

namespace App\Mail;

use App\Config;

/**
 * App-level access policy for the mail tools.
 *
 * Mail credentials always belong to one real mailbox. When that mailbox also
 * receives mail for aliases, this policy narrows what the MCP tools may see and
 * do, so the model can only work with a specific address instead of the whole
 * inbox:
 *
 *  - `alias` limits incoming mail to messages addressed to that address (To,
 *    Cc or the Delivered-To header, which usually records a Bcc delivery), and
 *    limits the default/mailbox set to INBOX. Messages sent as the alias are
 *    matched by their From header in the sent folder.
 *  - `mailboxes` limits every operation to a list of folders (for example an
 *    inbox plus the sent folder, or a folder that an Infomaniak rule moves
 *    alias mail into).
 *  - `allowedSenders` hides messages from anyone not on the list. An empty list
 *    allows every sender.
 *  - `allowedRecipients` refuses to send to any address not on the list. An
 *    empty list allows every recipient.
 *
 * This is not a credential boundary: the server still holds the account's
 * password. It is enforced in {@see MailService} on every tool path so the
 * model never sees mail outside the policy.
 */
final class MailScope
{
    /**
     * Folder names that mean "sent mail" across the usual IMAP localisations.
     *
     * @var list<string>
     */
    private const SENT_NAMES = [
        'sent', 'sent items', 'sent messages', 'sent mail', 'sent-mail',
        'envoyés', 'envoyes', 'gesendet', 'verzonden', 'posta inviata',
        'poslano', 'poslana pošta', 'poslana posta',
    ];

    /**
     * @param list<string> $mailboxes         folder names or paths; empty means no folder restriction
     * @param list<string> $allowedSenders    lower-case addresses; empty means all
     * @param list<string> $allowedRecipients lower-case addresses; empty means all
     * @param string|null  $sendAs            the address used in From, when it differs from the alias
     */
    public function __construct(
        private readonly ?string $alias = null,
        private readonly array $mailboxes = [],
        private readonly array $allowedSenders = [],
        private readonly array $allowedRecipients = [],
        private readonly ?string $sendAs = null,
    ) {
    }

    /**
     * Build the policy from environment configuration. Returns null when no
     * policy is configured, which keeps the mail tools working on the full
     * mailbox (the default before this feature existed).
     *
     * Throws {@see MailException} for a malformed policy; the caller disables
     * the mail tools in that case rather than exposing more than intended.
     */
    public static function fromConfig(Config $config): ?self
    {
        $alias = self::clean($config->get('INFOMANIAK_MAIL_ALIAS'));
        $mailboxes = self::parseMailboxes($config->get('INFOMANIAK_MAIL_SCOPE_MAILBOXES'));
        $legacy = self::clean($config->get('INFOMANIAK_MAIL_SCOPE_MAILBOX'));
        if (null !== $legacy) {
            $mailboxes = self::mergeMailboxes($mailboxes, [$legacy]);
        }

        $senders = self::parseAddresses($config->get('INFOMANIAK_MAIL_ALLOWED_SENDERS'), 'INFOMANIAK_MAIL_ALLOWED_SENDERS');
        $recipients = self::parseAddresses($config->get('INFOMANIAK_MAIL_ALLOWED_RECIPIENTS'), 'INFOMANIAK_MAIL_ALLOWED_RECIPIENTS');

        // Only used to recognise the user's own sent mail; an invalid value is
        // ignored here because the mail factory validates it before sending.
        $sendAs = self::clean($config->get('INFOMANIAK_MAIL_FROM'));
        if (null !== $sendAs && false === filter_var($sendAs, \FILTER_VALIDATE_EMAIL)) {
            $sendAs = null;
        }

        if (null === $alias && [] === $mailboxes && [] === $senders && [] === $recipients) {
            return null;
        }

        if (null !== $alias && false === filter_var($alias, \FILTER_VALIDATE_EMAIL)) {
            throw new MailException(sprintf('INFOMANIAK_MAIL_ALIAS ("%s") is not a valid email address.', $alias));
        }

        return new self($alias, $mailboxes, $senders, $recipients, $sendAs);
    }

    public function alias(): ?string
    {
        return $this->alias;
    }

    /**
     * @return list<string>
     */
    public function mailboxes(): array
    {
        return $this->mailboxes;
    }

    public function defaultMailbox(): string
    {
        return $this->mailboxes[0] ?? MailService::DEFAULT_MAILBOX;
    }

    public function isScoped(): bool
    {
        return null !== $this->alias
            || [] !== $this->mailboxes
            || [] !== $this->allowedSenders
            || [] !== $this->allowedRecipients;
    }

    /**
     * Whether a mailbox may be used. With an alias (and no explicit folders),
     * only INBOX is allowed so the model cannot browse personal folders.
     *
     * `$sentPath` is the folder the app resolved as the sent mailbox. When the
     * configured folder list mentions a sent name (`Sent`, `Poslano`, …), the
     * resolved folder is allowed too, so the configuration does not have to
     * guess Infomaniak's exact folder name.
     */
    public function allowsMailbox(string $path, ?string $sentPath = null): bool
    {
        $path = trim($path);

        if ([] !== $this->mailboxes) {
            foreach ($this->mailboxes as $mailbox) {
                if (self::matchesFolder($path, $mailbox)) {
                    return true;
                }
            }

            return $this->mentionsSentFolder() && $this->isSentFolder($path, $sentPath);
        }

        if (null !== $this->alias) {
            return 0 === strcasecmp($path, MailService::DEFAULT_MAILBOX);
        }

        return true;
    }

    /**
     * Whether incoming mail in this folder must carry the alias in its
     * recipient headers. A dedicated scope folder (a folder an Infomaniak rule
     * moves alias mail into) is itself the boundary, so membership is enough
     * there — this also covers Bcc mail whose stored headers no longer show the
     * alias. The sent folder matches on From instead (see allowsMessage).
     */
    public function requiresRecipientMatch(?string $mailbox = null, ?string $sentPath = null): bool
    {
        if (null === $this->alias) {
            return false;
        }

        $path = null !== $mailbox && '' !== trim($mailbox) ? trim($mailbox) : $this->defaultMailbox();
        if ($this->isSentFolder($path, $sentPath)) {
            return false;
        }

        return self::isInbox($path);
    }

    /**
     * The IMAP criteria that narrow a search to the policy, per folder:
     * messages addressed to the alias in INBOX, messages sent as the alias in
     * the sent folder, and nothing in a dedicated folder.
     *
     * @return array<string, string>
     */
    public function searchCriteria(string $mailbox, ?string $sentPath = null): array
    {
        if (null === $this->alias) {
            return [];
        }

        if ($this->isSentFolder($mailbox, $sentPath)) {
            return ['from' => $this->alias];
        }

        return $this->requiresRecipientMatch($mailbox, $sentPath) ? ['recipient' => $this->alias] : [];
    }

    /**
     * @param array<string, mixed> $message a message as returned by MailClient
     */
    public function allowsMessage(array $message, ?string $mailbox = null, ?string $sentPath = null): bool
    {
        $path = null !== $mailbox && '' !== trim($mailbox) ? trim($mailbox) : $this->defaultMailbox();

        if ($this->isSentFolder($path, $sentPath)) {
            // Sent mail has the user as sender and arbitrary recipients, so the
            // sender allowlist would hide it. The From header is the boundary.
            return null === $this->alias || $this->isOwnAddress(self::stringValue($message['from'] ?? null));
        }

        if (!$this->allowsSender(self::stringValue($message['from'] ?? null))) {
            return false;
        }

        if (!$this->requiresRecipientMatch($path, $sentPath)) {
            return true;
        }

        foreach (['to', 'cc', 'bcc', 'delivered_to'] as $header) {
            if (self::containsAddress(self::stringValue($message[$header] ?? null), (string) $this->alias)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Enforce the recipient allowlist. An empty list allows every recipient.
     *
     * @param list<string> $addresses
     */
    public function assertRecipients(array $addresses): void
    {
        if ([] === $this->allowedRecipients) {
            return;
        }

        foreach ($addresses as $address) {
            if (!\in_array(strtolower($address), $this->allowedRecipients, true)) {
                throw new MailException(sprintf(
                    'Sending to "%s" is not allowed by the mail access policy.',
                    $address,
                ));
            }
        }
    }

    /**
     * A one-sentence description for the MCP server instructions.
     */
    public function summary(): string
    {
        $parts = [];

        if (null !== $this->alias) {
            $parts[] = $this->mentionsSentFolder() && [] !== $this->mailboxes
                ? sprintf('only messages addressed to %s or sent as %s are available', $this->alias, $this->alias)
                : sprintf('only messages addressed to %s are available', $this->alias);
        }
        if ([] !== $this->mailboxes) {
            $parts[] = sprintf('only the "%s" mailboxes may be used', implode('", "', $this->mailboxes));
        } elseif (null !== $this->alias) {
            $parts[] = sprintf('only the "%s" mailbox may be used', MailService::DEFAULT_MAILBOX);
        }
        if ([] !== $this->allowedSenders) {
            $parts[] = 'only senders on the configured allowlist are visible';
        }
        if ([] !== $this->allowedRecipients) {
            $parts[] = 'mail may only be sent to addresses on the configured allowlist';
        }

        return ucfirst(implode('; ', $parts)).'. Mail outside this policy is not accessible through these tools.';
    }

    /**
     * Whether $path is the folder that holds sent messages. The resolved path
     * from {@see MailClient::findSentFolder()} wins; a folder literally named
     * like a sent folder is recognised as a fallback.
     */
    private function isSentFolder(string $path, ?string $sentPath): bool
    {
        if (null !== $sentPath && '' !== trim($sentPath) && self::sameFolder($path, $sentPath)) {
            return true;
        }

        return \in_array(mb_strtolower(self::lastSegment($path)), self::SENT_NAMES, true);
    }

    private function mentionsSentFolder(): bool
    {
        foreach ($this->mailboxes as $mailbox) {
            if (\in_array(mb_strtolower($mailbox), self::SENT_NAMES, true)) {
                return true;
            }

            if (\in_array(mb_strtolower(self::lastSegment($mailbox)), self::SENT_NAMES, true)) {
                return true;
            }
        }

        return false;
    }

    private function isOwnAddress(string $from): bool
    {
        $own = [strtolower((string) $this->alias)];
        if (null !== $this->sendAs) {
            $own[] = strtolower($this->sendAs);
        }

        foreach (self::addressesIn($from) as $address) {
            if (\in_array($address, $own, true)) {
                return true;
            }
        }

        return false;
    }

    private function allowsSender(string $from): bool
    {
        if ([] === $this->allowedSenders) {
            return true;
        }

        $address = self::firstAddress($from);

        return null !== $address && \in_array($address, $this->allowedSenders, true);
    }

    private static function matchesFolder(string $path, string $mailbox): bool
    {
        $mailbox = trim($mailbox);
        if ('' === $mailbox) {
            return false;
        }

        // The configured value may be the readable folder name while a caller
        // passes the full path from list_mailboxes (INBOX/Bot).
        return 0 === strcasecmp($path, $mailbox)
            || 0 === strcasecmp(self::lastSegment($path), $mailbox)
            || 0 === strcasecmp(self::lastSegment($path), self::lastSegment($mailbox));
    }

    private static function sameFolder(string $a, string $b): bool
    {
        return 0 === strcasecmp(trim($a), trim($b));
    }

    private static function isInbox(string $path): bool
    {
        return 0 === strcasecmp(trim($path), MailService::DEFAULT_MAILBOX)
            || 0 === strcasecmp(self::lastSegment($path), MailService::DEFAULT_MAILBOX);
    }

    private static function lastSegment(string $path): string
    {
        $segments = preg_split('#[/\\\\]#', trim($path)) ?: [];

        return (string) end($segments);
    }

    private static function containsAddress(string $header, string $address): bool
    {
        return \in_array(strtolower($address), self::addressesIn($header), true);
    }

    private static function firstAddress(string $header): ?string
    {
        return self::addressesIn($header)[0] ?? null;
    }

    /**
     * @return list<string> lower-case addresses found in a header value
     */
    private static function addressesIn(string $header): array
    {
        if (!preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $header, $matches)) {
            return [];
        }

        return array_values(array_unique(array_map('strtolower', $matches[0])));
    }

    private static function clean(?string $value): ?string
    {
        $value = null !== $value ? trim($value) : '';

        return '' === $value ? null : $value;
    }

    /**
     * Parse a comma/semicolon separated list of folder names. Folder names may
     * contain spaces ("Sent Items"), so whitespace is not a separator here.
     * JSON arrays are accepted too, so `["INBOX", "Sent"]` also works.
     *
     * @return list<string>
     */
    private static function parseMailboxes(?string $value): array
    {
        $value = trim((string) $value);
        if ('' === $value) {
            return [];
        }

        if (str_starts_with($value, '[')) {
            $decoded = json_decode($value, true);
            if (!\is_array($decoded)) {
                throw new MailException('INFOMANIAK_MAIL_SCOPE_MAILBOXES must be a comma-separated list of folder names.');
            }

            $value = implode(',', array_map(static fn (mixed $item): string => \is_string($item) ? $item : '', $decoded));
        }

        $mailboxes = [];
        foreach (preg_split('/[,;]+/', $value) ?: [] as $mailbox) {
            $mailbox = trim($mailbox, " \t\n\r\0\x0B\"'");
            if ('' !== $mailbox) {
                $mailboxes[] = $mailbox;
            }
        }

        return self::mergeMailboxes([], $mailboxes);
    }

    /**
     * @param list<string> $existing
     * @param list<string> $extra
     *
     * @return list<string>
     */
    private static function mergeMailboxes(array $existing, array $extra): array
    {
        $seen = [];
        $result = [];

        foreach ([...$existing, ...$extra] as $mailbox) {
            $mailbox = trim($mailbox, " \t\n\r\0\x0B\"'");
            if ('' === $mailbox) {
                continue;
            }

            $key = mb_strtolower($mailbox);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $result[] = $mailbox;
        }

        return $result;
    }

    /**
     * Parse a comma/semicolon/whitespace separated list of addresses. JSON
     * arrays are accepted too, so `["a@x", "b@y"]` also works.
     *
     * @return list<string> lower-case addresses
     */
    private static function parseAddresses(?string $value, string $key): array
    {
        $value = trim((string) $value);
        if ('' === $value) {
            return [];
        }

        if (str_starts_with($value, '[')) {
            $decoded = json_decode($value, true);
            if (!\is_array($decoded)) {
                throw new MailException(sprintf('%s must be a comma-separated list of email addresses.', $key));
            }

            $value = implode(',', array_map(static fn (mixed $item): string => \is_string($item) ? $item : '', $decoded));
        }

        $addresses = [];
        foreach (preg_split('/[\s,;]+/', $value) ?: [] as $address) {
            $address = trim($address, " \t\n\r\0\x0B\"'");
            if ('' === $address) {
                continue;
            }

            if (false === filter_var($address, \FILTER_VALIDATE_EMAIL)) {
                throw new MailException(sprintf('"%s" in %s is not a valid email address.', $address, $key));
            }

            $addresses[] = strtolower($address);
        }

        return array_values(array_unique($addresses));
    }

    private static function stringValue(mixed $value): string
    {
        return \is_string($value) ? $value : '';
    }
}
