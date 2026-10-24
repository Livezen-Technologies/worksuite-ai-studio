import { useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { FormModal, FormSection, Input, Select, Textarea, Toggle, Alert, useToast } from '../../components/ui';
import { ApiError, post, put } from '../../lib/api';
import { KINDS, type Kind, type Server } from './shared';

type Form = Record<string, string | number | boolean>;

function initial(s: Server | null): Form {
  if (!s) return { kind: 'ollama', name: '', description: '', ip_address: '', hostname: '', port: 11434, api_url: '', auth_token: '', cpu_cores: '', ram_gb: '', storage_gb: '', gpu_model: '', cuda_version: '', os: '', is_local: false };
  return {
    kind: s.kind ?? 'ollama', name: s.name, description: s.description ?? '', ip_address: s.ip_address ?? '', hostname: s.hostname ?? '',
    port: s.port ?? 11434, api_url: s.api_url ?? '', auth_token: '', cpu_cores: s.cpu_cores ?? '', ram_gb: s.ram_gb ? Number(s.ram_gb) : '',
    storage_gb: s.storage_gb ? Number(s.storage_gb) : '', gpu_model: s.gpu_model ?? '', cuda_version: s.cuda_version ?? '', os: s.os ?? '',
    is_local: Number(s.is_local) === 1,
  };
}

export default function ServerForm({ server, onClose }: { server: Server | null; onClose: () => void }) {
  const qc = useQueryClient();
  const toast = useToast();
  const [f, setF] = useState<Form>(() => initial(server));
  const [errors, setErrors] = useState<Record<string, string>>({});
  const kind = String(f.kind) as Kind;
  const external = kind !== 'ollama';
  const meta = KINDS[kind];
  const set = (k: string) => (e: { target: { value: string } }) => setF((p) => ({ ...p, [k]: e.target.value }));

  const save = useMutation({
    mutationFn: () => {
      const body: Form = { ...f, is_local: f.is_local ? 1 : 0 };
      if (external) for (const k of ['ip_address', 'hostname', 'port', 'cpu_cores', 'ram_gb', 'storage_gb', 'gpu_model', 'cuda_version', 'os']) delete body[k];
      return server ? put<Server>(`/servers/${server.id}`, body) : post<Server>('/servers', body);
    },
    onSuccess: () => {
      toast.success(server ? 'Server updated' : external ? 'Provider connected' : 'Server registered');
      qc.invalidateQueries({ queryKey: ['servers'] });
      if (server) qc.invalidateQueries({ queryKey: ['server', server.id] });
      qc.invalidateQueries({ queryKey: ['models'] });
      onClose();
    },
    onError: (e) => { if (e instanceof ApiError) setErrors(e.fields); toast.error(e); },
  });

  return (
    <FormModal
      open
      onClose={onClose}
      size="lg"
      title={server ? 'Edit connection' : 'Add a server or provider'}
      description="A self-hosted Ollama node, or a commercial LLM API used as a model source."
      submitLabel={server ? 'Save' : external ? 'Connect' : 'Register'}
      submitting={save.isPending}
      onSubmit={() => save.mutate()}
    >
      <div className="space-y-6">
        <FormSection title="Connection">
          <div className="grid gap-4 sm:grid-cols-2">
            <Select
              label="Type"
              value={kind}
              disabled={!!server}
              hint={server ? 'The type cannot change after creation.' : undefined}
              onChange={set('kind')}
              options={(Object.keys(KINDS) as Kind[]).map((k) => ({ value: k, label: KINDS[k].label }))}
            />
            <Input label="Name" required value={String(f.name)} onChange={set('name')} error={errors.name}
              placeholder={external ? `e.g. ${meta.label}` : 'e.g. GPU node — Frankfurt'} />
          </div>
          <Textarea label="Description" rows={2} value={String(f.description)} onChange={set('description')} placeholder="Optional notes" />
          {external ? (
            <>
              <Input
                label="API key"
                type="password"
                autoComplete="new-password"
                required={!server}
                value={String(f.auth_token)}
                onChange={set('auth_token')}
                error={errors.auth_token}
                placeholder={server ? (server.has_token ? '•••••••• (unchanged)' : 'No key stored') : meta.keyHint}
                hint={server ? 'Leave blank to keep the current key.' : 'Stored on the server; never sent back to the browser.'}
              />
              <Input label="API base URL" value={String(f.api_url)} onChange={set('api_url')} error={errors.api_url}
                placeholder={meta.base} hint={`Optional. Defaults to ${meta.base}`} />
              <Alert tone="info">Models from this provider appear in the Chat playground and the API gateway next to your local models.</Alert>
            </>
          ) : (
            <>
              <div className="grid gap-4 sm:grid-cols-3">
                <Input wrapperClassName="sm:col-span-2" label="IP address" value={String(f.ip_address)} onChange={set('ip_address')} error={errors.ip_address}
                  placeholder="40.160.57.17" hint="The API URL is built from this and the port when left blank." />
                <Input label="Ollama port" type="number" min={1} max={65535} value={String(f.port)} onChange={set('port')} error={errors.port} />
              </div>
              <div className="grid gap-4 sm:grid-cols-2">
                <Input label="Hostname" value={String(f.hostname)} onChange={set('hostname')} placeholder="gpu-01.internal" />
                <Input label="API URL" value={String(f.api_url)} onChange={set('api_url')} error={errors.api_url}
                  placeholder="http://40.160.57.17:11434" hint="Overrides IP and port." />
              </div>
              <Input label="Auth token" type="password" autoComplete="new-password" value={String(f.auth_token)} onChange={set('auth_token')}
                placeholder={server?.has_token ? '•••••••• (unchanged)' : 'Optional'}
                hint={server ? 'Sent as a Bearer token to the node. Leave blank to keep the current one.' : 'Sent as a Bearer token to the node, if it needs one.'} />
            </>
          )}
        </FormSection>
        {!external && (
          <FormSection title="Hardware" description="For your reference and capacity planning.">
            <div className="grid gap-4 sm:grid-cols-3">
              <Input label="CPU cores" type="number" min={1} value={String(f.cpu_cores)} onChange={set('cpu_cores')} />
              <Input label="RAM (GB)" type="number" min={1} step="0.1" value={String(f.ram_gb)} onChange={set('ram_gb')} />
              <Input label="Storage (GB)" type="number" min={1} step="0.1" value={String(f.storage_gb)} onChange={set('storage_gb')} />
              <Input label="GPU" value={String(f.gpu_model)} onChange={set('gpu_model')} placeholder="CPU-only" />
              <Input label="CUDA version" value={String(f.cuda_version)} onChange={set('cuda_version')} placeholder="12.4" />
              <Input label="OS" value={String(f.os)} onChange={set('os')} placeholder="Ubuntu 24.04" />
            </div>
            <Toggle checked={Boolean(f.is_local)} onChange={(v) => setF((p) => ({ ...p, is_local: v }))}
              label="Local node" description="Runs on the same machine as AI Studio." />
          </FormSection>
        )}
      </div>
    </FormModal>
  );
}
