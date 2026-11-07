<?php

declare(strict_types=1);

namespace App\Policies;

/** The audit log: admins read it; nobody edits or deletes it. */
final class ActivityPolicy extends AdminReadOnlyPolicy {}
