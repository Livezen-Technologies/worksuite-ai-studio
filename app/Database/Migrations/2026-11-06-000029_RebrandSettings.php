<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Rebrand: stored site and email sender names still set to the old "OllamaHub" default become
 * "WorkSuite AI Studio". Values an admin changed to anything else are left alone.
 */
class RebrandSettings extends Migration
{
    private const KEYS = [['general', 'site_name'], ['smtp', 'from_name']];

    public function up(): void
    {
        foreach (self::KEYS as [$group, $key]) {
            $this->db->table('settings')
                ->where(['group' => $group, 'key' => $key, 'value' => 'OllamaHub'])
                ->update(['value' => 'WorkSuite AI Studio']);
        }
    }

    public function down(): void
    {
        foreach (self::KEYS as [$group, $key]) {
            $this->db->table('settings')
                ->where(['group' => $group, 'key' => $key, 'value' => 'WorkSuite AI Studio'])
                ->update(['value' => 'OllamaHub']);
        }
    }
}
