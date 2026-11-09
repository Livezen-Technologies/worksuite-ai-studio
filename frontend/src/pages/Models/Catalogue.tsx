import { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { BookOpenIcon, CheckCircleIcon, PlusIcon } from '@heroicons/react/24/outline';
import { Badge, Button, Card, Checkbox, EmptyState, FormModal, SearchInput, Select, SkeletonRows, Toolbar, useToast, type BadgeTone } from '../../components/ui';
import { get, post } from '../../lib/api';
import { useAuth } from '../../lib/auth';
import type { Server } from '../Servers/shared';
import { TYPE_LABEL } from './Registry';

type ModelType = keyof typeof TYPE_LABEL;
type Engine = 'ollama' | 'vllm' | 'speech';

interface Entry {
  id: string; name: string; type: ModelType; family: string; parameter_size: string; quantization: string; size_gb: number; vram_gb: number;
  context_length: number; capabilities: string[]; ollama: string | null; hf: string | null; speech: string | null; license: string;
  description: string; source: string | null; registered: boolean; installed_on: string[]; engines: Engine[];
}

const TYPE_TONE: Record<ModelType, BadgeTone> = { llm: 'indigo', embedding: 'blue', stt: 'purple', tts: 'purple', vision: 'green', multimodal: 'amber' };
const ENGINE_LABEL: Record<Engine, string> = { ollama: 'Ollama', vllm: 'vLLM / llama.cpp', speech: 'Speech service' };
const CLOUD = ['openai', 'anthropic', 'gemini'];

function AddModal({ entry, servers, onClose }: { entry: Entry; servers: Server[]; onClose: () => void }) {
  const qc = useQueryClient();
  const toast = useToast();
  const [engine, setEngine] = useState<Engine>(entry.engines[0]);
  const [ids, setIds] = useState<number[]>([]);
  const nodes = servers.filter((s) => {
    const k = s.kind ?? 'ollama';
    if (CLOUD.includes(k)) return false;
    return engine === 'ollama' ? k === 'ollama' : k !== 'ollama';
  });
  const add = useMutation({
    mutationFn: () => post('/registry/catalogue', { id: entry.id, engine, server_ids: engine === 'speech' ? [] : ids }),
    onSuccess: (_r) => {
      toast.success(`${entry.name} added`, engine === 'speech' ? 'It is listed for transcription as soon as the speech service has its files.' : ids.length ? 'Deployment started.' : 'Deploy it from the Registry tab when you are ready.');
      qc.invalidateQueries({ queryKey: ['registry'] });
      onClose();
    },
    onError: (e) => toast.error(e),
  });
  const name = engine === 'ollama' ? entry.ollama : engine === 'vllm' ? entry.hf : entry.id;
  return (
    <FormModal open onClose={onClose} title={`Add ${entry.name}`} submitLabel={ids.length ? 'Add and deploy' : 'Add to registry'} submitting={add.isPending}
      description={`Registered as ${name}. Needs about ${entry.vram_gb} GB of GPU memory.`} onSubmit={() => add.mutate()}>
      <div className="space-y-4">
        {entry.engines.length > 1 && (
          <Select label="Engine" value={engine} onChange={(e) => { setEngine(e.target.value as Engine); setIds([]); }}
            options={entry.engines.map((e) => ({ value: e, label: ENGINE_LABEL[e] }))}
            hint={engine === 'vllm' ? 'vLLM and llama.cpp nodes serve the models they were started with; deploying records the assignment.' : 'Ollama nodes download the model.'} />
        )}
        {engine === 'speech' ? (
          <p className="text-sm text-slate-600 dark:text-slate-300">Speech models run on the speech service on this server and reach GPU nodes with their speech sync. Built-in Whisper sizes are already there; once added, it can be chosen for transcription and given access rules and a default.</p>
        ) : nodes.length ? (
          <div className="space-y-2">
            <div className="text-sm font-medium text-slate-900 dark:text-white">Deploy now (optional)</div>
            {nodes.map((s) => {
              const fits = !s.gpu_vram_gb || Number(s.gpu_vram_gb) >= entry.vram_gb;
              return (
                <Checkbox key={s.id} checked={ids.includes(s.id)} disabled={!fits} onChange={() => setIds((l) => (l.includes(s.id) ? l.filter((x) => x !== s.id) : [...l, s.id]))}
                  label={<span>{s.name} {!fits && <Badge tone="red">Not enough VRAM</Badge>}
                    <span className="block text-xs text-slate-500 dark:text-slate-400">{s.gpu_vram_gb ? `${Number(s.gpu_vram_gb)} GB VRAM` : 'VRAM unknown'} · {s.status}</span></span>} />
              );
            })}
          </div>
        ) : <p className="text-sm text-slate-500 dark:text-slate-400">No {ENGINE_LABEL[engine]} node is registered yet; you can deploy later.</p>}
      </div>
    </FormModal>
  );
}

export default function Catalogue() {
  const manage = useAuth().can('models.manage');
  const [q, setQ] = useState('');
  const [type, setType] = useState('');
  const [adding, setAdding] = useState<Entry | null>(null);
  const list = useQuery({ queryKey: ['registry', 'catalogue'], queryFn: () => get<Entry[]>('/registry/catalogue') });
  const servers = useQuery({ queryKey: ['servers'], queryFn: () => get<Server[]>('/servers') });
  const s = q.trim().toLowerCase();
  const rows = useMemo(() => (list.data ?? []).filter((e) => (!type || e.type === type)
    && (!s || [e.name, e.id, e.family, e.description, ...e.capabilities].some((v) => v.toLowerCase().includes(s)))), [list.data, s, type]);

  return (
    <Card>
      <Toolbar right={<span className="text-sm text-slate-500 dark:text-slate-400">{list.data ? `${rows.length} model${rows.length === 1 ? '' : 's'}` : ''}</span>}>
        <SearchInput value={q} onChange={setQ} placeholder="Search: code, Sinhala, embeddings…" className="w-full sm:w-72" />
        <Select aria-label="Type" value={type} onChange={(e) => setType(e.target.value)} wrapperClassName="w-full sm:w-48"
          options={[{ value: '', label: 'All types' }, ...Object.entries(TYPE_LABEL).filter(([k]) => k !== 'tts').map(([value, label]) => ({ value, label }))]} />
      </Toolbar>
      {list.isLoading ? <div className="p-4"><SkeletonRows rows={6} cols={3} /></div> : !rows.length ? (
        <EmptyState icon={BookOpenIcon} title="No matches" description="Try another search or type." />
      ) : (
        <ul className="grid gap-3 p-4 sm:grid-cols-2 sm:p-6 xl:grid-cols-3">
          {rows.map((e) => (
            <li key={e.id} className="flex flex-col rounded-lg p-4 ring-1 ring-slate-200 dark:ring-white/10">
              <div className="flex items-start justify-between gap-2">
                <div className="min-w-0">
                  <div className="font-medium text-slate-900 dark:text-white">{e.name}</div>
                  <div className="truncate font-mono text-xs text-slate-500 dark:text-slate-400">{e.ollama ?? e.hf ?? e.id}</div>
                </div>
                <Badge tone={TYPE_TONE[e.type]}>{TYPE_LABEL[e.type]}</Badge>
              </div>
              <p className="mt-2 flex-1 text-sm text-slate-600 dark:text-slate-300">{e.description}</p>
              <div className="mt-2 flex flex-wrap gap-1">
                {e.capabilities.map((c) => <span key={c} className="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] text-slate-600 dark:bg-white/10 dark:text-slate-300">{c.replace('_', ' ')}</span>)}
              </div>
              <dl className="mt-3 grid grid-cols-3 gap-2 text-xs">
                <div><dt className="text-slate-500 dark:text-slate-400">Size</dt><dd className="font-medium text-slate-900 dark:text-white">{e.parameter_size} · {e.size_gb < 1 ? `${Math.round(e.size_gb * 1000)} MB` : `${e.size_gb} GB`}</dd></div>
                <div><dt className="text-slate-500 dark:text-slate-400">GPU memory</dt><dd className="font-medium text-slate-900 dark:text-white">{e.vram_gb} GB</dd></div>
                <div><dt className="text-slate-500 dark:text-slate-400">Context</dt><dd className="font-medium text-slate-900 dark:text-white">{e.context_length >= 1024 ? `${Math.round(e.context_length / 1024)}K` : e.context_length}</dd></div>
              </dl>
              <div className="mt-2 text-xs text-slate-500 dark:text-slate-400">
                {e.engines.map((x) => ENGINE_LABEL[x]).join(' · ')} · {e.license}
                {e.source && <> · <a href={e.source} target="_blank" rel="noreferrer noopener" className="text-brand-600 hover:underline dark:text-blue-400">source</a></>}
              </div>
              <div className="mt-3 flex items-center justify-between gap-2">
                <div className="flex flex-wrap gap-1">
                  {e.registered && <Badge tone="green"><CheckCircleIcon className="mr-0.5 inline size-3" />In registry</Badge>}
                  {e.installed_on.length > 0 && <span title={e.installed_on.join(", ")}><Badge tone="gray">On {e.installed_on.length > 1 ? `${e.installed_on.length} nodes` : e.installed_on[0]}</Badge></span>}
                </div>
                {manage && !e.registered && <Button size="sm" icon={PlusIcon} onClick={() => setAdding(e)}>Add</Button>}
              </div>
            </li>
          ))}
        </ul>
      )}
      {adding && <AddModal entry={adding} servers={servers.data ?? []} onClose={() => setAdding(null)} />}
    </Card>
  );
}
