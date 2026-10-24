<?php

namespace App\Controllers\Api;

use App\Services\SettingsService;

/**
 * Settings — grouped configuration.
 *
 *   GET /api/settings/{group}   read a group (secrets masked)   (settings.view)
 *   PUT /api/settings/{group}   save a group                    (settings.manage)
 *
 * Groups: general, ai, smtp, storage, notifications.
 */
class SettingsController extends BaseApiController
{
    private const GROUPS = ['general', 'ai', 'smtp', 'storage', 'notifications', 'gateway'];

    public function show($group = null)
    {
        if ($r = $this->requirePermission('settings.view')) return $r;
        if (! in_array($group, self::GROUPS, true)) return $this->failure('Unknown settings group.', 404);
        return $this->ok((new SettingsService())->group($group));
    }

    public function save($group = null)
    {
        if ($r = $this->requirePermission('settings.manage')) return $r;
        if (! in_array($group, self::GROUPS, true)) return $this->failure('Unknown settings group.', 404);
        (new SettingsService())->save($group, $this->input());
        return $this->ok((new SettingsService())->group($group), 'Settings saved.');
    }
}
