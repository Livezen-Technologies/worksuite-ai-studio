<?php

namespace App\Services;

/**
 * NotificationService — writes in-app notifications and dispatches to configured
 * channels (Slack/Discord/Telegram/generic webhook are real POSTs; email is
 * best-effort via SMTP settings). Dispatch failures never break the caller.
 */
class NotificationService
{
    protected $db;
    protected SettingsService $settings;

    public function __construct()
    {
        $this->db       = \Config\Database::connect();
        $this->settings = new SettingsService();
    }

    /** Create an in-app notification (user_id null = broadcast to all). */
    public function notify(?int $userId, string $title, ?string $body = null, string $level = 'info', string $type = 'general'): int
    {
        return (int) $this->db->table('notifications')->insert([
            'user_id' => $userId, 'type' => $type, 'title' => $title,
            'body' => $body, 'level' => $level, 'created_at' => date('Y-m-d H:i:s'),
        ], true) ? (int) $this->db->insertID() : 0;
    }

    /**
     * Push a message to every enabled external channel. Returns a per-channel
     * delivery report. Used by the "test notification" action and system events.
     */
    public function dispatch(string $title, string $body): array
    {
        $cfg    = $this->settings->group('notifications', false);
        $report = [];

        if (! empty($cfg['slack_webhook'])) {
            $report['slack'] = $this->postWebhook($cfg['slack_webhook'], ['text' => "*{$title}*\n{$body}"]);
        }
        if (! empty($cfg['discord_webhook'])) {
            $report['discord'] = $this->postWebhook($cfg['discord_webhook'], ['content' => "**{$title}**\n{$body}"]);
        }
        if (! empty($cfg['telegram_bot_token']) && ! empty($cfg['telegram_chat_id'])) {
            $url = "https://api.telegram.org/bot{$cfg['telegram_bot_token']}/sendMessage";
            $report['telegram'] = $this->postWebhook($url, ['chat_id' => $cfg['telegram_chat_id'], 'text' => "{$title}\n{$body}"]);
        }
        if (! empty($cfg['webhook_url'])) {
            $report['webhook'] = $this->postWebhook($cfg['webhook_url'], ['title' => $title, 'body' => $body]);
        }
        if (($cfg['email_enabled'] ?? '0') === '1') {
            $report['email'] = $this->sendEmail($title, $body);
        }

        if (! $report) {
            $report['none'] = 'No channels configured.';
        }
        return $report;
    }

    private function postWebhook(string $url, array $payload): string
    {
        try {
            $client = \Config\Services::curlrequest([], null, null, false);
            $res = $client->post($url, ['json' => $payload, 'http_errors' => false, 'timeout' => 8]);
            $code = $res->getStatusCode();
            return $code < 300 ? 'ok' : "http {$code}";
        } catch (\Throwable $e) {
            return 'error: ' . $e->getMessage();
        }
    }

    private function sendEmail(string $title, string $body): string
    {
        $host = $this->settings->get('smtp', 'host');
        $to   = $this->settings->get('smtp', 'from_email');
        if (! $host || ! $to) {
            return 'smtp not configured';
        }
        try {
            $email = \Config\Services::email();
            $email->setFrom($this->settings->get('smtp', 'from_email'), $this->settings->get('smtp', 'from_name'));
            $email->setTo($to);
            $email->setSubject($title);
            $email->setMessage(nl2br(esc($body)));
            return $email->send(false) ? 'ok' : 'send failed';
        } catch (\Throwable $e) {
            return 'error: ' . $e->getMessage();
        }
    }
}
