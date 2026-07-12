<?php

declare(strict_types=1);

namespace App\Lessons\Exceptions;

/** Attendance evidence rejected: ineligible lesson/booking state or finalized record. */
class LessonAttendanceException extends LessonException {}
