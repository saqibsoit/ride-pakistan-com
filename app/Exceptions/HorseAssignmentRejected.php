<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/** A horse can't take this rider (AssignHorse, milestone 10b). The message is shown to staff. */
final class HorseAssignmentRejected extends RuntimeException {}
