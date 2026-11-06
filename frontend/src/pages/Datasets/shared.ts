import type { BadgeTone, useToast } from '../../components/ui';
import { download } from '../../lib/api';
import type { GovernedDataset } from '../SinLama/engineDataset';

export interface Dataset {
  id: number; name: string; language: string; description: string | null;
  item_count: number | string; token_estimate: number | string; created_at: string; updated_at?: string;
  rights_status?: 'quarantined' | 'approved' | 'withdrawn' | null; task?: string | null; category?: string | null; manifest_hash?: string | null; version?: number | string | null;
}
export interface Item { id: number; instruction: string; input: string | null; output: string; tokens: number | string }
export interface Detail { dataset: Dataset & GovernedDataset; items: Item[]; total: number; page: number; per: number }

export const LANGS = [
  { value: 'si', label: 'Sinhala' },
  { value: 'en', label: 'English' },
  { value: 'mixed', label: 'Mixed (si + en)' },
];
export const langLabel = (l: string) => LANGS.find((x) => x.value === l)?.label ?? l;
export const ACCEPT = '.json,.jsonl,.csv';

/** Training categories set by `spark datasets:organize`; the balanced mix takes a fixed share of the first five. */
export const CATEGORIES: { value: string; label: string; tone: BadgeTone }[] = [
  { value: 'instruction', label: 'Instructions', tone: 'blue' },
  { value: 'qa', label: 'Q&A', tone: 'green' },
  { value: 'transliteration', label: 'Singlish', tone: 'purple' },
  { value: 'translation', label: 'Translation', tone: 'indigo' },
  { value: 'corpus', label: 'Text corpus', tone: 'amber' },
  { value: 'speech', label: 'Speech', tone: 'gray' },
  { value: 'evaluation', label: 'Evaluation only', tone: 'red' },
  { value: 'reference', label: 'Reference', tone: 'gray' },
];
export const category = (c?: string | null) => CATEGORIES.find((x) => x.value === c);

export async function exportDataset(d: Dataset, toast: ReturnType<typeof useToast>) {
  try {
    await download(`/datasets/${d.id}/export`, `${d.name.replace(/[^a-z0-9_-]+/gi, '-') || 'dataset'}.jsonl`);
    toast.success('Export started', 'Alpaca-format JSONL.');
  } catch (e) { toast.error(e); }
}
