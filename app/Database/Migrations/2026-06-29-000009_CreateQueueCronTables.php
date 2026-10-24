<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateQueueCronTables extends Migration
{
    public function up(): void
    {
        // queues — background AI/ops tasks (DB-backed; Redis-ready in production)
        $this->forge->addField([
            'id'           => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'type'         => ['type' => 'VARCHAR', 'constraint' => 64],
            'payload'      => ['type' => 'TEXT', 'null' => true], // JSON
            'status'       => ['type' => "ENUM('pending','running','completed','failed','cancelled')", 'default' => 'pending'],
            'priority'     => ['type' => 'TINYINT', 'default' => 5],
            'attempts'     => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'max_attempts' => ['type' => 'INT', 'unsigned' => true, 'default' => 3],
            'result'       => ['type' => 'TEXT', 'null' => true],
            'error'        => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'available_at' => ['type' => 'DATETIME', 'null' => true],
            'started_at'   => ['type' => 'DATETIME', 'null' => true],
            'finished_at'  => ['type' => 'DATETIME', 'null' => true],
            'created_at'   => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['status', 'available_at']);
        $this->forge->addKey('created_at');
        $this->forge->createTable('queues');

        // cron_jobs — scheduled maintenance jobs
        $this->forge->addField([
            'id'          => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'key'         => ['type' => 'VARCHAR', 'constraint' => 64],
            'name'        => ['type' => 'VARCHAR', 'constraint' => 120],
            'description' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'handler'     => ['type' => 'VARCHAR', 'constraint' => 64],
            'schedule'    => ['type' => 'VARCHAR', 'constraint' => 40, 'default' => 'hourly'],
            'enabled'     => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'last_run_at' => ['type' => 'DATETIME', 'null' => true],
            'last_status' => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
            'last_output' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'next_run_at' => ['type' => 'DATETIME', 'null' => true],
            'created_at'  => ['type' => 'DATETIME', 'null' => true],
            'updated_at'  => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('key');
        $this->forge->createTable('cron_jobs');
    }

    public function down(): void
    {
        $this->forge->dropTable('cron_jobs', true);
        $this->forge->dropTable('queues', true);
    }
}
