<?php

namespace App\Services;

use App\Models\TrainingJobModel;
use App\Models\DatasetModel;

/**
 * TrainingService — generates a self-contained LoRA/QLoRA "training kit"
 * (train.py + dataset.jsonl + README.md, zipped) for a job. The control-plane
 * server is CPU-only, so the kit is designed to run on any rented GPU
 * (RunPod / Vast.ai / Colab); the resulting GGUF is imported back through
 * Model Studio to become a live SinLama model.
 */
class TrainingService
{
    public const DEFAULT_HYPERPARAMS = [
        'epochs'        => 3,
        'learning_rate' => 2e-4,
        'lora_rank'     => 16,
        'lora_alpha'    => 16,
        'lora_dropout'  => 0,
        'batch_size'    => 2,
        'grad_accum'    => 4,
        'max_seq_len'   => 2048,
        'warmup_steps'  => 10,
        'seed'          => 3407,
        'quant_export'  => 'q4_k_m',
    ];

    /** Popular Unsloth-optimised base models for Sinhala fine-tuning. */
    public const BASE_MODELS = [
        'unsloth/Qwen2.5-3B-Instruct',
        'unsloth/Qwen2.5-7B-Instruct',
        'unsloth/Llama-3.2-3B-Instruct',
        'unsloth/Llama-3.1-8B-Instruct',
        'unsloth/gemma-2-9b-it',
        'unsloth/mistral-7b-instruct-v0.3',
    ];

    /** Numeric hyperparameter bounds: [type, min, max]. */
    private const HP_RANGES = [
        'epochs'        => ['float', 0.1, 100],
        'learning_rate' => ['float', 1e-8, 1],
        'lora_rank'     => ['int', 1, 1024],
        'lora_alpha'    => ['int', 1, 4096],
        'lora_dropout'  => ['float', 0, 1],
        'batch_size'    => ['int', 1, 1024],
        'grad_accum'    => ['int', 1, 1024],
        'max_seq_len'   => ['int', 64, 262144],
        'warmup_steps'  => ['int', 0, 1000000],
        'seed'          => ['int', 0, 2147483647],
    ];

    public const QUANT_EXPORTS = ['q4_k_m', 'q5_k_m', 'q8_0', 'f16', 'q4_0', 'q5_0', 'q6_k', 'q3_k_m', 'q2_k', 'bf16'];

    /** Null when the job name is acceptable, else a reason. */
    public static function nameProblem(string $name): ?string
    {
        return preg_match('/^[A-Za-z0-9._-]{1,64}$/', $name) ? null : 'name may use only letters, digits, dot, dash and underscore (1-64 characters).';
    }

    public static function baseModelProblem(string $base): ?string
    {
        return in_array($base, self::BASE_MODELS, true) ? null : 'base_model must be one of: ' . implode(', ', self::BASE_MODELS) . '.';
    }

    /**
     * Known hyperparameters, cast and range-checked. Unknown keys are dropped.
     *
     * @throws \InvalidArgumentException on a non-numeric or out-of-range value
     */
    public static function cleanHyperparams(array $in): array
    {
        $out = [];
        foreach (array_intersect_key($in, self::DEFAULT_HYPERPARAMS) as $k => $v) {
            if ($k === 'quant_export') {
                if (! in_array($v, self::QUANT_EXPORTS, true)) throw new \InvalidArgumentException('quant_export must be one of: ' . implode(', ', self::QUANT_EXPORTS) . '.');
                $out[$k] = $v;
                continue;
            }
            [$type, $min, $max] = self::HP_RANGES[$k];
            if (! is_int($v) && ! is_float($v) && ! (is_string($v) && is_numeric(trim($v)))) throw new \InvalidArgumentException("{$k} must be a number.");
            $n = (float) $v;
            if ($type === 'int' && floor($n) != $n) throw new \InvalidArgumentException("{$k} must be a whole number.");
            if ($n < $min || $n > $max) throw new \InvalidArgumentException("{$k} must be between {$min} and {$max}.");
            $out[$k] = $type === 'int' ? (int) $n : $n;
        }
        return $out;
    }

    protected TrainingJobModel $jobs;

    public function __construct()
    {
        $this->jobs = new TrainingJobModel();
    }

    /**
     * Build the kit zip for a job. Returns the absolute zip path.
     *
     * @throws \RuntimeException when the dataset is empty or the zip fails
     */
    public function generateKit(int $jobId): string
    {
        $job = $this->jobs->find($jobId);
        if (! $job) {
            throw new \RuntimeException('Job not found.');
        }
        $dataset = $job['dataset_id'] ? (new DatasetModel())->find($job['dataset_id']) : null;
        if (! $dataset || (int) $dataset['item_count'] === 0) {
            throw new \RuntimeException('The job needs a dataset with at least one row.');
        }

        $hp  = array_merge(self::DEFAULT_HYPERPARAMS, json_decode((string) ($job['hyperparams'] ?? ''), true) ?: []);
        $dir = WRITEPATH . 'training/job_' . $jobId;
        if (! is_dir($dir) && ! mkdir($dir, 0775, true)) {
            throw new \RuntimeException('Cannot create kit directory.');
        }

        // 1. dataset.jsonl (+ dev.jsonl; the test split is never exported)
        // Engine guard rail (FR TRNPIPE 003/006): DomainException → 409 in the controller.
        if (($dataset['rights_status'] ?? 'approved') === 'withdrawn') {
            throw new \DomainException('This dataset has been withdrawn and cannot be used for new training runs.');
        }
        if (empty($dataset['manifest_hash'])) {
            throw new \DomainException('Freeze the dataset (immutable manifest + splits) before generating a training kit.');
        }
        $frozen = true;
        $files = ['train.py', 'dataset.jsonl', 'README.md'];
        $fh = fopen($dir . '/dataset.jsonl', 'wb');
        foreach ((new DatasetService())->exportJsonl((int) $job['dataset_id'], $frozen ? ['train'] : null) as $line) {
            fwrite($fh, $line);
        }
        fclose($fh);
        if ($frozen) {
            $fh = fopen($dir . '/dev.jsonl', 'wb');
            foreach ((new DatasetService())->exportJsonl((int) $job['dataset_id'], ['dev']) as $line) {
                fwrite($fh, $line);
            }
            fclose($fh);
            $files[] = 'dev.jsonl';
        }

        // Lineage (FR TRNPIPE 002): dataset manifest/splits, config hash and seed.
        $seed    = (int) $hp['seed'];
        $lineage = [
            'dataset_manifest_hash' => $dataset['manifest_hash'] ?? null,
            'dataset_split_hash'    => $dataset['split_hash'] ?? null,
            'config_hash'           => hash('sha256', json_encode([$job['base_model'], $job['method'], $hp, $seed])),
            'seed'                  => $seed,
        ];
        file_put_contents($dir . '/lineage.json', json_encode($lineage + [
            'job_id' => (int) $jobId, 'base_model' => $job['base_model'], 'method' => $job['method'],
            'hyperparams' => $hp, 'dataset_id' => (int) $job['dataset_id'], 'dataset_version' => $dataset['version'] ?? null,
            'generated_at' => gmdate('c'),
            'record_after_run' => ['base_digest', 'code_commit', 'container_digest', 'hardware', 'output_digest'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $files[] = 'lineage.json';

        // 2. train.py + README.md
        file_put_contents($dir . '/train.py', $this->trainScript($job, $hp));
        file_put_contents($dir . '/README.md', $this->readme($job, $dataset, $hp));

        // 3. zip
        $zipPath = $dir . '/sinlama-training-kit-' . $jobId . '.zip';
        @unlink($zipPath);
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Cannot create the kit zip.');
        }
        foreach ($files as $f) {
            $zip->addFile($dir . '/' . $f, $f);
        }
        $zip->close();

        $this->jobs->update($jobId, ['status' => 'kit_ready'] + $lineage);
        return $zipPath;
    }

    /** Existing kit zip path for a job, or null if not generated yet. */
    public function kitPath(int $jobId): ?string
    {
        $p = WRITEPATH . 'training/job_' . $jobId . '/sinlama-training-kit-' . $jobId . '.zip';
        return is_file($p) ? $p : null;
    }

    private function trainScript(array $job, array $hp): string
    {
        // Every interpolated value is a Python literal: strings via json_encode (valid Python string
        // syntax), numbers via casts, so stored rows cannot inject code into the generated script.
        $lit     = static fn (string $v): string => json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $base    = $lit((string) $job['base_model']);
        $is4bit  = $job['method'] === 'qlora' ? 'True' : 'False';
        $outName = preg_replace('/[^a-z0-9_-]+/i', '-', strtolower((string) $job['name'])) ?: 'sinlama';
        $docName = preg_replace('/[^A-Za-z0-9._ -]+/', '-', (string) $job['name']);
        $docBase = preg_replace('/[^A-Za-z0-9._\/-]+/', '-', (string) $job['base_model']);
        $method  = $job['method'] === 'lora' ? 'lora' : 'qlora';
        $lr      = (float) $hp['learning_rate'];
        $quant   = $lit((string) $hp['quant_export']);
        foreach (['max_seq_len', 'lora_rank', 'lora_alpha', 'seed', 'batch_size', 'grad_accum', 'warmup_steps'] as $k) $hp[$k] = (int) $hp[$k];
        foreach (['lora_dropout', 'epochs'] as $k) $hp[$k] = (float) $hp[$k];

        return <<<PY
#!/usr/bin/env python3
"""
SinLama training kit — job: {$docName}
Method: {$method} | Base: {$docBase}
Generated by WorkSuite AI Studio. Run on a CUDA GPU (16GB+ VRAM recommended).

Setup:
    pip install unsloth trl peft datasets
Run:
    python train.py
Output:
    ./{$outName}-gguf/  → *.gguf ready to import in WorkSuite AI Studio Model Studio
"""
from unsloth import FastLanguageModel
from datasets import load_dataset
from trl import SFTTrainer
from transformers import TrainingArguments

MAX_SEQ = {$hp['max_seq_len']}

model, tokenizer = FastLanguageModel.from_pretrained(
    model_name={$base},
    max_seq_length=MAX_SEQ,
    load_in_4bit={$is4bit},
)

model = FastLanguageModel.get_peft_model(
    model,
    r={$hp['lora_rank']},
    lora_alpha={$hp['lora_alpha']},
    lora_dropout={$hp['lora_dropout']},
    target_modules=["q_proj", "k_proj", "v_proj", "o_proj", "gate_proj", "up_proj", "down_proj"],
    use_gradient_checkpointing="unsloth",
    random_state={$hp['seed']},
)

ALPACA = """Below is an instruction that describes a task. Write a response that appropriately completes the request.

### Instruction:
{instruction}

### Input:
{input}

### Response:
{output}"""

EOS = tokenizer.eos_token

def fmt(rows):
    texts = []
    for ins, inp, out in zip(rows["instruction"], rows["input"], rows["output"]):
        texts.append(ALPACA.format(instruction=ins, input=inp or "", output=out) + EOS)
    return {"text": texts}

dataset = load_dataset("json", data_files="dataset.jsonl", split="train")
dataset = dataset.map(fmt, batched=True)

trainer = SFTTrainer(
    model=model,
    tokenizer=tokenizer,
    train_dataset=dataset,
    dataset_text_field="text",
    max_seq_length=MAX_SEQ,
    args=TrainingArguments(
        per_device_train_batch_size={$hp['batch_size']},
        gradient_accumulation_steps={$hp['grad_accum']},
        num_train_epochs={$hp['epochs']},
        learning_rate={$lr},
        warmup_steps={$hp['warmup_steps']},
        logging_steps=5,
        save_strategy="epoch",
        output_dir="checkpoints",
        optim="adamw_8bit",
        seed={$hp['seed']},
        fp16=True,
    ),
)

trainer.train()

# Merged GGUF export (ready for Ollama / WorkSuite AI Studio Model Studio import).
model.save_pretrained_gguf("{$outName}-gguf", tokenizer, quantization_method={$quant})
# Also keep the raw LoRA adapter in case you want ADAPTER-style import.
model.save_pretrained("{$outName}-lora")
tokenizer.save_pretrained("{$outName}-lora")
print("\\nDone. Import {$outName}-gguf/*.gguf via WorkSuite AI Studio → Model Studio → Import GGUF.")
PY;
    }

    private function readme(array $job, array $dataset, array $hp): string
    {
        $hpLines = '';
        foreach ($hp as $k => $v) {
            $hpLines .= "| {$k} | {$v} |\n";
        }
        return <<<MD
# SinLama Training Kit — {$job['name']}

| | |
|---|---|
| Base model | `{$job['base_model']}` |
| Method | {$job['method']} |
| Dataset | {$dataset['name']} ({$dataset['item_count']} rows, ~{$dataset['token_estimate']} tokens) |

## Hyperparameters
| Param | Value |
|---|---|
{$hpLines}

## How to run (the control-plane server is CPU-only — use a GPU box)

**Option A — RunPod / Vast.ai (recommended)**
1. Rent a GPU pod (RTX 3090/4090 or A10 works; PyTorch template).
2. Upload this kit and run:
   ```bash
   pip install unsloth trl peft datasets
   python train.py
   ```
3. Download the generated `*-gguf/` folder.

**Option B — Google Colab (T4, free tier works for 1–3B bases)**
Upload `train.py` + `dataset.jsonl`, then run the same two commands in a cell (prefix with `!`).

## Import the result into WorkSuite AI Studio
1. Open **Model Studio** in WorkSuite AI Studio.
2. Choose **Import GGUF**, upload the `.gguf` file from `*-gguf/`.
3. Name it (e.g. `sinlama:3b`), add the SinLama system prompt preset, **Create**.
4. It appears in the Chat playground and the API gateway immediately.
5. Back on the Training page, mark this job **imported**.

MD;
    }
}
