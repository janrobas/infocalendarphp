<?php

declare(strict_types=1);

namespace App\Calendar;

use RuntimeException;

/**
 * Raised for calendar-level input/validation errors whose message is safe to
 * surface to the client (invalid dates, unknown calendar/event, etc.).
 */
final class CalendarException extends RuntimeException
{
}
