<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Calendar\CalDavException;
use App\Calendar\CalendarException;
use App\Calendar\CalendarService;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server;
use Mcp\Server\Session\FileSessionStore;

/**
 * Builds the MCP server and registers the calendar tools.
 */
final class McpServer
{
    public static function build(CalendarService $calendar, string $sessionDir): Server
    {
        return Server::builder()
            ->setServerInfo(
                name: 'infomaniak-calendar',
                version: '1.0.0',
                title: 'Infomaniak Calendar',
                description: 'Access the user\'s Infomaniak calendars: list calendars, read, search, create, update and delete events, and find free time slots.',
            )
            ->setInstructions('The user\'s Infomaniak calendar. Use list_calendars first to obtain a calendar_id, then use the other tools. Dates and times are ISO 8601 strings. Read-only tools: list_calendars, get_events, search_events, find_free_slots. Mutating tools: create_event, update_event, delete_event.')
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
            )
            ->build();
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
}
