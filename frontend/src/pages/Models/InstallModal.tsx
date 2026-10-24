import { useMemo, useRef, useState } from 'react';
import { ArrowDownTrayIcon, CheckCircleIcon, EyeIcon } from '@heroicons/react/20/solid';
import { Alert, Badge, Button, Input, Meter, Modal, SearchInput, Segmented, Select, cx } from '../../components/ui';
import { streamNdjson } from '../../lib/api';
import { bytes } from '../../lib/format';
import type { Server } from '../Servers/shared';
import { CATALOGUE, CATEGORIES, isInstalled } from './catalogue';

interface Progress { status?: string; total?: number; completed?: number; error?: string; done?: boolean }

export default function InstallModal({ open, servers, installed, onClose, onDone }: {
  open: boolean; servers: Server[]; installed: string[]; onClose: () => void; onDone: (name: string) => void;
}) {
  const nodes = useMemo(() => servers.filter((s) => (s.kind ?? 'ollama') === 'ollama'), [servers]);
  const have = useMemo(() => new Set(installed), [installed]);
  const [serverId, setServerId] = useState<number | ''>('');
  const [name, setName] = useState('');
  const [search, setSearch] = useState('');
  const [cat, setCat] = useState<string>('All');
  const [busy, setBusy] = useState(false);
  const [progress, setProgress] = useState<Progress | null>(null);
  const [log, setLog] = useState<string[]>([]);
  const [error, setError] = useState('');
  const [finished, setFinished] = useState(false);
  const abort = useRef<AbortController | null>(null);
  const target = serverId || nodes[0]?.id || '';

  const list = useMemo(() => {
    const q = search.trim().toLowerCase();
    return CATALOGUE.filter((m) => (cat === 'All' || m.category === cat) && (!q || m.name.includes(q) || m.desc.toLowerCase().includes(q)));
  }, [search, cat]);
  const pct = progress?.total ? Math.min(100, Math.round(((progress.completed ?? 0) / progress.total) * 100)) : undefined;

  const install = async () => {
    if (!target || !name.trim()) { setError('Pick a node and a model.'); return; }
    setError(''); setBusy(true); setProgress(null); setLog([]); setFinished(false);
    const ctl = new AbortController();
    abort.current = ctl;
    let failed = false;
    try {
      await streamNdjson('/models/pull', { server_id: target, name: name.trim() }, (line) => {
        const p = line as Progress;
        setProgress(p);
        if (p.error) { setError(p.error); failed = true; }
        if (p.status) setLog((l) => (l[l.length - 1] === p.status ? l : [...l.slice(-5), p.status!]));
      }, ctl.signal);
      if (!failed) { setFinished(true); onDone(name.trim()); }
    } catch (e) {
      if (!ctl.signal.aborted) setError(e instanceof Error ? e.message : 'Install failed.');
    } finally {
      setBusy(false);
    }
  };

  const close = () => {
    abort.current?.abort();
    setBusy(false); setProgress(null); setError(''); setName(''); setSearch(''); setCat('All'); setLog([]); setFinished(false);
    onClose();
  };

  return (
    <Modal
      open={open}
      onClose={close}
      size="lg"
      title="Install a model"
      description="Pull a model from the Ollama library onto one of your nodes."
      footer={<>
        <Button onClick={close}>{busy ? 'Cancel' : 'Close'}</Button>
        <Button variant="primary" icon={ArrowDownTrayIcon} loading={busy} disabled={!name.trim() || !target} onClick={install}>
          {busy ? 'Installing…' : 'Install'}
        </Button>
      </>}
    >
      <div className="space-y-4">
        {nodes.length === 0 && <Alert tone="warning">Register an Ollama node on the Servers page first. Hosted providers do not install models.</Alert>}
        <div className="grid gap-3 sm:grid-cols-2">
          <Select label="Target node" value={target} disabled={busy || !nodes.length}
            onChange={(e) => setServerId(Number(e.target.value))}
            options={nodes.map((s) => ({ value: s.id, label: `${s.name} (${s.status})` }))}
            placeholder={nodes.length ? undefined : 'No Ollama nodes'} />
          <div className="sm:pt-7"><SearchInput value={search} onChange={setSearch} placeholder="Search the catalogue…" /></div>
        </div>
        <div className="overflow-x-auto">
          <Segmented label="Category" value={cat} onChange={setCat} options={['All', ...CATEGORIES].map((c) => ({ value: c, label: c }))} />
        </div>
        <div className="max-h-72 space-y-1.5 overflow-y-auto pr-1">
          {list.length === 0 && <p className="py-8 text-center text-sm text-slate-500 dark:text-slate-400">No catalogue matches. Type any tag in the field below.</p>}
          {list.map((m) => {
            const selected = name === m.name;
            return (
              <button key={m.name} type="button" disabled={busy} onClick={() => setName(m.name)}
                className={cx('flex w-full items-start gap-3 rounded-md border px-3 py-2 text-left transition-colors disabled:cursor-not-allowed disabled:opacity-60',
                  selected ? 'border-brand-500 bg-brand-50 ring-1 ring-brand-500 dark:border-blue-400 dark:bg-blue-500/10 dark:ring-blue-400'
                    : 'border-slate-200 hover:bg-slate-50 dark:border-white/10 dark:hover:bg-white/5')}>
                <div className="min-w-0 flex-1">
                  <div className="flex flex-wrap items-center gap-2">
                    <span className="font-mono text-sm font-medium text-slate-900 dark:text-white">{m.name}</span>
                    {m.vision && <Badge tone="purple"><EyeIcon className="size-3" /> vision</Badge>}
                    {isInstalled(m.name, have) && <Badge tone="green"><CheckCircleIcon className="size-3" /> installed</Badge>}
                  </div>
                  <div className="mt-0.5 truncate text-xs text-slate-500 dark:text-slate-400">{m.desc}</div>
                </div>
                <div className="shrink-0 text-right">
                  <Badge>{m.category}</Badge>
                  <div className="mt-1 text-xs text-slate-500 dark:text-slate-400">{m.size}</div>
                </div>
              </button>
            );
          })}
        </div>
        <Input label="Or install any other tag" value={name} disabled={busy} onChange={(e) => setName(e.target.value)}
          placeholder="e.g. qwen2.5:32b, llama3.3, gemma2:27b" hint="Any model from ollama.com/library. Picking one above fills this in." />
        {(busy || progress) && (
          <div className="rounded-md border border-slate-200 bg-slate-50 p-3 dark:border-white/10 dark:bg-white/5">
            {pct !== undefined ? (
              <Meter value={pct} label={`${progress?.status ?? 'Downloading'}${progress?.total ? ` · ${bytes(progress.completed ?? 0)} of ${bytes(progress.total)}` : ''}`} />
            ) : (
              <div className="h-1.5 overflow-hidden rounded-full bg-slate-200 dark:bg-white/10">
                <div className={cx('h-full w-1/3 rounded-full bg-brand-600 dark:bg-blue-500', busy && 'animate-pulse')} />
              </div>
            )}
            <div className="mt-2 font-mono text-xs leading-relaxed text-slate-500 dark:text-slate-400">
              {log.map((l, i) => <div key={i}>{l}</div>)}
              {finished && !error && <div className="text-green-600 dark:text-green-400">Install complete.</div>}
            </div>
          </div>
        )}
        {error && <Alert tone="danger">{error}</Alert>}
      </div>
    </Modal>
  );
}
