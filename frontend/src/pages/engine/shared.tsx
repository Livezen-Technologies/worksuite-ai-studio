import { useState, type ComponentType, type ReactNode, type SVGProps } from 'react';
import { Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import {
  ArchiveBoxIcon, ArrowLeftIcon, CheckBadgeIcon, CheckCircleIcon, CheckIcon, ClipboardDocumentIcon, ClockIcon,
  ExclamationTriangleIcon, LockClosedIcon, MinusCircleIcon, NoSymbolIcon, PlayCircleIcon, QuestionMarkCircleIcon,
  ShieldCheckIcon, SparklesIcon, XCircleIcon,
} from '@heroicons/react/20/solid';
import { Badge, IconButton, cx, type BadgeTone } from '../../components/ui';
import { get } from '../../lib/api';

/* ---------------------------------------------------------------- types */

export type GateDef = [op: 'max' | 'min', threshold: number, label: string];
export interface Overview {
  gates: Record<string, Record<string, GateDef>>;
  states: string[];
  transitions: Record<string, string[]>;
  runtimes: string[];
  blocked_licenses: string[];
  package_counts: Record<string, number | string>;
  publisher_key: { key_id: string; ed25519_public: string };
}

export interface GateCheck { metric: string; label: string; value?: number; status: 'pass' | 'fail' | 'insufficient_evidence' }
export interface Subgroup { name: string; n: number; metrics?: Record<string, number>; baseline?: Record<string, number> }
export interface Evaluation {
  id: number; uuid: string; task: string; training_job_id: number | null;
  candidate_digest: string; baseline_digest: string | null; suite_digest: string;
  hardware: string | null; runtime_lock: string | null;
  metrics: Record<string, number> | null; baseline_metrics: Record<string, number> | null;
  subgroups: Subgroup[] | null; gate_result: { task?: string; pass: boolean; checks: GateCheck[] } | null;
  decision: 'pass' | 'fail' | 'pending' | string; notes: string | null;
  release_approver: number | string | null; language_approver: number | string | null;
  release_approver_name?: string | null; language_approver_name?: string | null; created_by_name?: string | null;
  training_job?: { id: number; name: string } | null;
  packages?: { id: number; package_id: string; version: string; state: string }[];
  created_at: string; updated_at?: string;
}

export interface Artifact { path: string; sha256: string; size_bytes: number; server_verified?: boolean }
export interface HistoryEntry { from: string | null; to: string; at: string; by?: number | null; by_name?: string | null; actor?: number | null; reason?: string | null }
export interface PackageSummary {
  id: number; package_id: string; version: string; task: string; languages: string;
  runtime_name: string; runtime_range: string; base_model?: string | null; quantization?: string | null;
  license_id: string; rights_approval_id?: string | null; state: string;
  manifest_sha256: string | null; publisher_key_id?: string | null;
  artifacts: Artifact[] | null; state_history?: HistoryEntry[] | null; updated_at: string | null;
  evaluation_uuid?: string | null; evaluation_decision?: string | null;
}
export interface PackageDetail extends PackageSummary {
  evaluation: { id: number; decision: string; task: string } | null;
  allowed_next: string[];
  missing: Record<string, string[]>;
  bindings: { task: string; profile: string; role: 'active' | 'previous' }[];
  notes?: string | null;
}

/* ---------------------------------------------------------------- queries */

export const useOverview = () => useQuery({ queryKey: ['engine', 'overview'], queryFn: () => get<Overview>('/engine/overview') });
export const usePackages = () => useQuery({ queryKey: ['engine', 'packages'], queryFn: () => get<PackageSummary[]>('/engine/packages') });
export const useEvaluations = () => useQuery({ queryKey: ['engine', 'evaluations'], queryFn: () => get<Evaluation[]>('/engine/evaluations') });

/* ---------------------------------------------------------------- labels */

const TASKS: Record<string, string> = {
  text: 'Text', asr: 'Speech recognition (ASR)', image_upscale: 'Image upscaling', frame_interpolation: 'Frame interpolation', background_removal: 'Background removal', tts: 'Text to speech (TTS)', captions: 'Captions',
  transliteration: 'Transliteration', legacy_conversion: 'Legacy conversion', suggestions: 'Suggestions',
  spelling: 'Spelling', grammar: 'Grammar', translation: 'Translation',
};
export const taskLabel = (t: string | null | undefined) => (t ? TASKS[t] ?? t.replace(/_/g, ' ') : '—');
export const taskOptions = (tasks: string[]) => tasks.map((t) => ({ value: t, label: taskLabel(t) }));

export const PROFILE_LABEL: Record<string, string> = { minimum: 'Minimum', standard: 'Standard', apple: 'Apple silicon', accelerated: 'Accelerated (GPU)' };
export const profileLabel = (p: string) => PROFILE_LABEL[p] ?? p;

export const LIFECYCLE = ['candidate', 'quarantined', 'verified', 'evaluated', 'approved', 'staged', 'active', 'retired'];

type Icon = ComponentType<SVGProps<SVGSVGElement>>;
const STATE: Record<string, { label: string; tone: BadgeTone; icon: Icon }> = {
  candidate: { label: 'Candidate', tone: 'gray', icon: SparklesIcon },
  quarantined: { label: 'Quarantined', tone: 'amber', icon: LockClosedIcon },
  verified: { label: 'Verified', tone: 'blue', icon: ShieldCheckIcon },
  evaluated: { label: 'Evaluated', tone: 'indigo', icon: CheckCircleIcon },
  approved: { label: 'Approved', tone: 'purple', icon: CheckBadgeIcon },
  staged: { label: 'Signed / staged', tone: 'blue', icon: ClockIcon },
  active: { label: 'Active', tone: 'green', icon: PlayCircleIcon },
  retired: { label: 'Retired', tone: 'gray', icon: ArchiveBoxIcon },
  revoked: { label: 'Revoked', tone: 'red', icon: NoSymbolIcon },
};
export const stateLabel = (s: string) => STATE[s]?.label ?? s;
export const stateIcon = (s: string): Icon => STATE[s]?.icon ?? QuestionMarkCircleIcon;

function IconBadge({ tone, icon: I, children }: { tone: BadgeTone; icon: Icon; children: ReactNode }) {
  return <Badge tone={tone}><I aria-hidden className="-ml-0.5 size-3.5" />{children}</Badge>;
}

export function StateBadge({ state }: { state: string }) {
  const s = STATE[state] ?? { label: state, tone: 'gray' as BadgeTone, icon: QuestionMarkCircleIcon };
  return <IconBadge tone={s.tone} icon={s.icon}>{s.label}</IconBadge>;
}

export function DecisionBadge({ decision }: { decision: string | null | undefined }) {
  if (decision === 'pass') return <IconBadge tone="green" icon={CheckCircleIcon}>Pass</IconBadge>;
  if (decision === 'fail') return <IconBadge tone="red" icon={XCircleIcon}>Fail</IconBadge>;
  if (!decision) return <IconBadge tone="gray" icon={MinusCircleIcon}>No evaluation</IconBadge>;
  return <IconBadge tone="amber" icon={ClockIcon}>{decision.charAt(0).toUpperCase() + decision.slice(1)}</IconBadge>;
}

export function CheckBadge({ status }: { status: GateCheck['status'] }) {
  if (status === 'pass') return <IconBadge tone="green" icon={CheckCircleIcon}>Pass</IconBadge>;
  if (status === 'fail') return <IconBadge tone="red" icon={XCircleIcon}>Fail</IconBadge>;
  return <IconBadge tone="amber" icon={ExclamationTriangleIcon}>Insufficient evidence</IconBadge>;
}

export function LicenceCell({ id, blocked }: { id: string; blocked: string[] }) {
  const isBlocked = blocked.map((b) => b.toUpperCase()).includes(String(id).toUpperCase());
  return (
    <span className="inline-flex flex-wrap items-center gap-1.5">
      <span className="font-mono text-xs">{id}</span>
      {isBlocked && <IconBadge tone="red" icon={NoSymbolIcon}>Blocked licence</IconBadge>}
    </span>
  );
}

export function ApprovalMark({ label, name, given }: { label: string; name?: string | null; given: boolean }) {
  return given
    ? <IconBadge tone="green" icon={CheckIcon}>{label} ✓{name ? ` ${name}` : ''}</IconBadge>
    : <IconBadge tone="gray" icon={ClockIcon}>{label} pending</IconBadge>;
}

/* ---------------------------------------------------------------- digests */

export function CopyButton({ text, label = 'Copy' }: { text: string; label?: string }) {
  const [done, setDone] = useState(false);
  return (
    <IconButton
      label={done ? 'Copied' : label}
      icon={done ? CheckIcon : ClipboardDocumentIcon}
      variant="ghost"
      size="sm"
      onClick={(e) => { e.stopPropagation(); void navigator.clipboard?.writeText(text); setDone(true); setTimeout(() => setDone(false), 1500); }}
    />
  );
}

export const short = (d: string | null | undefined, n = 12) => (d ? (d.length > n ? d.slice(0, n) : d) : '—');

/** A digest shown short in monospace, with its full value in the title and a copy button. */
export function Digest({ value, n = 12, copy = true }: { value: string | null | undefined; n?: number; copy?: boolean }) {
  if (!value) return <span className="text-slate-400 dark:text-slate-500">—</span>;
  return (
    <span className="inline-flex items-center gap-0.5">
      <code title={value} className="font-mono text-xs text-slate-700 dark:text-slate-300">{short(value, n)}</code>
      {copy && <CopyButton text={value} label="Copy digest" />}
    </span>
  );
}

export const artifactsSize = (a: Artifact[] | null | undefined) => (a ?? []).reduce((s, x) => s + Number(x.size_bytes || 0), 0);

/* ---------------------------------------------------------------- layout bits */

export function BackLink({ to, children }: { to: string; children: ReactNode }) {
  return (
    <Link to={to} className="mb-3 inline-flex items-center gap-1 text-sm font-medium text-slate-600 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white">
      <ArrowLeftIcon aria-hidden className="size-4" /> {children}
    </Link>
  );
}

/**
 * The 8 lifecycle states (+ revoked) as a stepper. With `counts`, each step shows its package count
 * (pipeline view); with `current`, steps before it are done and the current one is highlighted.
 */
export function LifecycleStepper({ counts, current }: { counts?: Record<string, number | string>; current?: string }) {
  const idx = current ? LIFECYCLE.indexOf(current) : -1;
  const steps = [...LIFECYCLE, 'revoked'];
  return (
    <ol className="grid grid-cols-[repeat(auto-fill,minmax(8.5rem,1fr))] gap-2">
      {steps.map((s, i) => {
        const I = stateIcon(s);
        const isCurrent = current === s;
        const done = !counts && idx >= 0 && i < idx && s !== 'revoked';
        const n = counts ? Number(counts[s] ?? 0) : null;
        return (
          <li
            key={s}
            aria-current={isCurrent ? 'step' : undefined}
            className={cx(
              'flex items-center gap-2 rounded-md px-2.5 py-2 text-xs ring-1 ring-inset',
              isCurrent
                ? s === 'revoked'
                  ? 'bg-red-50 text-red-800 ring-red-600/30 dark:bg-red-500/10 dark:text-red-300 dark:ring-red-400/30'
                  : 'bg-brand-50 text-brand-800 ring-brand-600/30 dark:bg-blue-500/15 dark:text-blue-200 dark:ring-blue-400/30'
                : done
                  ? 'bg-green-50 text-green-800 ring-green-600/20 dark:bg-green-500/10 dark:text-green-300 dark:ring-green-400/20'
                  : 'bg-slate-50 text-slate-600 ring-slate-200 dark:bg-white/[0.03] dark:text-slate-400 dark:ring-white/10',
              s === 'revoked' && 'border-l-2 border-dashed border-slate-300 dark:border-white/20',
            )}
          >
            {done ? <CheckIcon aria-hidden className="size-4 shrink-0" /> : <I aria-hidden className="size-4 shrink-0" />}
            <span className="min-w-0 flex-1 truncate font-medium">{stateLabel(s)}</span>
            {n !== null && <span className="font-semibold tabular-nums">{n}</span>}
            {done && <span className="sr-only">(done)</span>}
            {isCurrent && <span className="sr-only">(current)</span>}
          </li>
        );
      })}
    </ol>
  );
}

/** Error text for a mutation: the server's message is shown verbatim (409 refusals name the missing evidence). */
export function refusalText(e: unknown): string {
  if (e instanceof Error) {
    const data = (e as { data?: unknown }).data;
    if (Array.isArray(data) && data.length && data.every((x) => typeof x === 'string')) return `${e.message} ${data.join(' ')}`.trim();
    return e.message;
  }
  return 'Something went wrong.';
}

export const fmtMetric = (v: unknown) => (v === null || v === undefined || v === '' ? '—' : typeof v === 'number' ? String(Math.round(v * 10000) / 10000) : String(v));
