<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Seeds the SinLama Sinhala prompt pack into the Prompt Library (idempotent —
 * inserts only names that don't already exist).
 */
class SeedSinhalaPrompts extends Migration
{
    private const PROMPTS = [
        [
            'name'        => 'SinLama Assistant persona',
            'description' => 'Sinhala-native enterprise assistant system prompt',
            'content'     => "You are SinLama AI (සින්ලාමා), an enterprise Sinhala AI assistant for Sri Lankan users. Always answer in natural Sinhala unless the user writes in English. Understand Sinhala, English and Singlish. Use Sri Lankan terminology, currency (රු./LKR) and examples. Keep common technical terms in English. Be concise and helpful.",
        ],
        [
            'name'        => 'Translate to Sinhala',
            'description' => 'Translate {{text}} into natural Sinhala',
            'content'     => "Translate the following text into natural, contemporary Sinhala (සිංහල). Output only the translation.\n\n{{text}}",
        ],
        [
            'name'        => 'Translate to English',
            'description' => 'Translate Sinhala/Singlish {{text}} into English',
            'content'     => "Translate the following Sinhala (or Singlish) text into natural English. Output only the translation.\n\n{{text}}",
        ],
        [
            'name'        => 'Singlish → Sinhala script',
            'description' => 'Convert Singlish (Latin letters) to Sinhala script',
            'content'     => "Convert the following Singlish text (Sinhala written in Latin letters) into proper Sinhala script. Fix obvious typos but keep the meaning identical. Output only the converted text.\n\n{{text}}",
        ],
        [
            'name'        => 'Sinhala grammar correction',
            'description' => 'Fix grammar/spelling in Sinhala {{text}}',
            'content'     => "Correct the grammar and spelling of the following Sinhala text. Keep the meaning and tone unchanged. Output only the corrected text.\n\n{{text}}",
        ],
        [
            'name'        => 'Sinhala summariser',
            'description' => 'Summarise {{text}} in Sinhala ({{sentences}} sentences)',
            'content'     => "Summarise the following text in Sinhala, in at most {{sentences}} sentences. Keep names, figures and key facts. Output only the summary.\n\n{{text}}",
        ],
    ];

    public function up(): void
    {
        if (! $this->db->tableExists('prompt_templates')) {
            return;
        }
        $now = date('Y-m-d H:i:s');
        foreach (self::PROMPTS as $p) {
            $exists = $this->db->table('prompt_templates')->where('name', $p['name'])->countAllResults();
            if ($exists) {
                continue;
            }
            preg_match_all('/{{\s*([a-z0-9_]+)\s*}}/i', $p['content'], $m);
            $this->db->table('prompt_templates')->insert([
                'name'        => $p['name'],
                'category'    => 'Sinhala',
                'description' => $p['description'],
                'content'     => $p['content'],
                'variables'   => json_encode(array_values(array_unique($m[1] ?? []))),
                'is_public'   => 1,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
        }
    }

    public function down(): void
    {
        if ($this->db->tableExists('prompt_templates')) {
            $this->db->table('prompt_templates')->where('category', 'Sinhala')->delete();
        }
    }
}
