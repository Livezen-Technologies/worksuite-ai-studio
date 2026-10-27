<?php

namespace App\Libraries;

/**
 * Training recipes: what a pipeline's job needs — its code (deploy/train/<recipe>/train.py + requirements.txt, run by
 * the generic node runner), its data, whether it needs a GPU, and its defaults.
 *
 * data.type:
 *   dataset   — the pipeline's AI Studio dataset, exported to train.jsonl / dev.jsonl (frozen: its train/dev splits;
 *               otherwise a stable 95/5 split by row hash). The locked test split never leaves the server.
 *   prepared  — files already prepared on the server (e.g. the Whisper number clips), staged as they are.
 */
class TrainingRecipes
{
    public static function all(): array
    {
        return [
            'text_sft' => [
                'label'       => 'Sinhala chat model — LoRA fine-tune',
                'task'        => 'text',
                'needs_gpu'   => true,
                'base_model'  => 'Qwen/Qwen2.5-3B-Instruct',
                'hyperparams' => ['epochs' => 2, 'batch' => 4, 'grad_accum' => 4, 'lr' => 2e-4, 'lora_r' => 16, 'max_len' => 1024, 'max_rows' => 60000],
                'data'        => ['type' => 'dataset'],
                'extra'       => [],
                'output'      => 'a custom LLM in Ollama (LoRA merged into the base, GGUF)',
                // Open base models a 16 GB GPU can LoRA-train in fp16 (no gated licences); any Hugging Face id also works.
                'base_options' => [
                    ['id' => 'Qwen/Qwen2.5-0.5B-Instruct', 'label' => 'Qwen2.5 0.5B Instruct — fastest, for testing', 'vram_gb' => 4],
                    ['id' => 'Qwen/Qwen2.5-1.5B-Instruct', 'label' => 'Qwen2.5 1.5B Instruct — small and quick', 'vram_gb' => 8],
                    ['id' => 'Qwen/Qwen2.5-3B-Instruct', 'label' => 'Qwen2.5 3B Instruct — recommended for Sinhala', 'vram_gb' => 14],
                    ['id' => 'HuggingFaceTB/SmolLM2-1.7B-Instruct', 'label' => 'SmolLM2 1.7B Instruct — English-first', 'vram_gb' => 8],
                ],
            ],
            'text_classifier' => [
                'label'       => 'Text classifier — sort text into your labels',
                'task'        => 'classification',
                'needs_gpu'   => false,              // fast on a GPU; a CPU node is fine for a few thousand rows
                'base_model'  => 'FacebookAI/xlm-roberta-base',
                'hyperparams' => ['epochs' => 4, 'batch' => 16, 'lr' => 2e-5, 'max_len' => 256, 'max_rows' => 200000],
                'data'        => ['type' => 'dataset', 'classifier' => true],
                'extra'       => [],
                'output'      => 'a classifier with an API (POST /api/v1/classify) and a Try box',
                'base_options' => [
                    ['id' => 'FacebookAI/xlm-roberta-base', 'label' => 'XLM-RoBERTa base — Sinhala + English, recommended', 'vram_gb' => 4],
                    ['id' => 'distilbert/distilbert-base-multilingual-cased', 'label' => 'DistilBERT multilingual — smaller and faster', 'vram_gb' => 3],
                    ['id' => 'FacebookAI/xlm-roberta-large', 'label' => 'XLM-RoBERTa large — most accurate, needs a GPU', 'vram_gb' => 12],
                ],
            ],
            'whisper_numbers' => [
                'label'       => 'Whisper Sinhala — numbers fine-tune',
                'task'        => 'asr',
                'needs_gpu'   => true,
                'base_model'  => 'SinhaSpeech/whisper-small-sinhala-run11',
                'hyperparams' => ['epochs' => 3, 'batch' => 16, 'lr' => 1e-5],
                'data'        => ['type' => 'prepared', 'dir' => '/srv/sinhala-data/prepared/asr_numbers', 'files' => ['train.parquet', 'dev.parquet']],
                'extra'       => ['deploy/asr/numfix.py'],
                'output'      => 'fine-tuned Whisper (whisper-sinhala-numbers.tar.gz)',
            ],
        ];
    }

    public static function get(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    public static function codeDir(string $key): string
    {
        return ROOTPATH . 'deploy/train/' . $key . '/';
    }

    /** Can this server row run the recipe? (GPU recipes need an NVIDIA GPU on record.) */
    public static function fits(array $recipe, array $server): bool
    {
        return ! $recipe['needs_gpu'] || GpuSpecs::cudaCores($server['gpu_model'] ?? null) !== null
            || stripos((string) ($server['gpu_model'] ?? ''), 'nvidia') !== false;
    }
}
