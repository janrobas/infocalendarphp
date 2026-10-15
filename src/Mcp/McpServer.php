<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Calendar\CalDavException;
use App\Calendar\CalendarException;
use App\Calendar\CalendarService;
use App\Mail\MailException;
use App\Mail\MailService;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server;
use Mcp\Server\Session\FileSessionStore;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Builds the MCP server and registers the calendar tools, plus the read-only
 * mail tools when a mailbox is configured.
 */
final class McpServer
{
    public static function build(CalendarService $calendar, ?MailService $mail, string $sessionDir, ?LoggerInterface $logger = null): Server
    {
        $builder = Server::builder()
            ->setLogger($logger ?? new NullLogger())
            ->setServerInfo(
                name: 'infomaniak',
                version: '1.1.0',
                title: 'Infomaniak Calendar & Mail',
                description: 'Access the user\'s Infomaniak calendars and mailbox: list calendars, read, search, create, update and delete events, find free time slots, and read/search email (mail is read-only).',
            )
            ->setInstructions('The user\'s Infomaniak calendar and mailbox. Use list_calendars first to obtain a calendar_id, then use the other calendar tools. Use list_mailboxes first to obtain a mailbox path, then use the mail tools. Dates and times are ISO 8601 strings. Read-only tools never change anything. Calendar read-only tools: list_calendars, get_events, search_events, find_free_slots. Calendar mutating tools: create_event, update_event, delete_event. Mail tools are read-only: list_mailboxes, list_emails, search_emails, get_email.')
            ->setSession(new FileSessionStore($sessionDir))
            ->addTool(
                handler: static fn (): array => self::guard(static fn (): array => $calendar->listCalendars()),
                name: 'list_calendars',
                description: 'List all calendars available in the connected Infomaniak account. Returns each calendar\'s id, name, description and color. Use this first to discover the calendar_id required by the other tools. This tool only reads data.',
                annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: true),
                inputSchema: [
                    'type' => 'object',
                    'properties' => [],
                ],
            )
            ->addTool(
                handler: static fn (string $calendar_id, string $start, string $end): array => self::guard(
                    static fn (): array => $calendar->getEvents($calendar_id, $start, $end),
                ),
                name: 'get_events',
                description: 'Retrieve events from a calendar within a date/time range. Returns structured event objects (title, start, end, location, attendees, recurrence, etc.). Use this to read what is scheduled for a period. This tool only reads data and does not modify the calendar.',
                annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: true),
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'calendar_id' => ['type' => 'string', 'description' => 'The calendar ID, as returned by list_calendars.'],
                        'start' => ['type' => 'string', 'description' => 'Start of the range as an ISO 8601 timestamp, e.g. 2026-08-26T00:00:00Z.'],
                        'end' => ['type' => 'string', 'description' => 'End of the range as an ISO 8601 timestamp, e.g. 2026-08-27T00:00:00Z.'],
                    ],
                    'required' => ['calendar_id', 'start', 'end'],
                ],
            )
            ->addTool(
                handler: static fn (string $query, string $start, string $end, ?string $calendar_id = null): array => self::guard(
                    static fn (): array => $calendar->searchEvents($query, $start, $end, $calendar_id),
                ),
                name: 'search_events',
                description: 'Search for events by a text query within a date/time range. Matches against event titles, descriptions and locations (case-insensitive). Use this to find events the user mentions by name, keyword, place or subject. This tool only reads data.',
                annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: true),
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => 'The text to search for in event titles, descriptions and locations.'],
                        'start' => ['type' => 'string', 'description' => 'Start of the range as an ISO 8601 timestamp.'],
                        'end' => ['type' => 'string', 'description' => 'End of the range as an ISO 8601 timestamp.'],
                        'calendar_id' => ['type' => 'string', 'description' => 'Optional calendar ID to limit the search. When omitted, all calendars are searched.'],
                    ],
                    'required' => ['query', 'start', 'end'],
                ],
            )
            ->addTool(
                handler: static fn (string $start, string $end, int $duration_minutes, ?string $calendar_id = null): array => self::guard(
                    static fn (): array => $calendar->findFreeSlots($start, $end, $duration_minutes, $calendar_id),
                ),
                name: 'find_free_slots',
                description: 'Find periods when the calendar is free within a specified date/time range, each at least duration_minutes long. Use this when the user asks when they are available or asks you to find a suitable meeting time. This tool does not create or modify any event.',
                annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: true),
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'start' => ['type' => 'string', 'description' => 'Start of the window to search, as an ISO 8601 timestamp.'],
                        'end' => ['type' => 'string', 'description' => 'End of the window to search, as an ISO 8601 timestamp.'],
                        'duration_minutes' => ['type' => 'integer', 'description' => 'Minimum free period length, in minutes.'],
                        'calendar_id' => ['type' => 'string', 'description' => 'Optional calendar ID. When omitted, free time across all calendars is considered.'],
                    ],
                    'required' => ['start', 'end', 'duration_minutes'],
                ],
            )
            ->addTool(
                handler: static function (string $calendar_id, string $title, string $start, string $end, ?string $description = null, ?string $location = null) use ($calendar): array {
                    return self::guard(static fn (): array => $calendar->createEvent($calendar_id, $title, $start, $end, $description, $location));
                },
                name: 'create_event',
                description: 'Create a new event on a calendar. This modifies the user\'s calendar. Provide the calendar_id, a title, and start/end times in ISO 8601; description and location are optional. Returns the created event including its ID.',
                annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, openWorldHint: true),
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'calendar_id' => ['type' => 'string', 'description' => 'The calendar ID, as returned by list_calendars.'],
                        'title' => ['type' => 'string', 'description' => 'The event title.'],
                        'start' => ['type' => 'string', 'description' => 'Start time as an ISO 8601 timestamp.'],
                        'end' => ['type' => 'string', 'description' => 'End time as an ISO 8601 timestamp.'],
                        'description' => ['type' => 'string', 'description' => 'Optional event description.'],
                        'location' => ['type' => 'string', 'description' => 'Optional event location.'],
                    ],
                    'required' => ['calendar_id', 'title', 'start', 'end'],
                ],
            )
            ->addTool(
                handler: static function (string $calendar_id, string $event_id, ?string $title = null, ?string $start = null, ?string $end = null, ?string $description = null, ?string $location = null) use ($calendar): array {
                    $updates = [];
                    if (null !== $title) {
                        $updates['title'] = $title;
                    }
                    if (null !== $start) {
                        $updates['start'] = $start;
                    }
                    if (null !== $end) {
                        $updates['end'] = $end;
                    }
                    if (null !== $description) {
                        $updates['description'] = $description;
                    }
                    if (null !== $location) {
                        $updates['location'] = $location;
                    }

                    return self::guard(static fn (): array => $calendar->updateEvent($calendar_id, $event_id, $updates));
                },
                name: 'update_event',
                description: 'Update an existing event on a calendar. This modifies the user\'s calendar. Only the fields you provide are changed; all other event fields are preserved. Provide the calendar_id and the event_id (the event\'s ID as returned by get_events/search_events).',
                annotations: new ToolAnnotations(readOnlyHint: false, openWorldHint: true),
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'calendar_id' => ['type' => 'string', 'description' => 'The calendar ID, as returned by list_calendars.'],
                        'event_id' => ['type' => 'string', 'description' => 'The ID of the event to update, as returned by get_events or search_events.'],
                        'title' => ['type' => 'string', 'description' => 'Optional new title.'],
                        'start' => ['type' => 'string', 'description' => 'Optional new start time as an ISO 8601 timestamp.'],
                        'end' => ['type' => 'string', 'description' => 'Optional new end time as an ISO 8601 timestamp.'],
                        'description' => ['type' => 'string', 'description' => 'Optional new description.'],
                        'location' => ['type' => 'string', 'description' => 'Optional new location.'],
                    ],
                    'required' => ['calendar_id', 'event_id'],
                ],
            )
            ->addTool(
                handler: static fn (string $calendar_id, string $event_id): array => self::guard(
                    static fn (): array => $calendar->deleteEvent($calendar_id, $event_id),
                ),
                name: 'delete_event',
                description: 'Permanently delete an event from a calendar. This is destructive and cannot be undone. Only call this when the user explicitly asks to delete or cancel an event. Provide the calendar_id and event_id.',
                annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, openWorldHint: true),
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'calendar_id' => ['type' => 'string', 'description' => 'The calendar ID, as returned by list_calendars.'],
                        'event_id' => ['type' => 'string', 'description' => 'The ID of the event to delete, as returned by get_events or search_events.'],
                    ],
                    'required' => ['calendar_id', 'event_id'],
                ],
            );

        if (null !== $mail) {
            self::registerMailTools($builder, $mail);
        }

        return $builder->build();
    }

    /**
     * Register the read-only mail tools. Kept separate so they are only added
     * when the mailbox is configured.
     */
    private static function registerMailTools(\Mcp\Server\Builder $builder, MailService $mail): void
    {
        $builder
            ->addTool(
                handler: static fn (): array => self::guardMail(static fn (): array => $mail->listMailboxes()),
                name: 'list_mailboxes',
                description: 'List all mailboxes (folders) in the connected Infomaniak mailbox. Returns each mailbox\'s name and path. Use this first to obtain the mailbox required by the other mail tools: pass the returned path as the `mailbox` argument. This tool only reads data.',
                annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: true),
                inputSchema: [
                    'type' => 'object',
                    'properties' => [],
                ],
            )
            ->addTool(
                handler: static fn (?string $mailbox = null, ?string $start = null, ?string $end = null, ?int $limit = null, bool $unread_only = false): array => self::guardMail(
                    static fn (): array => $mail->listEmails($mailbox, $start, $end, $limit, $unread_only),
                ),
                name: 'list_emails',
                description: 'List the most recent emails in a mailbox, newest first. Returns message headers (uid, subject, from, to, date, seen, flagged, has_attachments), not bodies. Use get_email with a uid to read a message. This tool only reads data and never marks messages as read.',
                annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: true),
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'mailbox' => ['type' => 'string', 'description' => 'Optional mailbox path, as returned by list_mailboxes (the readable name is accepted too). Defaults to INBOX.'],
                        'start' => ['type' => 'string', 'description' => 'Optional start of the date range (ISO 8601). Messages on or after this date.'],
                        'end' => ['type' => 'string', 'description' => 'Optional end of the date range (ISO 8601, exclusive). Messages strictly before this date.'],
                        'limit' => ['type' => 'integer', 'description' => 'Optional maximum number of messages to return (capped server-side).'],
                        'unread_only' => ['type' => 'boolean', 'description' => 'When true, return only unread messages. Defaults to false.'],
                    ],
                ],
            )
            ->addTool(
                handler: static fn (string $query, ?string $mailbox = null, ?string $start = null, ?string $end = null, ?string $from = null, ?string $subject = null, ?int $limit = null, bool $unread_only = false): array => self::guardMail(
                    static fn (): array => $mail->searchEmails($query, $mailbox, $start, $end, $from, $subject, $limit, $unread_only),
                ),
                name: 'search_emails',
                description: 'Search emails by text (matches sender, recipients, subject and body), or by from/subject, optionally within a date range and mailbox. Returns message headers, not bodies. Use get_email with a uid to read a message. This tool only reads data.',
                annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: true),
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => 'The text to search for across the message. May be empty when from and/or subject are provided.'],
                        'mailbox' => ['type' => 'string', 'description' => 'Optional mailbox path, as returned by list_mailboxes (the readable name is accepted too). Defaults to INBOX.'],
                        'start' => ['type' => 'string', 'description' => 'Optional start of the date range (ISO 8601).'],
                        'end' => ['type' => 'string', 'description' => 'Optional end of the date range (ISO 8601, exclusive).'],
                        'from' => ['type' => 'string', 'description' => 'Optional sender to match.'],
                        'subject' => ['type' => 'string', 'description' => 'Optional subject substring to match.'],
                        'limit' => ['type' => 'integer', 'description' => 'Optional maximum number of messages to return (capped server-side).'],
                        'unread_only' => ['type' => 'boolean', 'description' => 'When true, return only unread messages. Defaults to false.'],
                    ],
                    'required' => ['query'],
                ],
            )
            ->addTool(
                handler: static fn (string $uid, ?string $mailbox = null, ?int $max_chars = null): array => self::guardMail(
                    static fn (): array => $mail->getEmail($uid, $mailbox, $max_chars),
                ),
                name: 'get_email',
                description: 'Retrieve a single email by its uid, including a plain-text version of the body (truncated) and the list of attachment names. Provide the uid from list_emails or search_emails. This tool only reads data and never marks the message as read.',
                annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: true),
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'uid' => ['type' => 'string', 'description' => 'The message uid, as returned by list_emails or search_emails.'],
                        'mailbox' => ['type' => 'string', 'description' => 'Optional mailbox path, as returned by list_mailboxes (the readable name is accepted too). Defaults to INBOX.'],
                        'max_chars' => ['type' => 'integer', 'description' => 'Optional maximum number of body characters to return.'],
                    ],
                    'required' => ['uid'],
                ],
            );
    }

    /**
     * Convert calendar domain exceptions into MCP tool errors whose message is
     * safe to return to the client.
     *
     * @return array<string, mixed>
     */
    private static function guard(callable $fn): array
    {
        try {
            return $fn();
        } catch (CalendarException|CalDavException $e) {
            throw new ToolCallException($e->getMessage());
        }
    }

    /**
     * Convert mail domain exceptions into MCP tool errors whose message is safe
     * to return to the client.
     *
     * @return array<string, mixed>
     */
    private static function guardMail(callable $fn): array
    {
        try {
            return $fn();
        } catch (MailException $e) {
            throw new ToolCallException($e->getMessage());
        }
    }
}
