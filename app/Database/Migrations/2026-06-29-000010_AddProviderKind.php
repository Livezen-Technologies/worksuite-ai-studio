<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Adds a `kind` discriminator to `servers` so the registry can hold external
 * commercial LLM providers (OpenAI / Anthropic / Google Gemini) alongside
 * self-hosted Ollama nodes. For a provider row: api_url = the provider base URL
 * (blank → built-in default), auth_token = the API key. Their models flow into
 * the existing `models` table, so the Chat picker and gateway treat them like
 * any other model.
 */
class AddProviderKind extends Migration
{
    public function up(): void
    {
        if ($this->db->fieldExists('kind', 'servers')) {
            return;
        }
        $this->forge->addColumn('servers', [
            'kind' => [
                'type'       => "ENUM('ollama','openai','anthropic','gemini')",
                'default'    => 'ollama',
                'null'       => false,
                'after'      => 'description',
            ],
        ]);
    }

    public function down(): void
    {
        if ($this->db->fieldExists('kind', 'servers')) {
            $this->forge->dropColumn('servers', 'kind');
        }
    }
}
