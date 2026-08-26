<?php

declare(strict_types=1);

namespace App\Tests;

use App\Calendar\Ics;

final class IcsTest extends TestCase
{
    public function testCreateAndParseTimedEvent(): void
    {
        $built = Ics::create([
            'title' => 'Team standup',
            'start' => '2026-08-26T09:00:00Z',
            'end' => '2026-08-26T09:30:00Z',
            'description' => 'Daily sync',
            'location' => 'Room 1',
        ]);

        $event = Ics::parse($built['ical'], 'etag123', 'cal-id');

        self::assertNotNull($event);
        self::assertSame($built['uid'], $event['id']);
        self::assertSame('Team standup', $event['title']);
        self::assertSame('Daily sync', $event['description']);
        self::assertSame('Room 1', $event['location']);
        self::assertFalse($event['all_day']);
        self::assertStringStartsWith('2026-08-26T09:00:00', $event['start']);
        self::assertStringStartsWith('2026-08-26T09:30:00', $event['end']);
        self::assertSame('etag123', $event['etag']);
        self::assertSame('cal-id', $event['calendar_id']);
    }

    public function testCreateAndParseAllDayEvent(): void
    {
        $built = Ics::create([
            'title' => 'Conference',
            'start' => '2026-08-26',
            'end' => '2026-08-27',
        ]);

        $event = Ics::parse($built['ical'], '"etag"', 'cal-id');

        self::assertNotNull($event);
        self::assertTrue($event['all_day']);
        self::assertSame('2026-08-26', $event['start']);
        self::assertSame('2026-08-27', $event['end']);
    }

    public function testMergePreservesUnrelatedFields(): void
    {
        $ical = implode("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Test//EN',
            'BEGIN:VEVENT',
            'UID:test-uid',
            'DTSTAMP:20260101T000000Z',
            'DTSTART:20260826T100000Z',
            'DTEND:20260826T110000Z',
            'SUMMARY:Original title',
            'DESCRIPTION:Keep me',
            'RRULE:FREQ=DAILY',
            'SEQUENCE:2',
            'END:VEVENT',
            'END:VCALENDAR',
        ]);

        $merged = Ics::merge($ical, ['title' => 'New title']);

        self::assertStringContainsString('SUMMARY:New title', $merged);
        self::assertStringContainsString('DESCRIPTION:Keep me', $merged);
        self::assertStringContainsString('RRULE:FREQ=DAILY', $merged);
        self::assertStringContainsString('SEQUENCE:3', $merged);
        self::assertStringContainsString('UID:test-uid', $merged);

        // The merged output must still be valid iCalendar.
        $event = Ics::parse($merged, '"e"', 'c');
        self::assertNotNull($event);
        self::assertSame('New title', $event['title']);
        self::assertSame('Keep me', $event['description']);
        self::assertSame('FREQ=DAILY', $event['recurrence']['rrule']);
    }

    public function testMergeUpdatesDatesAndPreservesUid(): void
    {
        $ical = implode("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Test//EN',
            'BEGIN:VEVENT',
            'UID:abc',
            'DTSTAMP:20260101T000000Z',
            'DTSTART:20260826T100000Z',
            'DTEND:20260826T110000Z',
            'SUMMARY:X',
            'END:VEVENT',
            'END:VCALENDAR',
        ]);

        $merged = Ics::merge($ical, ['start' => '2026-08-27T11:00:00Z', 'end' => '2026-08-27T12:00:00Z']);

        self::assertStringContainsString('UID:abc', $merged);
        self::assertStringContainsString('SUMMARY:X', $merged);

        $event = Ics::parse($merged, '"e"', 'c');
        self::assertNotNull($event);
        self::assertStringStartsWith('2026-08-27T11:00:00', $event['start']);
    }
}
