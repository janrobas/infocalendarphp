<?php

declare(strict_types=1);

namespace App\Calendar;

use DateTimeImmutable;
use DOMDocument;
use DOMXPath;
use Sabre\DAV\Client as SabreClient;
use Sabre\DAV\Xml\Property\Href;
use Sabre\DAV\Xml\Property\ResourceType;

/**
 * Thin CalDAV client built on sabre/dav. Handles authentication, calendar
 * discovery (PROPFIND), event listing (REPORT calendar-query), and event
 * creation/update/deletion with ETag handling.
 */
class CalDavClient
{
    private const CALDAV_NS = 'urn:ietf:params:xml:ns:caldav';

    public function __construct(
        private readonly SabreClient $client,
        private readonly string $baseUri = '',
        private readonly string $username = '',
    ) {
    }

    public static function create(string $baseUri, string $username, string $password): self
    {
        $baseUri = rtrim($baseUri, '/').'/';

        $client = new SabreClient([
            'baseUri' => $baseUri,
            'userName' => $username,
            'password' => $password,
            'authType' => SabreClient::AUTH_BASIC,
        ]);

        // Map href-valued properties so propFind() returns convenient objects.
        $client->propertyMap['{DAV:}current-user-principal'] = Href::class;
        $client->propertyMap['{urn:ietf:params:xml:ns:caldav}calendar-home-set'] = Href::class;

        return new self($client, $baseUri, $username);
    }

    /**
     * @return list<array{href: string, name: string, description: string, color: string|null}>
     */
    public function discoverCalendars(): array
    {
        foreach ($this->homeCandidates() as $candidate) {
            try {
                $calendars = $this->listCalendars($candidate);
            } catch (CalDavException $e) {
                if (404 === $e->status) {
                    continue;
                }

                throw $e;
            }

            if ([] !== $calendars) {
                return $calendars;
            }
        }

        throw new CalDavException('Unable to discover calendars on the Infomaniak server.');
    }

    /**
     * @return list<array{href: string, etag: string, ical: string}>
     */
    public function getEvents(string $calendarHref, DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $xml = $this->calendarQueryXml($start, $end, null);
        $raw = $this->report($calendarHref, $xml);

        return $this->parseMultistatus($raw);
    }

    /**
     * @return array{href: string, etag: string, ical: string}|null
     */
    public function getEventByUid(string $calendarHref, string $uid): ?array
    {
        $xml = $this->calendarQueryXml(null, null, $uid);
        $raw = $this->report($calendarHref, $xml);

        $events = $this->parseMultistatus($raw);

        return $events[0] ?? null;
    }

    /**
     * Create an event and return its href and ETag.
     *
     * @return array{href: string, etag: string}
     */
    public function createEvent(string $calendarHref, string $uid, string $ical): array
    {
        $url = $this->eventUrl($calendarHref, $uid);
        $response = $this->request('PUT', $url, $ical, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'If-None-Match' => '*',
        ]);

        $status = (int) $response['statusCode'];
        if ($status < 200 || $status >= 300) {
            throw CalDavException::forStatus($status);
        }

        return [
            'href' => $url,
            'etag' => $this->extractEtag($response['headers']),
        ];
    }

    /**
     * Update an event conditionally on its ETag. Returns the new ETag.
     */
    public function updateEvent(string $href, string $etag, string $ical): string
    {
        $response = $this->request('PUT', $href, $ical, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'If-Match' => $etag,
        ]);

        $status = (int) $response['statusCode'];
        if ($status < 200 || $status >= 300) {
            throw CalDavException::forStatus($status, 'Event not found.');
        }

        return $this->extractEtag($response['headers']);
    }

    public function deleteEvent(string $href, string $etag): void
    {
        $response = $this->request('DELETE', $href, null, [
            'If-Match' => $etag,
        ]);

        $status = (int) $response['statusCode'];
        if ($status < 200 || $status >= 300) {
            throw CalDavException::forStatus($status, 'Event not found.');
        }
    }

    private function propFindHref(string $url, string $property): ?string
    {
        try {
            $result = $this->client->propFind($url, [$property], 0);
        } catch (\Sabre\HTTP\ClientHttpException $e) {
            if (404 === $e->getHttpStatus()) {
                return null;
            }

            throw CalDavException::forStatus($e->getHttpStatus());
        } catch (\Sabre\HTTP\ClientException) {
            throw new CalDavException('Calendar server unavailable.');
        }

        $value = $result[$property] ?? null;
        if ($value instanceof Href) {
            return $value->getHref();
        }
        if (\is_string($value) && '' !== $value) {
            return $value;
        }
        if (\is_array($value) && isset($value[0]) && \is_string($value[0])) {
            return $value[0];
        }

        return null;
    }

    /**
     * Candidate calendar-home URLs, in order: standard discovery first, then
     * common per-account paths for the sabre/dav server Infomaniak uses.
     *
     * @return list<string>
     */
    private function homeCandidates(): array
    {
        $homes = [];

        $principal = $this->propFindHref('', '{DAV:}current-user-principal');
        if (null !== $principal) {
            $home = $this->propFindHref($principal, '{'.self::CALDAV_NS.'}calendar-home-set');
            if (null !== $home) {
                $homes[] = $home;
            }
        }

        $home = $this->propFindHref('', '{'.self::CALDAV_NS.'}calendar-home-set');
        if (null !== $home) {
            $homes[] = $home;
        }

        foreach ($this->guessedHomes() as $guess) {
            $homes[] = $guess;
        }

        return array_values(array_unique($homes));
    }

    /**
     * @return list<string>
     */
    private function guessedHomes(): array
    {
        if ('' === $this->username) {
            return [];
        }

        $base = $this->baseUri;
        $users = [$this->username, rawurlencode($this->username)];

        $candidates = [];
        foreach (['calendars', 'principals'] as $segment) {
            foreach ($users as $user) {
                $candidates[] = rtrim($base, '/').'/'.$segment.'/'.$user.'/';
            }
        }

        return array_values(array_unique($candidates));
    }

    /**
     * @return list<array{href: string, name: string, description: string, color: string|null}>
     */
    private function listCalendars(string $home): array
    {
        $result = $this->guarded(fn () => $this->client->propFind($home, [
            '{DAV:}resourcetype',
            '{DAV:}displayname',
            '{http://apple.com/ns/ical/}calendar-color',
            '{'.self::CALDAV_NS.'}calendar-description',
        ], 1));

        $calendars = [];
        foreach ($result as $href => $props) {
            if (!$this->isCalendar($props['{DAV:}resourcetype'] ?? null)) {
                continue;
            }

            $calendars[] = [
                'href' => (string) $href,
                'name' => $this->stringValue($props['{DAV:}displayname'] ?? null) ?: basename(rtrim((string) $href, '/')),
                'description' => $this->stringValue($props['{'.self::CALDAV_NS.'}calendar-description'] ?? null),
                'color' => $this->nullableStringValue($props['{http://apple.com/ns/ical/}calendar-color'] ?? null),
            ];
        }

        return $calendars;
    }

    private function isCalendar(mixed $resourceType): bool
    {
        $types = [];
        if ($resourceType instanceof ResourceType) {
            $types = $resourceType->getValue();
        } elseif (\is_array($resourceType)) {
            $types = $resourceType;
        } elseif (\is_string($resourceType)) {
            $types = [$resourceType];
        }

        return \in_array('{'.self::CALDAV_NS.'}calendar', $types, true);
    }

    private function calendarQueryXml(?DateTimeImmutable $start, ?DateTimeImmutable $end, ?string $uid): string
    {
        $propFilter = '';
        if (null !== $uid) {
            $propFilter = sprintf(
                '<c:prop-filter name="UID"><c:text-match collation="i;octet">%s</c:text-match></c:prop-filter>',
                htmlspecialchars($uid, \ENT_XML1 | \ENT_QUOTES, 'UTF-8'),
            );
        }

        $timeRange = '';
        if (null !== $start && null !== $end) {
            $timeRange = sprintf(
                '<c:time-range start="%s" end="%s"/>',
                $start->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z'),
                $end->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z'),
            );
        }

        return '<?xml version="1.0" encoding="utf-8"?>'
            .'<c:calendar-query xmlns:d="DAV:" xmlns:c="'.self::CALDAV_NS.'">'
            .'<d:prop><d:getetag/><c:calendar-data/></d:prop>'
            .'<c:filter><c:comp-filter name="VCALENDAR"><c:comp-filter name="VEVENT">'
            .$timeRange.$propFilter
            .'</c:comp-filter></c:comp-filter></c:filter>'
            .'</c:calendar-query>';
    }

    /**
     * @return array{body: string, statusCode: int, headers: array<string, string>}
     */
    private function report(string $calendarHref, string $xml): array
    {
        $response = $this->request('REPORT', $calendarHref, $xml, [
            'Content-Type' => 'application/xml; charset=utf-8',
            'Depth' => '1',
        ]);

        $status = (int) $response['statusCode'];
        if ($status < 200 || $status >= 300) {
            throw CalDavException::forStatus($status);
        }

        return $response;
    }

    /**
     * Wrap a raw HTTP request, converting network-level failures into a safe
     * CalDavException.
     *
     * @return array{body: string, statusCode: int, headers: array<string, string>}
     */
    private function request(string $method, string $url, ?string $body, array $headers): array
    {
        try {
            return $this->client->request($method, $url, $body, $headers);
        } catch (\Sabre\HTTP\ClientException) {
            throw new CalDavException('Calendar server unavailable.');
        }
    }

    /**
     * Wrap a sabre call that may throw HTTP or network exceptions (e.g.
     * propFind), converting them into safe, specific CalDavException messages.
     */
    private function guarded(callable $fn, string $notFoundMessage = 'Calendar not found.')
    {
        try {
            return $fn();
        } catch (\Sabre\HTTP\ClientHttpException $e) {
            throw CalDavException::forStatus($e->getHttpStatus(), $notFoundMessage);
        } catch (\Sabre\HTTP\ClientException) {
            throw new CalDavException('Calendar server unavailable.');
        }
    }

    /**
     * @param array{body: string} $response
     *
     * @return list<array{href: string, etag: string, ical: string}>
     */
    private function parseMultistatus(array $response): array
    {
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadXML($response['body']);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('d', 'DAV:');
        $xpath->registerNamespace('c', self::CALDAV_NS);

        $events = [];
        foreach ($xpath->query('//d:response') as $responseNode) {
            $hrefNode = $xpath->query('d:href', $responseNode)->item(0);
            $etagNode = $xpath->query('.//d:propstat[d:status[contains(.," 200 ")]]/d:prop/d:getetag', $responseNode)->item(0);
            $dataNode = $xpath->query('.//d:propstat[d:status[contains(.," 200 ")]]/d:prop/c:calendar-data', $responseNode)->item(0);

            if (null === $hrefNode || null === $dataNode) {
                continue;
            }

            $etag = null !== $etagNode ? trim((string) $etagNode->textContent, " \t\n\r\0\x0B\"") : '';

            $events[] = [
                'href' => (string) $hrefNode->textContent,
                'etag' => $etag,
                'ical' => (string) $dataNode->textContent,
            ];
        }

        return $events;
    }

    private function eventUrl(string $calendarHref, string $uid): string
    {
        return rtrim($calendarHref, '/').'/'.rawurlencode($uid).'.ics';
    }

    /**
     * @param array<string, string> $headers
     */
    private function extractEtag(array $headers): string
    {
        foreach ($headers as $name => $value) {
            if (strtolower($name) === 'etag') {
                return trim((string) $value, " \t\n\r\0\x0B\"");
            }
        }

        return '';
    }

    private function stringValue(mixed $value): string
    {
        if (\is_string($value)) {
            return $value;
        }
        if ($value instanceof Href) {
            return (string) $value->getHref();
        }
        if (\is_object($value) && method_exists($value, '__toString')) {
            return (string) $value;
        }

        return '';
    }

    private function nullableStringValue(mixed $value): ?string
    {
        $string = $this->stringValue($value);

        return '' === $string ? null : $string;
    }
}
