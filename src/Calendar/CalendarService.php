<?php

declare(strict_types=1);

namespace App\Calendar;

use DateTimeImmutable;

/**
 * High-level calendar operations. All data is read live from Infomaniak; no
 * events are cached locally.
 */
final class CalendarService
{
    public function __construct(private readonly CalDavClient $client)
    {
    }

    /**
     * @return array{calendars: list<array<string, mixed>>}
     */
    public function listCalendars(): array
    {
        $calendars = [];
        foreach ($this->client->discoverCalendars() as $cal) {
            $calendars[] = [
                'id' => $this->calendarId($cal['href']),
                'name' => $cal['name'],
                'description' => $cal['description'],
                'color' => $cal['color'],
            ];
        }

        return ['calendars' => $calendars];
    }

    /**
     * @return array{events: list<array<string, mixed>>}
     */
    public function getEvents(string $calendarId, string $start, string $end): array
    {
        [$startDt, $endDt] = $this->parseRange($start, $end);
        $href = $this->resolveCalendar($calendarId);

        $events = [];
        foreach ($this->client->getEvents($href, $startDt, $endDt) as $raw) {
            $event = Ics::parse($raw['ical'], $raw['etag'], $calendarId);
            if (null !== $event) {
                $events[] = $event;
            }
        }

        return ['events' => $events];
    }

    /**
     * @return array{events: list<array<string, mixed>>}
     */
    public function searchEvents(string $query, string $start, string $end, ?string $calendarId): array
    {
        $needle = strtolower(trim($query));
        if ('' === $needle) {
            return ['events' => []];
        }

        [$startDt, $endDt] = $this->parseRange($start, $end);

        $hrefs = null !== $calendarId
            ? [$this->resolveCalendar($calendarId)]
            : array_map(static fn (array $cal): string => $cal['href'], $this->client->discoverCalendars());

        $results = [];
        foreach ($hrefs as $href) {
            $cid = $this->calendarId($href);
            foreach ($this->client->getEvents($href, $startDt, $endDt) as $raw) {
                $event = Ics::parse($raw['ical'], $raw['etag'], $cid);
                if (null === $event) {
                    continue;
                }

                $haystack = strtolower(implode(' ', [
                    (string) $event['title'],
                    (string) $event['description'],
                    (string) $event['location'],
                ]));

                if (str_contains($haystack, $needle)) {
                    $results[] = $event;
                }
            }
        }

        return ['events' => $results];
    }

    /**
     * @return array{slots: list<array<string, mixed>>}
     */
    public function findFreeSlots(string $start, string $end, int $durationMinutes, ?string $calendarId): array
    {
        if ($durationMinutes < 1) {
            throw new CalendarException('Invalid duration_minutes: must be at least 1.');
        }

        [$rangeStart, $rangeEnd] = $this->parseRange($start, $end);

        $hrefs = null !== $calendarId
            ? [$this->resolveCalendar($calendarId)]
            : array_map(static fn (array $cal): string => $cal['href'], $this->client->discoverCalendars());

        $busy = [];
        foreach ($hrefs as $href) {
            foreach ($this->client->getEvents($href, $rangeStart, $rangeEnd) as $raw) {
                $event = Ics::parse($raw['ical'], $raw['etag'], $this->calendarId($href));
                if (null === $event || null === $event['start'] || null === $event['end']) {
                    continue;
                }

                $busy[] = $this->interval($event['start'], $event['end'], $rangeStart, $rangeEnd);
            }
        }

        $merged = $this->mergeIntervals($busy);

        $slots = [];
        $cursor = $rangeStart->getTimestamp();
        $rangeEndTs = $rangeEnd->getTimestamp();

        foreach ($merged as [$bs, $be]) {
            $bs = max($bs, $rangeStart->getTimestamp());
            $be = min($be, $rangeEndTs);

            if ($bs - $cursor >= $durationMinutes * 60) {
                $slots[] = $this->slot($cursor, $bs);
            }
            $cursor = max($cursor, $be);

            if (\count($slots) >= 20) {
                return ['slots' => $slots];
            }
        }

        if ($rangeEndTs - $cursor >= $durationMinutes * 60) {
            $slots[] = $this->slot($cursor, $rangeEndTs);
        }

        return ['slots' => $slots];
    }

    /**
     * @return array{event: array<string, mixed>}
     */
    public function createEvent(
        string $calendarId,
        string $title,
        string $start,
        string $end,
        ?string $description,
        ?string $location,
    ): array {
        $this->assertValidRange($start, $end);

        $href = $this->resolveCalendar($calendarId);

        $built = Ics::create([
            'title' => $title,
            'start' => $start,
            'end' => $end,
            'description' => $description ?? '',
            'location' => $location ?? '',
        ]);

        $created = $this->client->createEvent($href, $built['uid'], $built['ical']);

        return ['event' => $this->describeCreated($built['uid'], $calendarId, $title, $start, $end, $description, $location, $created['etag'])];
    }

    /**
     * @param array<string, mixed> $updates
     *
     * @return array{event: array<string, mixed>}
     */
    public function updateEvent(string $calendarId, string $eventId, array $updates): array
    {
        $href = $this->resolveCalendar($calendarId);

        $raw = $this->client->getEventByUid($href, $eventId);
        if (null === $raw) {
            throw new CalendarException('Event not found.');
        }

        if (isset($updates['start'], $updates['end'])) {
            $this->assertValidRange($updates['start'], $updates['end']);
        }

        $merged = Ics::merge($raw['ical'], $updates);
        $newEtag = $this->client->updateEvent($raw['href'], $raw['etag'], $merged);

        $event = Ics::parse($merged, $newEtag, $calendarId);

        return ['event' => $event];
    }

    /**
     * @return array{deleted: bool, event_id: string}
     */
    public function deleteEvent(string $calendarId, string $eventId): array
    {
        $href = $this->resolveCalendar($calendarId);

        $raw = $this->client->getEventByUid($href, $eventId);
        if (null === $raw) {
            throw new CalendarException('Event not found.');
        }

        $this->client->deleteEvent($raw['href'], $raw['etag']);

        return ['deleted' => true, 'event_id' => $eventId];
    }

    // ---- internals ----

    private function calendarId(string $href): string
    {
        return substr(hash('sha256', $href), 0, 24);
    }

    private function resolveCalendar(string $id): string
    {
        foreach ($this->client->discoverCalendars() as $cal) {
            if (hash_equals($this->calendarId($cal['href']), $id)) {
                return $cal['href'];
            }
        }

        throw new CalendarException('Calendar not found.');
    }

    /**
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}
     */
    private function parseRange(string $start, string $end): array
    {
        try {
            $startDt = new DateTimeImmutable($start);
            $endDt = new DateTimeImmutable($end);
        } catch (\Exception) {
            throw new CalendarException('Invalid date range: dates must be ISO 8601.');
        }

        if ($startDt->getTimestamp() > $endDt->getTimestamp()) {
            throw new CalendarException('Invalid date range: start must be before end.');
        }

        return [$startDt, $endDt];
    }

    private function assertValidRange(string $start, string $end): void
    {
        $this->parseRange($start, $end);
    }

    /**
     * @return array{0: int, 1: int} start/end timestamps clamped to the range
     */
    private function interval(string $start, string $end, DateTimeImmutable $rangeStart, DateTimeImmutable $rangeEnd): array
    {
        try {
            $s = (new DateTimeImmutable($start))->getTimestamp();
            $e = (new DateTimeImmutable($end))->getTimestamp();
        } catch (\Exception) {
            return [0, 0];
        }

        $s = max($s, $rangeStart->getTimestamp());
        $e = min($e, $rangeEnd->getTimestamp());

        if ($e <= $s) {
            return [0, 0];
        }

        return [$s, $e];
    }

    /**
     * @param list<array{0: int, 1: int}> $intervals
     *
     * @return list<array{0: int, 1: int}>
     */
    private function mergeIntervals(array $intervals): array
    {
        $intervals = array_values(array_filter($intervals, static fn (array $i): bool => $i[1] > 0));

        usort($intervals, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $merged = [];
        foreach ($intervals as [$s, $e]) {
            if ([] === $merged) {
                $merged[] = [$s, $e];
                continue;
            }

            $last = \count($merged) - 1;
            if ($s <= $merged[$last][1]) {
                $merged[$last][1] = max($merged[$last][1], $e);
            } else {
                $merged[] = [$s, $e];
            }
        }

        return $merged;
    }

    /**
     * @return array<string, mixed>
     */
    private function slot(int $startTs, int $endTs): array
    {
        return [
            'start' => (new DateTimeImmutable('@'.$startTs))->setTimezone(new \DateTimeZone('UTC'))->format('c'),
            'end' => (new DateTimeImmutable('@'.$endTs))->setTimezone(new \DateTimeZone('UTC'))->format('c'),
            'duration_minutes' => (int) round(($endTs - $startTs) / 60),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function describeCreated(
        string $uid,
        string $calendarId,
        string $title,
        string $start,
        string $end,
        ?string $description,
        ?string $location,
        string $etag,
    ): array {
        $allDay = !str_contains($start, 'T');

        return [
            'id' => $uid,
            'uid' => $uid,
            'calendar_id' => $calendarId,
            'title' => $title,
            'description' => $description ?? '',
            'start' => $start,
            'end' => $end,
            'location' => $location ?? '',
            'status' => '',
            'all_day' => $allDay,
            'recurrence' => null,
            'attendees' => [],
            'organizer' => null,
            'etag' => $etag,
        ];
    }
}
