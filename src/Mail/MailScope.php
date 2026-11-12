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
 *  - `alias` limits every read to messages addressed to that address (To, Cc
 *    or the Delivered-To header, which usually records a Bcc delivery), and
 *    limits the default/mailbox set to INBOX.
 *  - `mailbox` limits every operation to one folder (for example a folder that
 *    an Infomaniak rule moves alias mail into).
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
     * @param list<string> $allowedSenders    lower-case addresses; empty means all
     * @param list<string> $allowedRecipients lower-case addresses; empty means all
     */
    public function __construct(
        private readonly ?string $alias = null,
        private readonly ?string $mailbox = null,
        private readonly array $allowedSenders = [],
        private readonly array $allowedRecipients = [],
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
        $mailbox = self::clean($config->get('INFOMANIAK_MAIL_SCOPE_MAILBOX'));
        $senders = self::parseAddresses($config->get('INFOMANIAK_MAIL_ALLOWED_SENDERS'), 'INFOMANIAK_MAIL_ALLOWED_SENDERS');
        $recipients = self::parseAddresses($config->get('INFOMANIAK_MAIL_ALLOWED_RECIPIENTS'), 'INFOMANIAK_MAIL_ALLOWED_RECIPIENTS');

        if (null === $alias && null === $mailbox && [] === $senders && [] === $recipients) {
            return null;
        }

        if (null !== $alias && false === filter_var($alias, \FILTER_VALIDATE_EMAIL)) {
            throw new MailException(sprintf('INFOMANIAK_MAIL_ALIAS ("%s") is not a valid email address.', $alias));
        }

        return new self($alias, $mailbox, $senders, $recipients);
    }

    public function alias(): ?string
    {
        return $this->alias;
    }

    public function mailbox(): ?string
    {
        return $this->mailbox;
    }

    public function defaultMailbox(): string
    {
        return $this->mailbox ?? MailService::DEFAULT_MAILBOX;
    }

    public function isScoped(): bool
    {
        return null !== $this->alias
            || null !== $this->mailbox
            || [] !== $this->allowedSenders
            || [] !== $this->allowedRecipients;
    }

    /**
     * Whether a mailbox may be used. With an alias (and no explicit folder),
     * only INBOX is allowed so the model cannot browse personal folders.
     */
    public function allowsMailbox(string $path): bool
    {
        $path = trim($path);

        if (null !== $this->mailbox) {
            if (0 === strcasecmp($path, $this->mailbox)) {
                return true;
            }

            // The configured value may be the readable folder name while a
            // caller passes the full path from list_mailboxes (INBOX/Bot).
            $segments = preg_split('#[/\\\\]#', $path) ?: [];

            return 0 === strcasecmp((string) end($segments), $this->mailbox);
        }

        if (null !== $this->alias) {
            return 0 === strcasecmp($path, MailService::DEFAULT_MAILBOX);
        }

        return true;
    }

    /**
     * Whether a message must carry the alias in its recipient headers.
     *
     * A dedicated scope folder (a folder an Infomaniak rule moves alias mail
     * into) is itself the boundary, so membership is enough there — this also
     * covers Bcc mail whose stored headers no longer show the alias. INBOX is
     * not a dedicated folder, so an alias always filters inside it.
     */
    public function requiresRecipientMatch(): bool
    {
        return null !== $this->alias
            && (null === $this->mailbox || 0 === strcasecmp($this->mailbox, MailService::DEFAULT_MAILBOX));
    }

    /**
     * @param array<string, mixed> $message a message as returned by MailClient
     */
    public function allowsMessage(array $message): bool
    {
        if (!$this->allowsSender(self::stringValue($message['from'] ?? null))) {
            return false;
        }

        if (!$this->requiresRecipientMatch()) {
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
            $parts[] = sprintf('only messages addressed to %s are available', $this->alias);
        }
        if (null !== $this->mailbox) {
            $parts[] = sprintf('only the "%s" mailbox may be used', $this->mailbox);
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

    private function allowsSender(string $from): bool
    {
        if ([] === $this->allowedSenders) {
            return true;
        }

        $address = self::firstAddress($from);

        return null !== $address && \in_array($address, $this->allowedSenders, true);
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
