<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateAiWorkspaceTables extends Migration
{
    public function up(): void
    {
        // prompt_templates
        $this->forge->addField([
            'id'          => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'user_id'     => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'name'        => ['type' => 'VARCHAR', 'constraint' => 150],
            'category'    => ['type' => 'VARCHAR', 'constraint' => 64, 'default' => 'General'],
            'description' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'content'     => ['type' => 'MEDIUMTEXT'],
            'variables'   => ['type' => 'TEXT', 'null' => true], // JSON array of {{var}} names
            'is_public'   => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'created_at'  => ['type' => 'DATETIME', 'null' => true],
            'updated_at'  => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('category');
        $this->forge->createTable('prompt_templates');

        // knowledge_bases
        $this->forge->addField([
            'id'          => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'user_id'     => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'name'        => ['type' => 'VARCHAR', 'constraint' => 150],
            'description' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'embed_model' => ['type' => 'VARCHAR', 'constraint' => 120, 'default' => 'nomic-embed-text'],
            'server_id'   => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'chunk_size'  => ['type' => 'INT', 'unsigned' => true, 'default' => 800],
            'created_at'  => ['type' => 'DATETIME', 'null' => true],
            'updated_at'  => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->createTable('knowledge_bases');

        // knowledge_documents
        $this->forge->addField([
            'id'          => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'kb_id'       => ['type' => 'INT', 'unsigned' => true],
            'title'       => ['type' => 'VARCHAR', 'constraint' => 191],
            'source_type' => ['type' => "ENUM('text','file','url')", 'default' => 'text'],
            'filename'    => ['type' => 'VARCHAR', 'constraint' => 191, 'null' => true],
            'mime'        => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'size_bytes'  => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'status'      => ['type' => "ENUM('processing','ready','error')", 'default' => 'processing'],
            'chunk_count' => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'embedded'    => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'created_at'  => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('kb_id');
        $this->forge->addForeignKey('kb_id', 'knowledge_bases', 'id', '', 'CASCADE');
        $this->forge->createTable('knowledge_documents');

        // knowledge_chunks
        $this->forge->addField([
            'id'          => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'kb_id'       => ['type' => 'INT', 'unsigned' => true],
            'document_id' => ['type' => 'INT', 'unsigned' => true],
            'chunk_index' => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'content'     => ['type' => 'MEDIUMTEXT'],
            'embedding'   => ['type' => 'LONGTEXT', 'null' => true], // JSON float array
            'tokens'      => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'created_at'  => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['kb_id', 'document_id']);
        $this->forge->addForeignKey('document_id', 'knowledge_documents', 'id', '', 'CASCADE');
        $this->forge->createTable('knowledge_chunks');

        // agents
        $this->forge->addField([
            'id'            => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'user_id'       => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'name'          => ['type' => 'VARCHAR', 'constraint' => 150],
            'description'   => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'avatar'        => ['type' => 'VARCHAR', 'constraint' => 16, 'null' => true], // emoji
            'system_prompt' => ['type' => 'MEDIUMTEXT', 'null' => true],
            'model'         => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'server_id'     => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'temperature'   => ['type' => 'DECIMAL', 'constraint' => '4,2', 'default' => 0.70],
            'kb_id'         => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'tools'         => ['type' => 'TEXT', 'null' => true], // JSON array
            'api_access'    => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'status'        => ['type' => "ENUM('active','disabled')", 'default' => 'active'],
            'created_at'    => ['type' => 'DATETIME', 'null' => true],
            'updated_at'    => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->createTable('agents');
    }

    public function down(): void
    {
        $this->forge->dropTable('agents', true);
        $this->forge->dropTable('knowledge_chunks', true);
        $this->forge->dropTable('knowledge_documents', true);
        $this->forge->dropTable('knowledge_bases', true);
        $this->forge->dropTable('prompt_templates', true);
    }
}
