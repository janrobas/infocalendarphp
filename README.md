# Infomaniak Calendar MCP Server

A small, single-user PHP application that exposes your **Infomaniak Calendar**
to **ChatGPT Edu** over the [Model Context Protocol](https://modelcontextprotocol.io)
(MCP), using the official PHP MCP SDK.

It is not a framework, SaaS product, or admin panel. It is one PHP application
with two jobs:

1. Authenticate ChatGPT using OAuth 2.1 (authorization code + PKCE).
2. Expose calendar operations (`list_calendars`, `get_events`, `create_event`,
   …) over MCP Streamable HTTP, backed by the live Infomaniak CalDAV server.

```
ChatGPT Edu
    |  HTTPS  +  OAuth 2.1 + PKCE
    v
PHP MCP server  (/mcp)
    |-----------------------------------|
    v                                   v
OAuth token files (data/)          Infomaniak CalDAV
                                           |
                                     Infomaniak Calendar
```

All calendar data is read **live** from Infomaniak on every call. Nothing is
cached locally, so there are never stale events.

---

## Requirements

- PHP **8.3+** (8.1+ works, 8.3 recommended)
- Composer
- HTTPS (required by the OAuth flow and by ChatGPT)
- PHP extensions: `curl`, `mbstring`, `openssl`, `fileinfo`, `dom`, `xml`,
  `xmlwriter` (all standard on common PHP hosts)
- An Infomaniak account with an **application password**

---

## Installation

```bash
git clone <your-repo-url> infomaniak-mcp
cd infomaniak-mcp
composer install --no-dev --optimize-autoloader
cp .env.example .env
```

Then edit `.env` and configure the values described below.

Create the data directory with restrictive permissions:

```bash
mkdir -p data
chmod 700 data
```

### Configure `.env`

| Variable | Description |
| --- | --- |
| `APP_URL` | Public base URL of this server, e.g. `https://calendar.example.com` (no trailing slash). |
| `INFOMANIAK_CALDAV_URL` | CalDAV endpoint. Defaults to `https://sync.infomaniak.com/`. |
| `INFOMANIAK_USERNAME` | Your Infomaniak login (usually your email). |
| `INFOMANIAK_APP_PASSWORD` | An Infomaniak **application password** (app-specific password for sync apps). |
| `OAUTH_USERNAME` | The single local user name shown on the login page. |
| `OAUTH_PASSWORD_HASH` | A bcrypt hash of the login password (see below). |
| `OAUTH_ACCESS_TOKEN_LIFETIME` | Access token lifetime in seconds (default 3600). |
| `OAUTH_REFRESH_TOKEN_LIFETIME` | Refresh token lifetime in seconds (default 2592000). |
| `APP_DEBUG` | `1` to print errors to the browser (development only). |

### Generate the password hash

```bash
php bin/generate-password.php "your-password"
```

This prints a `password_hash()` bcrypt value. Put it in `OAUTH_PASSWORD_HASH`.
The plaintext password is never stored.

### Generate an Infomaniak application password

In your Infomaniak account: **Account settings → Application passwords**
(or the equivalent location) and create a new password for this app. The
application password is the credential the PHP server uses to talk to CalDAV.
It is stored only in `.env` and is never exposed to ChatGPT, logged, or
returned in any MCP response.

---

## Web server configuration

The document root **must** be the `public_html/` directory. Everything else
(`src/`, `vendor/`, `data/`, `.env`) stays outside the web root.

### Apache

Example configuration: [`deploy/apache-vhost.conf`](deploy/apache-vhost.conf).

```apache
<VirtualHost *:443>
    ServerName calendar.example.com
    DocumentRoot /path/to/infomaniak-mcp/public_html

    SSLEngine on
    # ... your TLS certificates ...

    SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1

    <Directory /path/to/infomaniak-mcp/public_html>
        AllowOverride All
        Require all granted
        Options -Indexes
    </Directory>
</VirtualHost>
```

The provided [`public_html/.htaccess`](public_html/.htaccess) routes every request
through the front controller and forwards the `Authorization` header to PHP.

### Nginx

Example configuration: [`deploy/nginx.conf`](deploy/nginx.conf).

```nginx
server {
    listen 443 ssl http2;
    server_name calendar.example.com;

    root /path/to/infomaniak-mcp/public_html;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_param HTTP_AUTHORIZATION $http_authorization;
        fastcgi_buffering off;   # required for MCP SSE streams
    }

    location ~ /\. { deny all; }
}
```

The `fastcgi_buffering off` directive is important: the MCP Streamable HTTP
transport may stream Server-Sent Events, and buffering would delay or break it.

### Verify the deployment

```bash
curl https://calendar.example.com/health
# {"status":"ok"}

curl https://calendar.example.com/health/caldav
# {"status":"ok"}   (after configuring Infomaniak credentials)
```

---

## Shared hosting (cPanel / DirectAdmin / Neoserv)

This app runs on ordinary shared hosting. The project already uses the
`public_html/` layout that shared hosts expect: the panel serves from
`public_html/` (or `www/`) as the document root, and `data/`, `.env`, `src/`
and `vendor/` live alongside it, outside the web root. Follow the steps below.

### 1. Run the preflight check

First upload the project (or run locally) and confirm the account is ready:

```bash
php deploy/check.php
```

It checks the PHP version, the required extensions and writable storage.
If your account has no SSH/PHP CLI, you can run this locally — but then also
verify the PHP version and extensions in the hosting panel.

### 2. Choose PHP 8.3+ and enable extensions

In the hosting panel, set the PHP version to **8.3 or 8.4** and enable these
extensions (they are standard, but `fileinfo` is sometimes off by default):

`curl`, `mbstring`, `openssl`, `fileinfo`, `dom`, `xml`, `xmlwriter`.

### 3. Build the vendor directory

If you have SSH/Composer on the host:

```bash
composer install --no-dev --optimize-autoloader
```

Otherwise run that command **locally** (you need PHP 8.3+ locally) and upload
the generated `vendor/` directory together with the rest of the project.

### 4. Upload the project

The repository is already in the correct shape: `public_html/` is the document
root, and everything else sits next to it. Two layouts work:

**Main domain (document root is `/home/you/public_html`).** Upload the
project *contents* — `public_html/`, `src/`, `vendor/`, `data/`, `bin/`,
`deploy/`, `.env`, `composer.json` — into your account home (`/home/you/`).
The repo's `public_html/` becomes the account's web root.

**Subdomain (recommended).** Upload the whole project into a folder such as
`/home/you/infomaniak-mcp/`, then create a subdomain and set its document root
to `/home/you/infomaniak-mcp/public_html`.

In both cases, **do not** place `data/`, `.env`, `src/` or `vendor/` under the
document root.

### 5. Configure `.env`

```bash
cp .env.example .env
```

Fill in `APP_URL` (your HTTPS URL), the Infomaniak credentials, and
`OAUTH_PASSWORD_HASH` (generate with `php bin/generate-password.php` locally).

### 6. Set permissions

```bash
chmod 700 data
chmod 600 .env
```

### 7. Enable HTTPS

Turn on the free Let's Encrypt certificate in the hosting panel. ChatGPT only
connects to public HTTPS endpoints.

### 8. Verify

```bash
curl https://your-domain.com/health
# {"status":"ok"}
```

`/health` works even before Infomaniak is configured, so it is a good first
deployment check. If the hosting uses Apache/LiteSpeed, the bundled
`public_html/.htaccess` already forwards the `Authorization` header.

---

## Data directory

The `data/` directory holds OAuth state and MCP sessions as JSON files:

```text
data/
├── oauth/
│   ├── auth_requests/      # pending authorization requests
│   ├── auth_codes/         # authorization codes (single use, hashed)
│   ├── access_tokens/      # access tokens (hashed)
│   └── refresh_tokens/     # refresh tokens (hashed, rotated)
├── sessions/               # MCP session state (handshake era)
├── rate_limit/             # login rate limiting state
├── session.key             # HMAC key for the login session cookie
└── app.log
```

Notes:

- `data/` **must not be web-accessible**. The standard layout already keeps it
  outside `public_html/`. A defensive [`data/.htaccess`](data/.htaccess) denies
  access if it is ever moved under the document root.
- Sensitive values (authorization codes, access tokens, refresh tokens) are
  stored under a SHA-256 hash of the value, so the raw secrets never touch
  disk.
- Writes are atomic (temp file + `rename`) and records carry expiration
  timestamps. Expired records are removed on read and by periodic cleanup.

---

## OAuth flow

The application implements a standards-compliant OAuth 2.1 authorization
server:

1. ChatGPT sends an MCP request without a token.
2. The server returns `401` with:
   ```
   WWW-Authenticate: Bearer resource_metadata="https://calendar.example.com/.well-known/oauth-protected-resource", scope="calendar"
   ```
3. ChatGPT fetches the **Protected Resource Metadata** (RFC 9728) at
   `/.well-known/oauth-protected-resource`, then the **Authorization Server
   Metadata** (RFC 8414) at `/.well-known/oauth-authorization-server`.
4. ChatGPT performs **Dynamic Client Registration** (RFC 7591) at `/register`.
5. ChatGPT opens `/authorize` in the browser with a PKCE `code_challenge`
   (S256) and a `resource` parameter (RFC 8707).
6. The user signs in and approves access ("ChatGPT is requesting access to
   your Infomaniak Calendar").
7. The server redirects back with `code`, `state`, and `iss` (RFC 9207).
8. ChatGPT exchanges the code at `/token` with the `code_verifier`, receiving
   an access token and a refresh token.
9. ChatGPT calls `/mcp` with `Authorization: Bearer <access-token>`.

Supported endpoints:

| Method | Path | Purpose |
| --- | --- | --- |
| GET | `/.well-known/oauth-protected-resource` | Protected Resource Metadata (RFC 9728) |
| GET | `/.well-known/oauth-authorization-server` | Authorization Server Metadata (RFC 8414) |
| GET | `/.well-known/openid-configuration` | Same AS metadata (OIDC discovery) |
| POST | `/register` | Dynamic Client Registration (RFC 7591) |
| GET | `/authorize` | Authorization endpoint (login/consent) |
| POST | `/login` | Login form handler |
| GET/POST | `/consent` | Consent screen |
| POST | `/token` | Token endpoint (code + refresh_token) |
| POST | `/mcp` | MCP Streamable HTTP endpoint |
| GET | `/health` | Health check |

---

## Connecting to ChatGPT Edu

> The ChatGPT UI changes over time. Refer to the current OpenAI documentation
> (help.openai.com "Apps in ChatGPT" and developers.openai.com "Authentication")
> for the exact click-path. The steps below describe the current flow.

1. **Deploy** the application and confirm `/health` returns `{"status":"ok"}`.

2. **Enable HTTPS.** ChatGPT only connects to public HTTPS endpoints.

3. **Determine the MCP URL.** This is your `APP_URL` plus `/mcp`, e.g.
   `https://calendar.example.com/mcp`.

4. **Enable Developer Mode** (for personal/custom MCP servers):
   In ChatGPT → **Settings → Security and login → Developer mode**, turn it on.

5. **Add the connection** under **ChatGPT → Plugins (or "Connect to apps") →
   +**. Under **Connection**, enter the MCP URL including `/mcp`:
   ```
   https://calendar.example.com/mcp
   ```

6. **Configure OAuth.** ChatGPT discovers the OAuth configuration
   automatically from the endpoints above (WWW-Authenticate, protected
   resource metadata, AS metadata). If ChatGPT asks for a redirect URI
   allowlist, add the redirect URI shown in the ChatGPT app management page —
   for example:
   ```
   https://chatgpt.com/connector_platform_oauth_redirect
   ```
   (The exact redirect URI is provided by ChatGPT; the server validates it
   against the URIs the client registered via DCR, so this is normally
   automatic.)

7. **Authorize.** The first time a calendar tool runs, ChatGPT opens the
   `/authorize` page in your browser. Sign in with `OAUTH_USERNAME` /
   your password and click **Allow access**.

8. **Test** by asking ChatGPT, for example:
   - *"List my calendars."* → calls `list_calendars`
   - *"What's on my calendar next Tuesday?"* → calls `get_events`
   - *"Schedule a 30 minute meeting called 'Design review' tomorrow at 2pm."*
     → calls `create_event`

### Configuration values that come from ChatGPT

The following are supplied by ChatGPT (or its OAuth flow), not by this
application, and must match what ChatGPT sends:

- The `client_id` and `redirect_uris` — registered dynamically via `/register`.
- The `code_challenge` / `code_challenge_method` — generated by ChatGPT.
- The `resource` parameter — ChatGPT sends your `APP_URL` or `APP_URL/mcp`.

The application validates all of these against registered/expected values.

---

## MCP tools

| Tool | Read/Write | Description |
| --- | --- | --- |
| `list_calendars` | read | List all Infomaniak calendars with their `id`, `name`, `description`, `color`. |
| `get_events` | read | Events in a range (`calendar_id`, `start`, `end`, ISO 8601). |
| `search_events` | read | Full-text search of titles/descriptions/locations in a range. |
| `find_free_slots` | read | Free periods of at least `duration_minutes` in a range. |
| `create_event` | write | Create an event (`title`, `start`, `end`, optional `description`/`location`). |
| `update_event` | write | Update event fields; only provided fields change, others are preserved. |
| `delete_event` | write | Permanently delete an event (destructive, cannot be undone). |

All dates/times are ISO 8601. Time zone information from the iCalendar data is
preserved (the server never silently converts to the server's local time
zone).

---

## Security

- **OAuth:** PKCE (S256 only), exact redirect-URI validation, `state`
  passthrough, short-lived access tokens, refresh-token rotation, RFC 8707
  `resource` (audience) binding, RFC 9207 `iss` in authorization responses.
- **Tokens:** 256-bit cryptographically random opaque tokens, stored hashed.
- **Authentication:** password stored only as a bcrypt hash; login attempts are
  rate-limited using filesystem state; the session cookie is HMAC-signed and
  `HttpOnly`/`SameSite=Lax`/`Secure`.
- **HTTP:** HTTPS required, security headers (`X-Content-Type-Options`,
  `X-Frame-Options`, `Referrer-Policy`, `Content-Security-Policy`), request
  body size limits, DNS-rebinding protection.
- **Filesystem:** `data/` outside the web root; restrictive permissions;
  storage keys are validated (no path traversal); atomic writes.
- **Logging:** operational logs never include the Infomaniak password, OAuth
  tokens, authorization codes, or calendar contents.
- **No credential leakage:** Infomaniak credentials are used only server-side
  and never appear in MCP tool responses, errors, or browser responses.

---

## Tests

```bash
composer install          # installs dev dependencies (PHPUnit)
vendor/bin/phpunit        # or: composer test
```

The suite covers the OAuth server (discovery, DCR, PKCE, code exchange,
refresh rotation, invalid/expired tokens), filesystem storage (atomic writes,
expiry, cleanup), and calendar logic (event parsing, creation, update with
ETag-conflict handling, deletion, free-slot calculation) using a mocked CalDAV
client — no live Infomaniak account is required.

---

## Compatibility notes

- **Protocol version.** ChatGPT's connector targets the MCP
  **`initialize` handshake era** (`2025-06-18` / `2025-11-25`). The official
  PHP MCP SDK (`mcp/sdk`) used here serves **both** the handshake era and the
  newer stateless `2026-07-28` revision from the same `/mcp` endpoint, so it
  interoperates with ChatGPT and with current/older MCP clients.
- **Client registration.** This server implements **Dynamic Client
  Registration (RFC 7591)** and advertises `registration_endpoint`. It does not
  advertise Client ID Metadata Documents (`client_id_metadata_document_supported`),
  so ChatGPT falls back to DCR.
- **Token endpoint auth.** Public-client auth method `none` + PKCE S256 is
  supported (`token_endpoint_auth_methods_supported: ["none"]`).

---

## Project structure

```text
infomaniak-mcp/
├── public_html/          # web root (document root)
│   ├── index.php          # front controller + routing
│   └── .htaccess
├── src/
│   ├── Auth/              # login session (signed cookie) + rate limiter
│   ├── Calendar/          # CalDavClient, CalendarService, iCalendar codec
│   ├── Http/              # response helpers, OAuth controller, HTML templates
│   ├── Mcp/               # MCP server + tool definitions
│   ├── OAuth/             # authorization server, client/token stores, validator
│   ├── Storage/           # atomic JSON file store
│   ├── Support/           # PSR-3 file logger
│   └── Config.php
├── data/                  # runtime state (outside web root)
├── bin/generate-password.php
├── deploy/                # Apache + Nginx examples + deployment check
│   └── check.php          # preflight: PHP version, extensions, storage
├── tests/
├── .env.example
├── composer.json
└── README.md
```
