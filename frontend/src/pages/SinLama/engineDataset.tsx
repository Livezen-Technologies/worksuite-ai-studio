import { CheckCircleIcon, ClipboardDocumentIcon, NoSymbolIcon, ShieldExclamationIcon } from '@heroicons/react/24/outline';
import { Badge, IconButton, useToast } from '../../components/ui';

/** Governance columns added to `datasets` by migration 2026-10-24-000013, plus the optional counts from GET /datasets/{id}. */
export type RightsStatus = 'quarantined' | 'approved' | 'withdrawn';
export interface GovernedDataset {
  id: number; name: string; item_count: number | string;
  task?: string | null; source_ref?: string | null; permitted_use?: string | null; rights_ref?: string | null;
  consent_required?: number | string | boolean | null; consent_ref?: string | null; rights_status?: RightsStatus | null;
  version?: number | string | null; manifest_hash?: string | null; split_hash?: string | null; frozen_at?: string | null;
  split_counts?: { train?: number; dev?: number; test?: number; unassigned?: number } | null;
  group_coverage?: { with?: number; without?: number } | null;
}

export const DATASET_TASKS = ['text', 'asr', 'tts', 'translation', 'transliteration', 'spelling', 'resource'];

const STATE: Record<RightsStatus, { label: string; tone: 'amber' | 'green' | 'red'; icon: typeof CheckCircleIcon }> = {
  quarantined: { label: 'Quarantined', tone: 'amber', icon: ShieldExclamationIcon },
  approved: { label: 'Approved', tone: 'green', icon: CheckCircleIcon },
  withdrawn: { label: 'Withdrawn', tone: 'red', icon: NoSymbolIcon },
};

export function RightsChip({ status }: { status?: RightsStatus | null }) {
  const s = STATE[status ?? 'quarantined'] ?? STATE.quarantined;
  const Icon = s.icon;
  return <Badge tone={s.tone}><Icon className="size-3.5" aria-hidden />{s.label}</Badge>;
}

/** A hash shown short with a copy button; the full value is in the title. */
export function Hash({ value, label }: { value?: string | null; label: string }) {
  const toast = useToast();
  if (!value) return <span className="text-slate-500 dark:text-slate-400">Not recorded</span>;
  return (
    <span className="inline-flex items-center gap-1">
      <code className="font-mono text-xs break-all" title={value}>{value.slice(0, 12)}…{value.slice(-6)}</code>
      <IconButton
        label={`Copy ${label}`} icon={ClipboardDocumentIcon} variant="ghost" size="sm"
        onClick={() => navigator.clipboard.writeText(value).then(() => toast.success(`${label} copied`), () => toast.error('Copy failed'))}
      />
    </span>
  );
}
