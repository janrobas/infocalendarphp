<?php

declare(strict_types=1);

namespace App\Mail;

/**
 * Codec for IMAP's modified UTF-7 (RFC 3501, section 5.1.3) — the encoding
 * IMAP servers such as Infomaniak's use on the wire for mailbox names.
 *
 * Human-readable mailbox names are UTF-8, but the wire format (what
 * {@see MailClient::listFolders()} reports as `path`) escapes every non-ASCII
 * character into a `&...-` shift sequence, e.g. `ŠCKR TODO` → `&AWA-CKR TODO`.
 * The IMAP library used by {@see MailClient} expects the readable UTF-8 form, so
 * wire paths have to be decoded before they can be selected.
 *
 * {@see decode()} is deliberately conservative: only well-formed `&...-`
 * sequences are converted and everything else is returned byte-for-byte
 * unchanged. Decoding an already readable name is therefore always a no-op, so
 * both forms can be accepted safely.
 */
final class ModifiedUtf7
{
    /**
     * Decode a modified UTF-7 mailbox name or path (including its `/` delimiter)
     * to UTF-8. `&-` decodes to a literal `&`; malformed or unterminated
     * sequences are left untouched.
     */
    public static function decode(string $value): string
    {
        if (!str_contains($value, '&')) {
            return $value;
        }

        $decoded = '';
        $offset = 0;
        $length = \strlen($value);

        while ($offset < $length) {
            $start = strpos($value, '&', $offset);
            if (false === $start) {
                return $decoded.substr($value, $offset);
            }

            $decoded .= substr($value, $offset, $start - $offset);

            $end = strpos($value, '-', $start + 1);
            if (false === $end) {
                // Unterminated shift sequence: keep the remainder verbatim.
                return $decoded.substr($value, $start);
            }

            $segment = substr($value, $start + 1, $end - $start - 1);
            $text = self::decodeSegment($segment);

            // Not a shift sequence (e.g. a plain "&" inside a readable name):
            // keep the original bytes.
            $decoded .= $text ?? substr($value, $start, $end - $start + 1);
            $offset = $end + 1;
        }

        return $decoded;
    }

    /**
     * Decode the payload of a single `&...-` sequence. Returns null when the
     * payload is not valid modified base64 holding UTF-16BE text.
     */
    private static function decodeSegment(string $segment): ?string
    {
        if ('' === $segment) {
            return '&';
        }

        // Modified base64 uses ',' where standard base64 uses '/'.
        if (1 !== preg_match('/^[A-Za-z0-9+,]+$/', $segment)) {
            return null;
        }

        $binary = base64_decode(
            strtr($segment, ',', '/').str_repeat('=', (4 - \strlen($segment) % 4) % 4),
            true,
        );

        if (false === $binary || 0 !== \strlen($binary) % 2 || !mb_check_encoding($binary, 'UTF-16BE')) {
            return null;
        }

        return mb_convert_encoding($binary, 'UTF-8', 'UTF-16BE');
    }
}
