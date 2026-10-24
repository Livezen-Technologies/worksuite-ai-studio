import { useState } from 'react';
import { useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { CubeIcon, PlusIcon } from '@heroicons/react/24/outline';
import {
  Alert, Button, Card, DataTable, EmptyState, FormModal, Input, PageHeader, SearchInput, Select, Textarea, Toolbar, useToast,
  type Column,
} from '../../components/ui';
import { post } from '../../lib/api';
import { useAuth } from '../../lib/auth';
import { ago, bytes, dateTime } from '../../lib/format';
import PackageDetail from './PackageDetail';
import {
  BackLink, DecisionBadge, LicenceCell, StateBadge, artifactsSize, refusalText, stateLabel, taskLabel, taskOptions,
  useEvaluations, useOverview, usePackages, type PackageSummary,
} from './shared';

export default function EnginePackages() {
  const id = useParams()['*'];
  return id ? <PackageDetail id={id} /> : <PackageList />;
}

function PackageList() {
  const { can } = useAuth();
  const canManage = can('engine.manage');
  const nav = useNavigate();
  const [params, setParams] = useSearchParams();
  const [q, setQ] = useState('');
  const [task, setTask] = useState('');
  const [state, setState] = useState('');
  const list = usePackages();
  const ov = useOverview();
  const blocked = ov.data?.blocked_licenses ?? [];

  const rows = (list.data ?? []).filter((p) => (!task || p.task === task) && (!state || p.state === state)
    && (!q || `${p.version} ${p.package_id} ${p.base_model ?? ''} ${p.license_id} ${p.runtime_name}`.toLowerCase().includes(q.toLowerCase())));

  const openNew = () => setParams((p) => { p.set('new', '1'); return p; });
  const closeNew = () => setParams((p) => { p.delete('new'); return p; });

  const columns: Column<PackageSummary>[] = [
    { key: 'task', header: 'Task', cell: (p) => <div><div>{taskLabel(p.task)}</div><div className="text-xs font-normal text-slate-500 sm:hidden dark:text-slate-400">v{p.version}</div></div> },
    { key: 'ver', header: 'Version', hideBelow: 'sm', cell: (p) => <span className="font-mono text-xs">v{p.version}</span> },
    { key: 'rt', header: 'Runtime', hideBelow: 'lg', cell: (p) => <span>{p.runtime_name} <span className="text-xs text-slate-500 dark:text-slate-400">{p.runtime_range}</span></span> },
    { key: 'lic', header: 'Licence', hideBelow: 'md', cell: (p) => <LicenceCell id={p.license_id} blocked={blocked} /> },
    { key: 'state', header: 'State', cell: (p) => <StateBadge state={p.state} /> },
    { key: 'eval', header: 'Evaluation', hideBelow: 'md', cell: (p) => <DecisionBadge decision={p.evaluation_decision} /> },
    { key: 'size', header: 'Size', hideBelow: 'lg', align: 'right', cell: (p) => <span className="tabular-nums">{bytes(artifactsSize(p.artifacts))}</span> },
    { key: 'upd', header: 'Updated', hideBelow: 'sm', cell: (p) => <span title={dateTime(p.updated_at)}>{ago(p.updated_at)}</span> },
  ];

  return (
    <>
      <BackLink to="/engine">Engine overview</BackLink>
      <PageHeader
        title="Package registry"
        description="Every engine package and where it is in the release lifecycle."
        actions={canManage ? <Button variant="primary" icon={PlusIcon} onClick={openNew}>Register candidate</Button> : undefined}
      />
      <Card>
        <Toolbar right={
          <>
            <Select aria-label="Task" value={task} onChange={(e) => setTask(e.target.value)} placeholder="All tasks" options={taskOptions(Object.keys(ov.data?.gates ?? {}))} />
            <Select aria-label="State" value={state} onChange={(e) => setState(e.target.value)} placeholder="All states" options={(ov.data?.states ?? []).map((s) => ({ value: s, label: stateLabel(s) }))} />
          </>
        }>
          <SearchInput value={q} onChange={setQ} placeholder="Search version, model, licence…" />
        </Toolbar>
        <DataTable
          columns={columns}
          rows={list.data ? rows : undefined}
          rowKey={(p) => p.id}
          loading={list.isLoading}
          error={list.error}
          onRetry={() => list.refetch()}
          onRowClick={(p) => nav(`/engine/packages/${p.id}`)}
          empty={<EmptyState icon={CubeIcon} title={list.data?.length ? 'No packages match' : 'No packages registered'}
            description="Register a candidate, upload its artifacts, then move it through quarantine, verification and approval."
            action={canManage ? <Button variant="primary" icon={PlusIcon} onClick={openNew}>Register candidate</Button> : undefined} />}
        />
      </Card>
      {canManage && <RegisterForm open={params.get('new') === '1'} onClose={closeNew} onCreated={(id) => nav(`/engine/packages/${id}`)} />}
    </>
  );
}

const blank = { package_id: '', version: '', task: '', languages: 'si', runtime_name: '', runtime_range: '', base_model: '', quantization: '', license_id: '', rights_approval_id: '', evaluation_uuid: '', notes: '' };

function RegisterForm({ open, onClose, onCreated }: { open: boolean; onClose: () => void; onCreated: (id: number) => void }) {
  const ov = useOverview();
  const evals = useEvaluations();
  const toast = useToast();
  const qc = useQueryClient();
  const [f, setF] = useState(blank);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const set = (k: keyof typeof blank) => (e: { target: { value: string } }) => setF((x) => ({ ...x, [k]: e.target.value }));
  const isBlocked = !!f.license_id && (ov.data?.blocked_licenses ?? []).map((b) => b.toUpperCase()).includes(f.license_id.trim().toUpperCase());

  const save = useMutation({
    mutationFn: () => post<PackageSummary>('/engine/packages', {
      ...(f.package_id.trim() ? { package_id: f.package_id.trim() } : {}),
      version: f.version.trim(), task: f.task,
      languages: f.languages.split(',').map((s) => s.trim()).filter(Boolean),
      runtime_name: f.runtime_name, runtime_range: f.runtime_range.trim(),
      base_model: f.base_model || null, quantization: f.quantization || null,
      license_id: f.license_id.trim(), rights_approval_id: f.rights_approval_id || null,
      evaluation_uuid: f.evaluation_uuid || null, notes: f.notes || null, artifacts: [],
    }),
    onSuccess: (p) => { toast.success('Package registered as candidate'); void qc.invalidateQueries({ queryKey: ['engine'] }); setF(blank); onClose(); if (p?.id) onCreated(p.id); },
    onError: (e) => toast.error(refusalText(e)),
  });

  const submit = () => {
    const errs: Record<string, string> = {};
    if (!f.task) errs.task = 'Choose a task.';
    if (!/^\d+\.\d+\.\d+$/.test(f.version.trim())) errs.version = 'Semver, e.g. 1.0.0.';
    if (!f.runtime_name) errs.runtime_name = 'Choose a runtime.';
    if (!f.runtime_range.trim()) errs.runtime_range = 'Pin the compatible runtime bounds, e.g. >=0.5.0 <0.6.0.';
    if (!f.license_id.trim()) errs.license_id = 'Required.';
    if (f.package_id.trim() && !/^[0-9a-f-]{36}$/i.test(f.package_id.trim())) errs.package_id = 'A UUID, or leave empty to generate one.';
    setErrors(errs);
    if (!Object.keys(errs).length) save.mutate();
  };

  const evalOptions = (evals.data ?? []).filter((e) => !f.task || e.task === f.task)
    .map((e) => ({ value: e.uuid, label: `#${e.id} ${taskLabel(e.task)} — ${e.decision} — ${e.candidate_digest.slice(0, 10)}` }));

  return (
    <FormModal open={open} onClose={onClose} title="Register candidate" description="The package starts as a candidate. Artifacts are uploaded on its detail page." onSubmit={submit} submitLabel="Register" submitting={save.isPending} size="lg">
      <div className="grid gap-4 sm:grid-cols-2">
        <Select label="Task" required value={f.task} error={errors.task} onChange={set('task')} placeholder="Choose…" options={taskOptions(Object.keys(ov.data?.gates ?? {}))} />
        <Input label="Version" required placeholder="1.0.0" value={f.version} error={errors.version} onChange={set('version')} />
        <Input label="Package id" wrapperClassName="sm:col-span-2" className="font-mono" placeholder="Generated automatically" hint="Leave empty for a new UUID." value={f.package_id} error={errors.package_id} onChange={set('package_id')} />
        <Input label="Languages" hint="Comma separated codes." value={f.languages} onChange={set('languages')} />
        <Select label="Runtime" required value={f.runtime_name} error={errors.runtime_name} onChange={set('runtime_name')} placeholder="Choose…" options={(ov.data?.runtimes ?? []).map((r) => ({ value: r, label: r }))} />
        <Input label="Runtime range" required wrapperClassName="sm:col-span-2" placeholder=">=0.5.0 <0.6.0" value={f.runtime_range} error={errors.runtime_range} onChange={set('runtime_range')} />
        <Input label="Base model" value={f.base_model} onChange={set('base_model')} />
        <Input label="Quantization" placeholder="q4_K_M" value={f.quantization} onChange={set('quantization')} />
        <Input label="Licence id" required placeholder="Apache-2.0" value={f.license_id} error={errors.license_id} onChange={set('license_id')} />
        <Input label="Rights approval id" value={f.rights_approval_id} onChange={set('rights_approval_id')} />
        <Select label="Evaluation" wrapperClassName="sm:col-span-2" value={f.evaluation_uuid} onChange={set('evaluation_uuid')} placeholder={evals.isLoading ? 'Loading…' : 'None yet'} options={evalOptions} />
      </div>
      {isBlocked && <Alert tone="danger" title="Blocked licence">{f.license_id} blocks commercial bundling. The package can be registered but will be refused at approval.</Alert>}
      <Textarea label="Notes" rows={3} value={f.notes} onChange={set('notes')} />
    </FormModal>
  );
}
