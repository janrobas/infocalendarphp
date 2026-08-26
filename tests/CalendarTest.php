<?php

declare(strict_types=1);

namespace App\Tests;

use App\Calendar\CalDavClient;
use App\Calendar\CalDavException;
use App\Calendar\CalendarException;
use App\Calendar\CalendarService;
use App\Calendar\Ics;
use DateTimeImmutable;
use Sabre\DAV\Client as SabreClient;

final class CalendarTest extends TestCase
{
    public function testListCalendars(): void
    {
        $client = new FakeCalDavClient([
            ['href' => 'https://sync.example.com/cal/user/work/', 'name' => 'Work', 'description' => 'Work calendar', 'color' => '#ff0000'],
            ['href' => 'https://sync.example.com/cal/user/personal/', 'name' => 'Personal', 'description' => '', 'color' => null],
        ]);

        $service = new CalendarService($client);
        $result = $service->listCalendars();

        self::assertCount(2, $result['calendars']);
        self::assertSame('Work', $result['calendars'][0]['name']);
        self::assertSame('#ff0000', $result['calendars'][0]['color']);
        self::assertNotEmpty($result['calendars'][0]['id']);
    }

    public function testGetEventsParsesEvents(): void
    {
        $built = Ics::create([
            'title' => 'Dentist',
            'start' => '2026-08-26T14:00:00Z',
            'end' => '2026-08-26T15:00:00Z',
        ]);

        $href = 'https://sync.example.com/cal/user/work/';
        $client = new FakeCalDavClient([
            ['href' => $href, 'name' => 'Work', 'description' => '', 'color' => null],
        ], [
            $href => [['href' => $href.'dentist.ics', 'etag' => '"e1"', 'ical' => $built['ical']]],
        ]);

        $service = new CalendarService($client);
        $result = $service->getEvents($this->calendarId($href), '2026-08-26T00:00:00Z', '2026-08-27T00:00:00Z');

        self::assertCount(1, $result['events']);
        self::assertSame('Dentist', $result['events'][0]['title']);
        self::assertSame($built['uid'], $result['events'][0]['id']);
    }

    public function testGetEventsUnknownCalendar(): void
    {
        $client = new FakeCalDavClient([]);
        $service = new CalendarService($client);

        $this->expectException(CalendarException::class);
        $this->expectExceptionMessage('Calendar not found.');
        $service->getEvents('nonexistent', '2026-08-26T00:00:00Z', '2026-08-27T00:00:00Z');
    }

    public function testCreateEvent(): void
    {
        $href = 'https://sync.example.com/cal/user/work/';
        $client = new FakeCalDavClient([
            ['href' => $href, 'name' => 'Work', 'description' => '', 'color' => null],
        ]);

        $service = new CalendarService($client);
        $result = $service->createEvent(
            $this->calendarId($href),
            'Lunch',
            '2026-08-26T12:00:00Z',
            '2026-08-26T13:00:00Z',
            'With Alice',
            'Cafe',
        );

        self::assertSame('Lunch', $result['event']['title']);
        self::assertNotEmpty($result['event']['id']);

        self::assertCount(1, $client->created);
        self::assertStringContainsString('SUMMARY:Lunch', $client->created[0]['ical']);
        self::assertStringContainsString('LOCATION:Cafe', $client->created[0]['ical']);
    }

    public function testUpdateEventPreservesFields(): void
    {
        $ical = implode("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Test//EN',
            'BEGIN:VEVENT',
            'UID:event-1',
            'DTSTAMP:20260101T000000Z',
            'DTSTART:20260826T100000Z',
            'DTEND:20260826T110000Z',
            'SUMMARY:Old',
            'DESCRIPTION:keep',
            'RRULE:FREQ=WEEKLY',
            'END:VEVENT',
            'END:VCALENDAR',
        ]);

        $href = 'https://sync.example.com/cal/user/work/';
        $client = new FakeCalDavClient(
            [['href' => $href, 'name' => 'Work', 'description' => '', 'color' => null]],
            [$href => [['href' => $href.'event-1.ics', 'etag' => '"old-etag"', 'ical' => $ical]]],
        );

        $service = new CalendarService($client);
        $result = $service->updateEvent($this->calendarId($href), 'event-1', ['title' => 'New']);

        self::assertSame('New', $result['event']['title']);

        self::assertCount(1, $client->updated);
        self::assertSame('"old-etag"', $client->updated[0]['etag']);
        self::assertStringContainsString('SUMMARY:New', $client->updated[0]['ical']);
        self::assertStringContainsString('DESCRIPTION:keep', $client->updated[0]['ical']);
        self::assertStringContainsString('RRULE:FREQ=WEEKLY', $client->updated[0]['ical']);
        self::assertSame('"new-etag"', $result['event']['etag']);
    }

    public function testUpdateEventNotFound(): void
    {
        $href = 'https://sync.example.com/cal/user/work/';
        $client = new FakeCalDavClient([['href' => $href, 'name' => 'Work', 'description' => '', 'color' => null]]);
        $service = new CalendarService($client);

        $this->expectException(CalendarException::class);
        $this->expectExceptionMessage('Event not found.');
        $service->updateEvent($this->calendarId($href), 'missing', ['title' => 'X']);
    }

    public function testDeleteEvent(): void
    {
        $ical = Ics::create(['title' => 'X', 'start' => '2026-08-26T10:00:00Z', 'end' => '2026-08-26T11:00:00Z'])['ical'];
        $href = 'https://sync.example.com/cal/user/work/';

        $client = new FakeCalDavClient(
            [['href' => $href, 'name' => 'Work', 'description' => '', 'color' => null]],
            [$href => [['href' => $href.'event.ics', 'etag' => '"e"', 'ical' => $ical]]],
        );

        $service = new CalendarService($client);
        $result = $service->deleteEvent($this->calendarId($href), (string) (Ics::parse($ical, '"e"', 'c')['id']));

        self::assertTrue($result['deleted']);
        self::assertCount(1, $client->deleted);
        self::assertSame('"e"', $client->deleted[0]['etag']);
    }

    public function testEtagConflictIsSurfaced(): void
    {
        $ical = Ics::create(['title' => 'X', 'start' => '2026-08-26T10:00:00Z', 'end' => '2026-08-26T11:00:00Z'])['ical'];
        $href = 'https://sync.example.com/cal/user/work/';

        $client = new FakeCalDavClient(
            [['href' => $href, 'name' => 'Work', 'description' => '', 'color' => null]],
            [$href => [['href' => $href.'event.ics', 'etag' => '"stale"', 'ical' => $ical]]],
        );
        $client->conflictOnUpdate = true;

        $service = new CalendarService($client);
        $uid = (string) (Ics::parse($ical, '"e"', 'c')['id']);

        $this->expectException(CalDavException::class);
        $this->expectExceptionMessage('Event was modified by another client.');
        $service->updateEvent($this->calendarId($href), $uid, ['title' => 'Y']);
    }

    public function testFindFreeSlots(): void
    {
        $href = 'https://sync.example.com/cal/user/work/';
        $busy = Ics::create(['title' => 'Busy', 'start' => '2026-08-26T10:00:00Z', 'end' => '2026-08-26T11:00:00Z'])['ical'];

        $client = new FakeCalDavClient(
            [['href' => $href, 'name' => 'Work', 'description' => '', 'color' => null]],
            [$href => [['href' => $href.'busy.ics', 'etag' => '"e"', 'ical' => $busy]]],
        );

        $service = new CalendarService($client);
        $result = $service->findFreeSlots('2026-08-26T09:00:00Z', '2026-08-26T12:00:00Z', 60, $this->calendarId($href));

        self::assertNotEmpty($result['slots']);

        // The 10:00-11:00 busy block must not appear in any free slot.
        foreach ($result['slots'] as $slot) {
            $s = (new DateTimeImmutable($slot['start']))->getTimestamp();
            $e = (new DateTimeImmutable($slot['end']))->getTimestamp();
            self::assertFalse($s >= 10 * 3600 && $s < 11 * 3600, 'Slot overlaps the busy block');
            self::assertGreaterThanOrEqual(3600, $e - $s);
        }
    }

    private function calendarId(string $href): string
    {
        return substr(hash('sha256', $href), 0, 24);
    }
}

final class FakeCalDavClient extends CalDavClient
{
    /** @var list<array<string, mixed>> */
    public array $created = [];

    /** @var list<array<string, mixed>> */
    public array $updated = [];

    /** @var list<array<string, mixed>> */
    public array $deleted = [];

    public bool $conflictOnUpdate = false;

    /**
     * @param list<array<string, mixed>>                       $calendars
     * @param array<string, list<array<string, mixed>>>        $events
     */
    public function __construct(private readonly array $calendars, private readonly array $events = [])
    {
        parent::__construct(new SabreClient(['baseUri' => 'https://sync.example.com/']));
    }

    public function discoverCalendars(): array
    {
        return $this->calendars;
    }

    public function getEvents(string $calendarHref, DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        return $this->events[$calendarHref] ?? [];
    }

    public function getEventByUid(string $calendarHref, string $uid): ?array
    {
        foreach ($this->events[$calendarHref] ?? [] as $event) {
            $parsed = Ics::parse($event['ical'], $event['etag'], 'c');
            if (null !== $parsed && $parsed['uid'] === $uid) {
                return $event;
            }
        }

        return null;
    }

    public function createEvent(string $calendarHref, string $uid, string $ical): array
    {
        $this->created[] = ['href' => $calendarHref.$uid.'.ics', 'etag' => '"new-etag"', 'ical' => $ical];

        return ['href' => $calendarHref.$uid.'.ics', 'etag' => '"new-etag"'];
    }

    public function updateEvent(string $href, string $etag, string $ical): string
    {
        if ($this->conflictOnUpdate) {
            throw CalDavException::forStatus(412);
        }

        $this->updated[] = ['href' => $href, 'etag' => $etag, 'ical' => $ical];

        return '"new-etag"';
    }

    public function deleteEvent(string $href, string $etag): void
    {
        $this->deleted[] = ['href' => $href, 'etag' => $etag];
    }
}
