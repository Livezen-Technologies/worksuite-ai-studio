<?php

namespace App\Libraries;

/**
 * ModelCatalogue — curated models the Studio can install, by type. Each entry names how each engine
 * knows it: an Ollama tag (Ollama nodes pull it) and/or a Hugging Face repo (what a vLLM or llama.cpp
 * node is started with). Speech models map to the speech service's Whisper keys.
 *
 * Sizes are the download for the listed quantization; vram_gb is what it needs on a GPU with an
 * ordinary context (weights + ~20 % for the KV cache and runtime).
 */
final class ModelCatalogue
{
    /** @return list<array<string, mixed>> */
    public static function all(): array
    {
        $e = static fn (string $id, string $name, string $type, string $family, string $params, float $size, float $vram, int $ctx, array $caps,
            ?string $ollama, ?string $hf, string $license, string $desc, string $quant = 'Q4_K_M', ?string $speech = null) => [
            'id' => $id, 'name' => $name, 'type' => $type, 'family' => $family, 'parameter_size' => $params, 'quantization' => $quant,
            'size_gb' => $size, 'vram_gb' => $vram, 'context_length' => $ctx, 'capabilities' => $caps, 'ollama' => $ollama, 'hf' => $hf,
            'speech' => $speech, 'license' => $license, 'description' => $desc,
            'source' => $ollama ? "https://ollama.com/library/" . explode(':', $ollama)[0] : ($hf ? "https://huggingface.co/{$hf}" : null),
        ];
        $chat = ['chat', 'completion'];
        return [
            // ---- General LLMs
            $e('llama3.2:1b', 'Llama 3.2 1B', 'llm', 'llama', '1B', 1.3, 2.0, 131072, [...$chat, 'tools'], 'llama3.2:1b', 'meta-llama/Llama-3.2-1B-Instruct', 'Llama 3.2 Community', 'Tiny and very fast; good for simple tasks and edge nodes.'),
            $e('llama3.2:3b', 'Llama 3.2 3B', 'llm', 'llama', '3B', 2.0, 3.0, 131072, [...$chat, 'tools'], 'llama3.2:3b', 'meta-llama/Llama-3.2-3B-Instruct', 'Llama 3.2 Community', 'A good everyday default for small GPUs.'),
            $e('llama3.1:8b', 'Llama 3.1 8B', 'llm', 'llama', '8B', 4.9, 6.5, 131072, [...$chat, 'tools'], 'llama3.1:8b', 'meta-llama/Llama-3.1-8B-Instruct', 'Llama 3.1 Community', 'Strong general model with tool calling.'),
            $e('llama3.3:70b', 'Llama 3.3 70B', 'llm', 'llama', '70B', 43.0, 48.0, 131072, [...$chat, 'tools'], 'llama3.3:70b', 'meta-llama/Llama-3.3-70B-Instruct', 'Llama 3.3 Community', 'Near frontier quality; needs a 48 GB GPU or two 24 GB.'),
            $e('qwen2.5:3b', 'Qwen 2.5 3B', 'llm', 'qwen2', '3B', 1.9, 3.0, 32768, [...$chat, 'tools'], 'qwen2.5:3b', 'Qwen/Qwen2.5-3B-Instruct', 'Qwen Research', 'Capable and compact; good multilingual coverage.'),
            $e('qwen2.5:7b', 'Qwen 2.5 7B', 'llm', 'qwen2', '7B', 4.7, 6.0, 32768, [...$chat, 'tools', 'json'], 'qwen2.5:7b', 'Qwen/Qwen2.5-7B-Instruct', 'Apache-2.0', 'Excellent all-rounder with reliable JSON and tool calling.'),
            $e('qwen2.5:14b', 'Qwen 2.5 14B', 'llm', 'qwen2', '14B', 9.0, 11.0, 32768, [...$chat, 'tools', 'json'], 'qwen2.5:14b', 'Qwen/Qwen2.5-14B-Instruct', 'Apache-2.0', 'Higher quality; fits a 12-16 GB GPU.'),
            $e('qwen2.5:32b', 'Qwen 2.5 32B', 'llm', 'qwen2', '32B', 20.0, 23.0, 32768, [...$chat, 'tools', 'json'], 'qwen2.5:32b', 'Qwen/Qwen2.5-32B-Instruct', 'Apache-2.0', 'Top open model for a single 24 GB GPU.'),
            $e('qwen3:8b', 'Qwen 3 8B', 'llm', 'qwen3', '8B', 5.2, 6.8, 40960, [...$chat, 'tools', 'reasoning'], 'qwen3:8b', 'Qwen/Qwen3-8B', 'Apache-2.0', 'Hybrid thinking / non-thinking model with tool calling.'),
            $e('gemma2:9b', 'Gemma 2 9B', 'llm', 'gemma2', '9B', 5.4, 7.0, 8192, $chat, 'gemma2:9b', 'google/gemma-2-9b-it', 'Gemma', 'Balanced quality from Google.'),
            $e('mistral:7b', 'Mistral 7B', 'llm', 'mistral', '7B', 4.1, 5.5, 32768, [...$chat, 'tools'], 'mistral:7b', 'mistralai/Mistral-7B-Instruct-v0.3', 'Apache-2.0', 'Fast, popular baseline with tool calling.'),
            $e('phi3.5', 'Phi-3.5 Mini', 'llm', 'phi3', '3.8B', 2.2, 3.2, 131072, $chat, 'phi3.5', 'microsoft/Phi-3.5-mini-instruct', 'MIT', 'Efficient small model with a long context.'),
            // ---- Sinhala
            $e('helalm:4b', 'HelaLM 4B (Sinhala)', 'llm', 'gemma', '4B', 2.5, 3.5, 8192, $chat, null, 'HelaAI/SinhalaLM-v0.0.1', 'HelaAI terms', 'Sinhala chat model; the AI Studio default for Sinhala.'),
            $e('sinllama:8b', 'SinLlama 8B (Sinhala)', 'llm', 'llama', '8B', 4.9, 6.5, 8192, $chat, null, 'polyglots/SinLlama_v01', 'Llama 3 Community', 'Llama 3 continued-pretrained on Sinhala.'),
            // ---- Reasoning
            $e('deepseek-r1:7b', 'DeepSeek-R1 7B', 'llm', 'qwen2', '7B', 4.7, 6.0, 131072, [...$chat, 'reasoning'], 'deepseek-r1:7b', 'deepseek-ai/DeepSeek-R1-Distill-Qwen-7B', 'MIT', 'Step-by-step reasoning distilled from DeepSeek-R1.'),
            $e('deepseek-r1:14b', 'DeepSeek-R1 14B', 'llm', 'qwen2', '14B', 9.0, 11.0, 131072, [...$chat, 'reasoning'], 'deepseek-r1:14b', 'deepseek-ai/DeepSeek-R1-Distill-Qwen-14B', 'MIT', 'Deeper reasoning; fits a 16 GB GPU.'),
            $e('qwq:32b', 'QwQ 32B', 'llm', 'qwen2', '32B', 20.0, 23.0, 131072, [...$chat, 'reasoning'], 'qwq:32b', 'Qwen/QwQ-32B', 'Apache-2.0', 'Strong open reasoning model for a 24 GB GPU.'),
            // ---- Code
            $e('qwen2.5-coder:7b', 'Qwen 2.5 Coder 7B', 'llm', 'qwen2', '7B', 4.7, 6.0, 32768, [...$chat, 'code', 'tools'], 'qwen2.5-coder:7b', 'Qwen/Qwen2.5-Coder-7B-Instruct', 'Apache-2.0', 'Strong coding model; completions and chat.'),
            $e('qwen2.5-coder:32b', 'Qwen 2.5 Coder 32B', 'llm', 'qwen2', '32B', 20.0, 23.0, 32768, [...$chat, 'code', 'tools'], 'qwen2.5-coder:32b', 'Qwen/Qwen2.5-Coder-32B-Instruct', 'Apache-2.0', 'Best open coding model for a single 24 GB GPU.'),
            $e('codegemma:7b', 'CodeGemma 7B', 'llm', 'gemma', '7B', 5.0, 6.5, 8192, [...$chat, 'code'], 'codegemma:7b', 'google/codegemma-7b-it', 'Gemma', 'Coding and fill-in-the-middle.'),
            // ---- Vision & multimodal
            $e('llama3.2-vision:11b', 'Llama 3.2 Vision 11B', 'vision', 'mllama', '11B', 7.9, 10.0, 131072, [...$chat, 'vision'], 'llama3.2-vision:11b', 'meta-llama/Llama-3.2-11B-Vision-Instruct', 'Llama 3.2 Community', 'Image understanding and visual question answering.'),
            $e('qwen2.5vl:7b', 'Qwen 2.5 VL 7B', 'vision', 'qwen2.5vl', '7B', 6.0, 8.0, 128000, [...$chat, 'vision', 'json'], 'qwen2.5vl:7b', 'Qwen/Qwen2.5-VL-7B-Instruct', 'Apache-2.0', 'Documents, charts, OCR and screenshots.'),
            $e('minicpm-v:8b', 'MiniCPM-V 2.6', 'vision', 'qwen2', '8B', 5.5, 7.0, 32768, [...$chat, 'vision'], 'minicpm-v:8b', 'openbmb/MiniCPM-V-2_6', 'MiniCPM', 'Strong OCR and multi-image understanding.'),
            $e('llava:7b', 'LLaVA 1.6 7B', 'vision', 'llama', '7B', 4.7, 6.0, 4096, [...$chat, 'vision'], 'llava:7b', 'llava-hf/llava-v1.6-mistral-7b-hf', 'Apache-2.0', 'Classic image chat model.'),
            $e('moondream', 'Moondream 2', 'vision', 'moondream', '1.8B', 1.7, 2.5, 2048, [...$chat, 'vision'], 'moondream', 'vikhyatk/moondream2', 'Apache-2.0', 'Tiny, fast image captioning and questions.'),
            $e('gemma3:12b', 'Gemma 3 12B', 'multimodal', 'gemma3', '12B', 8.1, 10.5, 131072, [...$chat, 'vision'], 'gemma3:12b', 'google/gemma-3-12b-it', 'Gemma', 'Text and images in 140+ languages.'),
            $e('qwen2.5-omni:7b', 'Qwen 2.5 Omni 7B', 'multimodal', 'qwen2.5omni', '7B', 15.0, 18.0, 32768, [...$chat, 'vision', 'audio_in'], null, 'Qwen/Qwen2.5-Omni-7B', 'Apache-2.0', 'Text, images and audio in one model (vLLM).', 'BF16'),
            // ---- Embeddings
            $e('nomic-embed-text', 'Nomic Embed Text v1.5', 'embedding', 'nomic-bert', '137M', 0.27, 0.6, 8192, ['embeddings'], 'nomic-embed-text', 'nomic-ai/nomic-embed-text-v1.5', 'Apache-2.0', 'Fast English embeddings for search and RAG.', 'F16'),
            $e('mxbai-embed-large', 'mxbai Embed Large', 'embedding', 'bert', '335M', 0.67, 1.0, 512, ['embeddings'], 'mxbai-embed-large', 'mixedbread-ai/mxbai-embed-large-v1', 'Apache-2.0', 'High-quality English embeddings.', 'F16'),
            $e('bge-m3', 'BGE-M3', 'embedding', 'bert', '567M', 1.2, 1.8, 8192, ['embeddings'], 'bge-m3', 'BAAI/bge-m3', 'MIT', 'Multilingual (100+ languages incl. Sinhala) embeddings.', 'F16'),
            $e('snowflake-arctic-embed2', 'Arctic Embed 2', 'embedding', 'bert', '568M', 1.2, 1.8, 8192, ['embeddings'], 'snowflake-arctic-embed2', 'Snowflake/snowflake-arctic-embed-l-v2.0', 'Apache-2.0', 'Multilingual retrieval embeddings.', 'F16'),
            // ---- Speech to text (the speech service; on GPU nodes with the training agent)
            $e('whisper:sinhala', 'Whisper Small · Sinhala', 'stt', 'whisper', '244M', 0.25, 1.0, 448, ['audio_in'], null, null, 'Apache-2.0 (fine-tune of MIT Whisper)', 'Fine-tuned for Sinhala. The default speech model.', 'INT8', 'sinhala'),
            $e('whisper:tiny', 'Whisper Tiny', 'stt', 'whisper', '39M', 0.04, 0.4, 448, ['audio_in'], null, 'Systran/faster-whisper-tiny', 'MIT', 'Fastest multilingual speech recognition.', 'INT8', 'tiny'),
            $e('whisper:base', 'Whisper Base', 'stt', 'whisper', '74M', 0.08, 0.5, 448, ['audio_in'], null, 'Systran/faster-whisper-base', 'MIT', 'Fast multilingual; detects the language in mixed speech.', 'INT8', 'base'),
            $e('whisper:small', 'Whisper Small', 'stt', 'whisper', '244M', 0.25, 1.0, 448, ['audio_in'], null, 'Systran/faster-whisper-small', 'MIT', 'Good balance of speed and accuracy.', 'INT8', 'small'),
            $e('whisper:medium', 'Whisper Medium', 'stt', 'whisper', '769M', 0.77, 2.0, 448, ['audio_in'], null, 'Systran/faster-whisper-medium', 'MIT', 'Higher accuracy, slower.', 'INT8', 'medium'),
            $e('whisper:turbo', 'Whisper Large v3 Turbo', 'stt', 'whisper', '809M', 0.81, 2.5, 448, ['audio_in'], null, 'mobiuslabsgmbh/faster-whisper-large-v3-turbo', 'MIT', 'Best multilingual accuracy; use a GPU node.', 'INT8', 'turbo'),
        ];
    }

    public static function find(string $id): ?array
    {
        foreach (self::all() as $m) if ($m['id'] === $id) return $m;
        return null;
    }
}
