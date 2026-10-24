<?php

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Starter\Administration\Audit\Auditor;
use Starter\Administration\Models\AuditEvent;
use Starter\Administration\Settings\SettingsManager;

if (! function_exists('settings')) {
    /**
     * Get the settings manager or a setting value using "dot" notation.
     */
    function settings(?string $key = null, mixed $default = null): mixed
    {
        $settings = app(SettingsManager::class);

        return $key === null ? $settings : $settings->get($key, $default);
    }
}

if (! function_exists('audit')) {
    /**
     * Get the auditor or record an audit event.
     *
     * @param  array<string, mixed>  $properties
     * @return ($action is null ? Auditor : AuditEvent)
     */
    function audit(?string $action = null, ?Model $subject = null, array $properties = [], ?Authenticatable $actor = null): Auditor|AuditEvent
    {
        $auditor = app(Auditor::class);

        return $action === null ? $auditor : $auditor->record($action, $subject, $properties, $actor);
    }
}
