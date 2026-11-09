<?php

namespace App\Services;

use App\Models\ApiKeyModel;

/**
 * Daily rotation policy for API keys that have rotation_days set:
 * - due: the owner (or, for service accounts, the admins) gets one notification a day;
 * - more than ENFORCE_AFTER_DAYS overdue and rotation_enforce on: the key is turned off and its
 *   access tokens end. Rotating it (or an admin turning it back on) restores service.
 */
class ApiKeyRotationService
{
    public const ENFORCE_AFTER_DAYS = 7;

    public function tick(): array
    {
        $db  = \Config\Database::connect();
        $out = ['reminded' => [], 'disabled' => []];
        $now = time();
        $rows = $db->table('api_keys')->where('rotation_days >', 0)->where('status', 'active')->where('revoked_at', null)->get()->getResultArray();
        foreach ($rows as $k) {
            if (! ApiKeyModel::rotationDue($k)) continue;
            $since   = strtotime((string) ($k['rotated_at'] ?: $k['created_at']));
            $overdue = (int) floor(($now - $since) / 86400) - (int) $k['rotation_days'];
            $owner   = ! empty($k['user_id']) ? (int) $k['user_id'] : null;
            if ((int) ($k['rotation_enforce'] ?? 0) === 1 && $overdue > self::ENFORCE_AFTER_DAYS) {
                $db->table('api_keys')->where('id', $k['id'])->update([
                    'status' => 'disabled', 'token_version' => (int) $k['token_version'] + 1, 'updated_at' => date('Y-m-d H:i:s'),
                ]);
                (new NotificationService())->notify($owner, "API key turned off: {$k['name']}",
                    "Its secret was due for rotation {$overdue} days ago and the key's policy turns it off after " . self::ENFORCE_AFTER_DAYS
                    . ' days. Rotate it on the API Keys page, then turn it back on.', 'error', 'security');
                $out['disabled'][] = $k['name'];
                continue;
            }
            if (! empty($k['rotation_notified_at']) && strtotime($k['rotation_notified_at']) > $now - 86400) continue; // one a day
            $db->table('api_keys')->where('id', $k['id'])->update(['rotation_notified_at' => date('Y-m-d H:i:s')]);
            $when = $overdue > 0 ? "{$overdue} day" . ($overdue > 1 ? 's' : '') . ' ago' : 'today';
            $tail = (int) ($k['rotation_enforce'] ?? 0) === 1
                ? ' It will be turned off if it is still not rotated ' . self::ENFORCE_AFTER_DAYS . ' days after that.'
                : '';
            (new NotificationService())->notify($owner, "Rotate API key: {$k['name']}",
                "Its secret was due for rotation {$when} (every {$k['rotation_days']} days). Rotating keeps the old secret working for a grace period so apps can switch.{$tail}",
                'warning', 'security');
            $out['reminded'][] = $k['name'];
        }
        return $out;
    }
}
