<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Removes known secret values from strings before they are logged or shown to
 * the browser, so credentials never leak through error messages or stack traces.
 */
final class SecretRedactor
{
    /** @var list<string> */
    private array $secrets;

    /**
     * @param list<string> $secrets
     */
    public function __construct(array $secrets)
    {
        $unique = [];
        foreach ($secrets as $secret) {
            if (!\is_string($secret)) {
                continue;
            }

            $secret = trim($secret);
            if (strlen($secret) < 4) {
                continue;
            }

            $unique[$secret] = true;
        }

        $this->secrets = array_keys($unique);
    }

    public function redact(string $text): string
    {
        foreach ($this->secrets as $secret) {
            $text = str_replace($secret, '[REDACTED]', $text);
        }

        return $text;
    }
}
