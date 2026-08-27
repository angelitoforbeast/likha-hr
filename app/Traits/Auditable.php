<?php

namespace App\Traits;

use App\Models\AuditLog;

/**
 * Attach to any Eloquent model to auto-record create / update / delete
 * events into the audit_logs table.
 *
 * Skips writes when there is no authenticated user (console, seeder, etc.).
 * Skips update events where nothing meaningful changed (only timestamps).
 */
trait Auditable
{
    /**
     * Fields that should NEVER be logged (noise or huge payloads).
     * Override on the model by defining $auditExclude.
     */
    protected static array $auditExcludeDefault = [
        'created_at', 'updated_at', 'remember_token', 'password',
    ];

    public static function bootAuditable(): void
    {
        static::created(function ($model) {
            self::writeAudit('created', $model, null, $model->getAttributes());
        });

        static::updated(function ($model) {
            $changes = $model->getChanges();
            $excluded = self::excludedFields($model);
            foreach ($excluded as $field) {
                unset($changes[$field]);
            }
            if (empty($changes)) {
                return;
            }
            $originals = [];
            foreach (array_keys($changes) as $key) {
                $originals[$key] = $model->getOriginal($key);
            }
            self::writeAudit('updated', $model, $originals, $changes);
        });

        static::deleted(function ($model) {
            self::writeAudit('deleted', $model, $model->getOriginal(), null);
        });
    }

    protected static function excludedFields($model): array
    {
        $modelExcluded = property_exists($model, 'auditExclude') ? $model->auditExclude : [];
        return array_merge(self::$auditExcludeDefault, $modelExcluded);
    }

    protected static function writeAudit(string $action, $model, ?array $old, ?array $new): void
    {
        // Skip when there is no auth context (artisan commands, seeders, etc.)
        if (!auth()->check()) {
            return;
        }

        // Clean out excluded fields from old/new values.
        $excluded = self::excludedFields($model);
        if (is_array($old)) {
            foreach ($excluded as $field) unset($old[$field]);
        }
        if (is_array($new)) {
            foreach ($excluded as $field) unset($new[$field]);
        }

        try {
            AuditLog::create([
                'user_id'        => auth()->id(),
                'action'         => $action,
                'auditable_type' => get_class($model),
                'auditable_id'   => $model->getKey(),
                'old_values'     => $old,
                'new_values'     => $new,
                'ip_address'     => request()?->ip(),
                'description'    => self::describeModel($model),
            ]);
        } catch (\Throwable $e) {
            // Never let auditing break the primary write.
            \Log::warning('Audit log failed: ' . $e->getMessage());
        }
    }

    protected static function describeModel($model): string
    {
        $class = class_basename(get_class($model));
        $name = $model->name ?? $model->full_name ?? $model->display_name ?? ('#' . $model->getKey());
        return "{$class}: {$name}";
    }
}
