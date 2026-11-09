<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * api_logs(model, created_at): the model registry's 24-hour performance figures and the idle
 * auto-unload check read the log by model.
 */
class AddApiLogModelIndex extends Migration
{
    public function up(): void
    {
        foreach ($this->db->getIndexData('api_logs') as $idx) {
            if ($idx->name === 'api_logs_model_created_at') return; // already there (a repeated run)
        }
        $this->db->query('CREATE INDEX ' . $this->db->escapeIdentifiers('api_logs_model_created_at') . ' ON ' . $this->db->escapeIdentifiers($this->db->prefixTable('api_logs')) . ' (model, created_at)');
    }

    public function down(): void
    {
        $this->db->query('DROP INDEX ' . $this->db->escapeIdentifiers('api_logs_model_created_at') . ($this->db->DBDriver === 'MySQLi' ? ' ON ' . $this->db->escapeIdentifiers($this->db->prefixTable('api_logs')) : ''));
    }
}
