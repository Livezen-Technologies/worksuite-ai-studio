<?php

namespace App\Controllers\Api;

use App\Services\NotificationService;

/**
 * Notifications — in-app feed for the current user + admin broadcast/test.
 *
 *   GET   /api/notifications            my feed (+ unread count)
 *   POST  /api/notifications/read       mark some/all read
 *   POST  /api/notifications/broadcast  send to all users          (notifications.manage)
 *   POST  /api/notifications/test       dispatch a test to channels (notifications.manage)
 */
class NotificationsController extends BaseApiController
{
    private function table() { return \Config\Database::connect()->table('notifications'); }

    public function index()
    {
        // Any authenticated user can read their own notifications.
        $uid = $this->userId();
        $rows = $this->table()
            ->groupStart()->where('user_id', $uid)->orWhere('user_id', null)->groupEnd()
            ->orderBy('id', 'DESC')->limit(50)->get()->getResultArray();
        $unread = $this->table()
            ->groupStart()->where('user_id', $uid)->orWhere('user_id', null)->groupEnd()
            ->where('read_at', null)->countAllResults();
        return $this->ok(['rows' => $rows, 'unread' => $unread]);
    }

    public function read()
    {
        $uid = $this->userId();
        $in  = $this->input();
        $b   = $this->table()->groupStart()->where('user_id', $uid)->orWhere('user_id', null)->groupEnd()->where('read_at', null);
        if (! empty($in['id'])) {
            $b->where('id', (int) $in['id']);
        }
        $b->update(['read_at' => date('Y-m-d H:i:s')]);
        return $this->ok(null, 'Marked as read.');
    }

    public function broadcast()
    {
        if ($r = $this->requirePermission('notifications.manage')) return $r;
        $in = $this->input();
        if ($errors = $this->validateFields($in, ['title' => 'required|max_length[191]'])) {
            return $this->failure('A title is required.', 422, $errors);
        }
        $svc = new NotificationService();
        $svc->notify(null, $in['title'], $in['body'] ?? null, $in['level'] ?? 'info');
        $report = ($in['channels'] ?? false) ? $svc->dispatch($in['title'], (string) ($in['body'] ?? '')) : null;
        return $this->ok(['channels' => $report], 'Notification broadcast.');
    }

    public function test()
    {
        if ($r = $this->requirePermission('notifications.manage')) return $r;
        $report = (new NotificationService())->dispatch('OllamaHub test', 'This is a test notification from OllamaHub.');
        return $this->ok(['channels' => $report], 'Test dispatched.');
    }
}
