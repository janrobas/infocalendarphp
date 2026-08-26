<?php

declare(strict_types=1);

namespace App\Calendar;

use DateTimeImmutable;
use DateTimeZone;
use Sabre\VObject\Component;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Reader;

/**
 * Parses and generates iCalendar VEVENT data, preserving fields the
 * application does not understand when updating an existing event.
 */
final class Ics
{
    /**
     * Parse a single-VEVENT iCalendar body into a structured event array.
     *
     * @return array<string, mixed>|null
     */
    public static function parse(string $ical, string $etag, string $calendarId): ?array
    {
        try {
            $vcal = Reader::read($ical);
        } catch (\Throwable) {
            return null;
        }

        $event = $vcal->VEVENT ?? null;
        if (null === $event) {
            return null;
        }

        $uid = isset($event->UID) ? (string) $event->UID : '';

        $allDay = isset($event->DTSTART) && 'DATE' === $event->DTSTART->getValueType();

        $start = isset($event->DTSTART) ? self::formatProperty($event->DTSTART, $allDay) : null;
        $end = null;
        if (isset($event->DTEND)) {
            $end = self::formatProperty($event->DTEND, $allDay);
        } elseif (null !== $start) {
            $end = $allDay
                ? (new DateTimeImmutable($start))->modify('+1 day')->format('Y-m-d')
                : $start;
        }

        $attendees = [];
        foreach ($event->select('ATTENDEE') as $attendee) {
            $email = (string) $attendee;
            if (str_starts_with($email, 'mailto:')) {
                $email = substr($email, 7);
            }
            $attendees[] = [
                'email' => $email,
                'name' => isset($attendee['CN']) ? (string) $attendee['CN'] : '',
                'role' => isset($attendee['ROLE']) ? (string) $attendee['ROLE'] : '',
                'status' => isset($attendee['PARTSTAT']) ? (string) $attendee['PARTSTAT'] : '',
            ];
        }

        $organizer = null;
        if (isset($event->ORGANIZER)) {
            $email = (string) $event->ORGANIZER;
            if (str_starts_with($email, 'mailto:')) {
                $email = substr($email, 7);
            }
            $organizer = [
                'email' => $email,
                'name' => isset($event->ORGANIZER['CN']) ? (string) $event->ORGANIZER['CN'] : '',
            ];
        }

        $recurrence = null;
        if (isset($event->RRULE)) {
            $recurrence['rrule'] = (string) $event->RRULE;
        }
        if (isset($event->{'RECURRENCE-ID'})) {
            $recurrence['recurrence_id'] = (string) $event->{'RECURRENCE-ID'};
        }

        return [
            'id' => $uid,
            'uid' => $uid,
            'calendar_id' => $calendarId,
            'title' => isset($event->SUMMARY) ? (string) $event->SUMMARY : '',
            'description' => isset($event->DESCRIPTION) ? (string) $event->DESCRIPTION : '',
            'start' => $start,
            'end' => $end,
            'location' => isset($event->LOCATION) ? (string) $event->LOCATION : '',
            'status' => isset($event->STATUS) ? (string) $event->STATUS : '',
            'all_day' => $allDay,
            'recurrence' => $recurrence,
            'attendees' => $attendees,
            'organizer' => $organizer,
            'etag' => $etag,
        ];
    }

    /**
     * Build an iCalendar body for a new event.
     *
     * @param array{title: string, start: string, end: string, description?: string, location?: string} $input
     *
     * @return array{uid: string, ical: string}
     */
    public static function create(array $input): array
    {
        $uid = self::newUid();
        $allDay = !str_contains($input['start'], 'T');

        $start = new DateTimeImmutable($input['start']);
        $end = new DateTimeImmutable($input['end']);

        $vcal = new VCalendar();
        $event = $vcal->add('VEVENT');
        $event->UID = $uid;
        $event->DTSTAMP = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $event->add('SEQUENCE', 0);

        if ($allDay) {
            self::addDateProperty($event, 'DTSTART', $start->format('Ymd'));
            self::addDateProperty($event, 'DTEND', $end->format('Ymd'));
        } else {
            $event->add('DTSTART', $start);
            $event->add('DTEND', $end);
        }

        $event->add('SUMMARY', (string) $input['title']);

        if (isset($input['description']) && '' !== $input['description']) {
            $event->add('DESCRIPTION', (string) $input['description']);
        }
        if (isset($input['location']) && '' !== $input['location']) {
            $event->add('LOCATION', (string) $input['location']);
        }

        return ['uid' => $uid, 'ical' => $vcal->serialize()];
    }

    /**
     * Apply a partial update to an existing event, preserving unrelated fields.
     *
     * @param array{title?: string, start?: string, end?: string, description?: string, location?: string} $updates
     */
    public static function merge(string $ical, array $updates): string
    {
        $vcal = Reader::read($ical);
        $event = $vcal->VEVENT;

        if (isset($updates['title'])) {
            self::replace($event, 'SUMMARY', (string) $updates['title']);
        }
        if (array_key_exists('description', $updates)) {
            self::replace($event, 'DESCRIPTION', (string) $updates['description']);
        }
        if (array_key_exists('location', $updates)) {
            self::replace($event, 'LOCATION', (string) $updates['location']);
        }

        if (isset($updates['start']) || isset($updates['end'])) {
            $existingStart = isset($event->DTSTART) ? self::formatProperty($event->DTSTART, 'DATE' === $event->DTSTART->getValueType()) : null;
            $existingEnd = isset($event->DTEND) ? self::formatProperty($event->DTEND, 'DATE' === $event->DTEND->getValueType()) : null;

            $newStart = isset($updates['start']) ? (string) $updates['start'] : $existingStart;
            $newEnd = isset($updates['end']) ? (string) $updates['end'] : $existingEnd;

            if (null !== $newStart) {
                $allDay = !str_contains($newStart, 'T');
                $event->remove('DTSTART');
                $event->remove('DTEND');

                $start = new DateTimeImmutable($newStart);
                if ($allDay) {
                    self::addDateProperty($event, 'DTSTART', $start->format('Ymd'));
                    if (null !== $newEnd) {
                        self::addDateProperty($event, 'DTEND', (new DateTimeImmutable($newEnd))->format('Ymd'));
                    }
                } else {
                    $event->add('DTSTART', $start);
                    if (null !== $newEnd) {
                        $event->add('DTEND', new DateTimeImmutable($newEnd));
                    }
                }
            }
        }

        // Bump SEQUENCE and refresh DTSTAMP so clients detect the change.
        $sequence = isset($event->SEQUENCE) ? (int) (string) $event->SEQUENCE : 0;
        self::replace($event, 'SEQUENCE', $sequence + 1);
        self::replace($event, 'DTSTAMP', new DateTimeImmutable('now', new DateTimeZone('UTC')));

        return $vcal->serialize();
    }

    private static function replace(Component $event, string $name, mixed $value): void
    {
        $event->remove($name);
        $event->add($name, $value);
    }

    private static function addDateProperty(Component $event, string $name, string $value): void
    {
        $property = $event->add($name, $value);
        $property['VALUE'] = 'DATE';
    }

    private static function formatProperty(mixed $property, bool $allDay): string
    {
        $dt = $property->getDateTime();

        return $allDay ? $dt->format('Y-m-d') : $dt->format('c');
    }

    private static function newUid(): string
    {
        return sprintf('%s-%s@infomaniak-mcp', gmdate('Ymd\THis\Z'), bin2hex(random_bytes(8)));
    }
}
