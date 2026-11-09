<?php

namespace App\Libraries;

/**
 * Freshness metrics for the node_exporter textfile collector: `aistudio_<name>_last_success_seconds`.
 * Prometheus alerts when one goes stale (deploy/prometheus-alerts.yml), which catches a stopped
 * cron, a crash-looping queue worker or a scheduler job that keeps failing.
 */
final class Heartbeat
{
    public static function beat(string $name): void
    {
        $dir = (string) (env('TEXTFILE_DIR') ?: '/var/lib/prometheus/node-exporter');
        if (! is_dir($dir) || ! is_writable($dir) || ! preg_match('/^[a-z_]+$/', $name)) return;
        $tmp = "{$dir}/aistudio_{$name}.prom." . getmypid();
        if (@file_put_contents($tmp, "aistudio_{$name}_last_success_seconds " . time() . "\n") !== false) @rename($tmp, "{$dir}/aistudio_{$name}.prom");
    }
}
