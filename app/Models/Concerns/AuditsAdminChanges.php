<?php

declare(strict_types=1);

namespace App\Models\Concerns;

/**
 * Audit-log entries for panel writes that don't go through an Action
 * (lead deletes, lead notes) (PLAN §7: every admin action is audited).
 * Records who, what and which fields, never the values: these records
 * hold personal data, and the audit log must not become a copy of it.
 *
 * The model lists the events in auditedEvents(): any of created, updated,
 * deleted.
 */
trait AuditsAdminChanges
{
    public static function bootAuditsAdminChanges(): void
    {
        foreach (['created', 'updated', 'deleted'] as $event) {
            static::registerModelEvent($event, function (self $model) use ($event): void {
                if (! in_array($event, $model->auditedEvents(), true)) {
                    return;
                }

                $changed = $event === 'updated'
                    ? array_values(array_diff(array_keys($model->getChanges()), ['updated_at']))
                    : [];

                if ($event === 'updated' && $changed === []) {
                    return;
                }

                activity($model->auditLogName())
                    ->performedOn($model)
                    ->event($event)
                    ->withProperties($changed === [] ? [] : ['fields' => $changed])
                    ->log(class_basename($model).' '.$event);
            });
        }
    }

    /**
     * @return list<'created'|'updated'|'deleted'>
     */
    abstract public function auditedEvents(): array;

    public function auditLogName(): string
    {
        return 'admin';
    }
}
