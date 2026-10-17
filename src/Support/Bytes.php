<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Human-readable byte sizes for messages that are shown to the user or the
 * model (limit errors, mainly).
 */
final class Bytes
{
    public static function format(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return sprintf('%.1f MB', $bytes / 1048576);
        }

        if ($bytes >= 1024) {
            return sprintf('%.0f KB', $bytes / 1024);
        }

        return sprintf('%d B', $bytes);
    }
}
