<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Minimal, dependency-free HTML for the OAuth login and consent screens.
 */
final class Templates
{
    public static function login(string $authRequestId, ?string $error = null): string
    {
        $errorHtml = null !== $error
            ? '<p class="error">'.self::e($error).'</p>'
            : '';
        $id = self::e($authRequestId);

        return self::layout('Sign in', <<<HTML
            <h1>Sign in</h1>
            <p class="muted">Sign in to authorize access.</p>
            {$errorHtml}
            <form method="post" action="/login">
                <input type="hidden" name="id" value="{$id}">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" autocomplete="username" required autofocus>
                <label for="password">Password</label>
                <input type="password" id="password" name="password" autocomplete="current-password" required>
                <button type="submit">Continue</button>
            </form>
            HTML);
    }

    public static function consent(string $authRequestId, string $clientName, string $scope): string
    {
        $id = self::e($authRequestId);
        $name = self::e($clientName);
        $scopes = self::e($scope);
        $access = self::e(self::describeScopes($scope));

        return self::layout('Authorize access', <<<HTML
            <h1>Authorize access</h1>
            <p class="muted">
                <strong>{$name}</strong> is requesting access to your
                Infomaniak <strong>{$access}</strong>.
            </p>
            <p class="muted">Requested access: <code>{$scopes}</code></p>
            <form method="post" action="/consent" class="actions">
                <input type="hidden" name="id" value="{$id}">
                <input type="hidden" name="action" value="approve">
                <button type="submit" class="primary">Allow access</button>
            </form>
            <form method="post" action="/consent" class="actions">
                <input type="hidden" name="id" value="{$id}">
                <input type="hidden" name="action" value="deny">
                <button type="submit" class="secondary">Cancel</button>
            </form>
            HTML);
    }

    /**
     * Turn a space-separated scope string into a human-readable description
     * ("calendar mail" -> "calendar and email").
     */
    private static function describeScopes(string $scope): string
    {
        $labels = [
            'calendar' => 'calendar',
            'mail' => 'email',
        ];

        $parts = [];
        foreach (explode(' ', $scope) as $s) {
            if ('' === $s) {
                continue;
            }
            $parts[] = $labels[$s] ?? $s;
        }

        if ([] === $parts) {
            return 'account';
        }

        $last = array_pop($parts);

        return [] === $parts ? $last : implode(', ', $parts).' and '.$last;
    }

    public static function error(string $message): string
    {
        return self::layout('Error', '<h1>Error</h1><p class="error">'.self::e($message).'</p>');
    }

    private static function layout(string $title, string $body): string
    {
        return <<<HTML
        <!doctype html>
        <html lang="en">
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>{$title}</title>
            <style>
                :root { color-scheme: light dark; }
                body { font-family: system-ui, -apple-system, sans-serif; max-width: 26rem; margin: 4rem auto; padding: 0 1rem; line-height: 1.5; }
                h1 { font-size: 1.4rem; }
                label { display: block; margin-top: 1rem; font-weight: 600; }
                input { width: 100%; box-sizing: border-box; padding: .5rem; margin-top: .25rem; font-size: 1rem; }
                button { margin-top: 1rem; padding: .5rem 1rem; font-size: 1rem; cursor: pointer; }
                .primary { background: #2563eb; color: #fff; border: 0; }
                .secondary { background: transparent; border: 1px solid #888; }
                .actions { display: inline-block; margin-right: .5rem; }
                .muted { color: #777; }
                .error { color: #b91c1c; }
                code { background: rgba(127,127,127,.15); padding: .1rem .3rem; border-radius: .25rem; }
            </style>
        </head>
        <body>{$body}</body>
        </html>
        HTML;
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }
}
