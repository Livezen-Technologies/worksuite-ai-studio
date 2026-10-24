<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateAdminTables extends Migration
{
    public function up(): void
    {
        // audit_logs — who did what
        $this->forge->addField([
            'id'         => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'user_id'    => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'user_name'  => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'action'     => ['type' => 'VARCHAR', 'constraint' => 120],
            'method'     => ['type' => 'VARCHAR', 'constraint' => 8, 'null' => true],
            'path'       => ['type' => 'VARCHAR', 'constraint' => 191, 'null' => true],
            'status'     => ['type' => 'SMALLINT', 'unsigned' => true, 'null' => true],
            'ip'         => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'meta'       => ['type' => 'TEXT', 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['user_id', 'created_at']);
        $this->forge->addKey('created_at');
        $this->forge->createTable('audit_logs');

        // notifications — in-app feed (user_id null = broadcast)
        $this->forge->addField([
            'id'         => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'user_id'    => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'type'       => ['type' => 'VARCHAR', 'constraint' => 64, 'default' => 'general'],
            'title'      => ['type' => 'VARCHAR', 'constraint' => 191],
            'body'       => ['type' => 'TEXT', 'null' => true],
            'level'      => ['type' => "ENUM('info','success','warning','error')", 'default' => 'info'],
            'read_at'    => ['type' => 'DATETIME', 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['user_id', 'read_at']);
        $this->forge->createTable('notifications');

        // settings — grouped key/value config
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'group'      => ['type' => 'VARCHAR', 'constraint' => 40],
            'key'        => ['type' => 'VARCHAR', 'constraint' => 80],
            'value'      => ['type' => 'TEXT', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['group', 'key']);
        $this->forge->createTable('settings');
    }

    public function down(): void
    {
        $this->forge->dropTable('settings', true);
        $this->forge->dropTable('notifications', true);
        $this->forge->dropTable('audit_logs', true);
    }
}
