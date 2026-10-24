export interface CatalogueEntry { name: string; size: string; category: string; desc: string; vision?: boolean }

export const CATEGORIES = ['General', 'Reasoning', 'Code', 'Vision', 'Embedding'] as const;

export const CATALOGUE: CatalogueEntry[] = [
  { name: 'llama3.2:1b', size: '1.3 GB', category: 'General', desc: 'Meta Llama 3.2 1B. Tiny and very fast.' },
  { name: 'llama3.2', size: '2.0 GB', category: 'General', desc: 'Meta Llama 3.2 3B. A good everyday default.' },
  { name: 'llama3.1:8b', size: '4.9 GB', category: 'General', desc: 'Meta Llama 3.1 8B. Strong general model.' },
  { name: 'qwen2.5:3b', size: '1.9 GB', category: 'General', desc: 'Qwen2.5 3B. Capable and compact.' },
  { name: 'qwen2.5:7b', size: '4.7 GB', category: 'General', desc: 'Qwen2.5 7B. Excellent all-rounder.' },
  { name: 'qwen2.5:14b', size: '9.0 GB', category: 'General', desc: 'Qwen2.5 14B. Higher quality, heavier.' },
  { name: 'gemma2:2b', size: '1.6 GB', category: 'General', desc: 'Google Gemma 2 2B. Small and quick.' },
  { name: 'gemma2:9b', size: '5.4 GB', category: 'General', desc: 'Google Gemma 2 9B. Balanced quality.' },
  { name: 'mistral', size: '4.1 GB', category: 'General', desc: 'Mistral 7B. Fast, popular baseline.' },
  { name: 'phi3.5', size: '2.2 GB', category: 'General', desc: 'Microsoft Phi-3.5 3.8B. Efficient.' },
  { name: 'tinyllama', size: '640 MB', category: 'General', desc: 'TinyLlama 1.1B. Ultralight.' },
  { name: 'deepseek-r1:1.5b', size: '1.1 GB', category: 'Reasoning', desc: 'DeepSeek-R1 distill. Tiny reasoner.' },
  { name: 'deepseek-r1:7b', size: '4.7 GB', category: 'Reasoning', desc: 'DeepSeek-R1 7B. Step-by-step reasoning.' },
  { name: 'deepseek-r1:8b', size: '4.9 GB', category: 'Reasoning', desc: 'DeepSeek-R1 8B distill (Llama).' },
  { name: 'deepseek-r1:14b', size: '9.0 GB', category: 'Reasoning', desc: 'DeepSeek-R1 14B. Deeper reasoning.' },
  { name: 'qwen2.5-coder:1.5b', size: '1.0 GB', category: 'Code', desc: 'Qwen2.5-Coder 1.5B. Fast completions.' },
  { name: 'qwen2.5-coder:7b', size: '4.7 GB', category: 'Code', desc: 'Qwen2.5-Coder 7B. Strong coding model.' },
  { name: 'codellama:7b', size: '3.8 GB', category: 'Code', desc: 'Code Llama 7B. Code generation.' },
  { name: 'codegemma:7b', size: '5.0 GB', category: 'Code', desc: 'CodeGemma 7B. Coding and infill.' },
  { name: 'starcoder2:3b', size: '1.7 GB', category: 'Code', desc: 'StarCoder2 3B. Multi-language code.' },
  { name: 'moondream', size: '1.7 GB', category: 'Vision', vision: true, desc: 'Moondream 2. Tiny, fast image analysis.' },
  { name: 'llava:7b', size: '4.7 GB', category: 'Vision', vision: true, desc: 'LLaVA 7B. Image understanding and chat.' },
  { name: 'llava:13b', size: '8.0 GB', category: 'Vision', vision: true, desc: 'LLaVA 13B. Higher-quality vision.' },
  { name: 'llama3.2-vision:11b', size: '7.9 GB', category: 'Vision', vision: true, desc: 'Llama 3.2 Vision 11B. Multimodal.' },
  { name: 'minicpm-v', size: '5.5 GB', category: 'Vision', vision: true, desc: 'MiniCPM-V. Strong OCR and vision.' },
  { name: 'nomic-embed-text', size: '274 MB', category: 'Embedding', desc: 'Nomic Embed. Text embeddings.' },
  { name: 'mxbai-embed-large', size: '670 MB', category: 'Embedding', desc: 'mxbai-embed-large. High-quality embeddings.' },
  { name: 'all-minilm', size: '46 MB', category: 'Embedding', desc: 'all-MiniLM. Tiny embeddings.' },
  { name: 'bge-m3', size: '1.2 GB', category: 'Embedding', desc: 'BGE-M3. Multilingual embeddings.' },
];

export const isInstalled = (name: string, installed: Set<string>) =>
  installed.has(name) || (!name.includes(':') && installed.has(`${name}:latest`));
