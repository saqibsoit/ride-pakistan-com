<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Support\Collection;
use Spatie\Activitylog\Contracts\Activity as ActivityContract;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Audit log for records holding personal data (users, riders). Logs
 * which attributes changed and their values, except the ones listed in
 * redactedActivityAttributes(), whose values are replaced so the audit
 * log never becomes a copy of sensitive data (PLAN §7).
 */
trait LogsPersonalDataChanges
{
    use LogsActivity;

    public const REDACTED = '[redacted]';

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('people')
            ->logOnly($this->loggedActivityAttributes())
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function tapActivity(ActivityContract $activity, string $eventName): void
    {
        if (! $activity instanceof Activity || ! $activity->properties instanceof Collection) {
            return;
        }

        $properties = $activity->properties;

        foreach (['attributes', 'old'] as $set) {
            $values = $properties->get($set);

            if (! is_array($values)) {
                continue;
            }

            foreach ($this->redactedActivityAttributes() as $attribute) {
                if (array_key_exists($attribute, $values)) {
                    $values[$attribute] = self::REDACTED;
                }
            }

            $properties = $properties->put($set, $values);
        }

        $activity->properties = $properties;
    }

    /**
     * @return list<string>
     */
    abstract protected function loggedActivityAttributes(): array;

    /**
     * @return list<string>
     */
    protected function redactedActivityAttributes(): array
    {
        return [];
    }
}
