import { useState } from 'react';
import { useMutation } from '@tanstack/react-query';
import { FormModal, Input, Segmented, Select, Toggle, useToast } from '../../components/ui';
import { post, put } from '../../lib/api';
import { ago } from '../../lib/format';
import { n2, type Kind, type Node, type Srv } from './shared';

export function NodeForm({ node, servers, onClose, onSaved }: { node: Node | null; servers: Srv[]; onClose: () => void; onSaved: () => void }) {
  const toast = useToast();
  const [kind, setKind] = useState<Kind>(node?.kind ?? 'local');
  const [name, setName] = useState(node?.name ?? '');
  const [path, setPath] = useState(node?.config.path ?? '');
  const [serverId, setServerId] = useState(String(node?.config.server_id ?? servers[0]?.id ?? ''));
  const [endpoint, setEndpoint] = useState(node?.config.endpoint ?? '');
  const [bucket, setBucket] = useState(node?.config.bucket ?? '');
  const [s3region, setS3region] = useState(node?.config.region ?? '');
  const [pathStyle, setPathStyle] = useState(node?.config.path_style ?? true);
  const [prefix, setPrefix] = useState(node?.config.prefix ?? 'aistudio');
  const [accessKey, setAccessKey] = useState('');
  const [secretKey, setSecretKey] = useState('');
  const [quota, setQuota] = useState(node?.quota_bytes ? String(Math.round(node.quota_bytes / 1073741824)) : '');
  const [region, setRegion] = useState(node?.region ?? '');
  const [tags, setTags] = useState(node?.tags ?? '');
  const [priority, setPriority] = useState(String(node?.priority ?? 100));
  const [readonly, setReadonly] = useState(Number(node?.readonly ?? 0) === 1);
  const save = useMutation({
    mutationFn: () => {
      const body = {
        name, kind, quota_gb: quota, region, tags, priority: Number(priority) || 100, readonly,
        config: kind === 's3' ? { endpoint, bucket, region: s3region, path_style: pathStyle, prefix } : kind === 'agent' ? { server_id: Number(serverId), path } : { path },
        secret: kind === 's3' ? { access_key: accessKey, secret_key: secretKey } : undefined,
      };
      return node ? put<Node>(`/storage/nodes/${node.id}`, body) : post<Node>('/storage/nodes', body);
    },
    onSuccess: (n) => {
      if (n.health === 'down') toast.error(`Saved, but the check failed: ${n.last_error}`);
      else toast.success(node ? 'Saved and checked' : 'Node added and checked', `${n2(n.write_mbps, 'MB/s')} write · ${n2(n.read_mbps, 'MB/s')} read`);
      onSaved();
    },
    onError: (e) => toast.error(e),
  });
  return (
    <FormModal open onClose={onClose} size="lg" title={node ? `Edit ${node.name}` : 'Add a storage node'}
      description="Saving runs a check: it writes, reads back and deletes a 4 MB test file, and measures capacity and speed."
      submitLabel={node ? 'Save and check' : 'Add and check'} submitting={save.isPending} onSubmit={() => save.mutate()}>
      <div className="grid gap-4 sm:grid-cols-2">
        {!node && (
          <div className="sm:col-span-2">
            <Segmented label="Type" value={kind} onChange={setKind}
              options={[{ value: 'local', label: 'Local disk' }, { value: 'mounted', label: 'Network mount' }, { value: 's3', label: 'S3-compatible' }, { value: 'agent', label: 'VPS / node' }]} />
          </div>
        )}
        <Input label="Name" required value={name} onChange={(e) => setName(e.target.value)} placeholder={kind === 's3' ? 'Garage — primary' : 'Data disk 2'} wrapperClassName="sm:col-span-2" />
        {kind === 'agent' ? <>
          <Select label="Node" value={serverId} onChange={(e) => setServerId(e.target.value)} wrapperClassName="sm:col-span-2"
            options={servers.length ? servers.map((v) => ({ value: String(v.id), label: `${v.name}${v.agent_seen_at ? ` — agent ${v.agent_version ?? ''}, seen ${ago(v.agent_seen_at)}` : ' — no agent yet'}` })) : [{ value: '', label: 'No enrolled nodes — enrol one on the Servers page' }]}
            hint="A server enrolled with the node installer and running the training agent 1.4.0 or newer. Files travel through its tunnel; nothing is opened on its firewall." />
          <Input label="Folder on the node" required value={path} onChange={(e) => setPath(e.target.value)} placeholder="aistudio-storage or /data/aistudio" wrapperClassName="sm:col-span-2"
            hint="A relative folder is under the agent user's home. The agent starts the storage helper within a minute; then run Test." />
        </> : kind !== 's3' ? (
          <Input label="Folder on this server" required value={path} onChange={(e) => setPath(e.target.value)} placeholder={kind === 'mounted' ? '/mnt/nas-storage' : '/srv/storage'} wrapperClassName="sm:col-span-2"
            hint={kind === 'mounted'
              ? 'The mount point of an NFS or SMB share (mount it on the server first, e.g. in /etc/fstab). The check refuses it while the share is not mounted.'
              : 'A dedicated folder on a disk of this server, writable by www-data.'} />
        ) : <>
          <Input label="Endpoint" required value={endpoint} onChange={(e) => setEndpoint(e.target.value)} placeholder="https://s3.eu-central-1.amazonaws.com" wrapperClassName="sm:col-span-2" />
          <Input label="Bucket" required value={bucket} onChange={(e) => setBucket(e.target.value)} placeholder="aistudio-data" />
          <Input label="Region" value={s3region} onChange={(e) => setS3region(e.target.value)} placeholder="us-east-1 (Garage: garage)" />
          <Input label="Access key" value={accessKey} onChange={(e) => setAccessKey(e.target.value)} autoComplete="off" placeholder={node?.has_secret ? 'Stored — leave empty to keep' : ''} />
          <Input label="Secret key" type="password" value={secretKey} onChange={(e) => setSecretKey(e.target.value)} autoComplete="new-password" placeholder={node?.has_secret ? 'Stored — leave empty to keep' : ''} />
          <Input label="Folder in the bucket" value={prefix} onChange={(e) => setPrefix(e.target.value)} hint="Everything AI Studio stores goes under this prefix." />
          <div className="flex items-end pb-1"><Toggle checked={pathStyle} onChange={setPathStyle} label="Path-style URLs" description="On for Garage and MinIO; off for AWS virtual-hosted buckets." /></div>
          <p className="text-xs/5 text-slate-500 sm:col-span-2 dark:text-slate-400">Keys are stored encrypted and never shown again.</p>
        </>}
        <Input label="Quota (GB)" type="number" value={quota} onChange={(e) => setQuota(e.target.value)} placeholder={kind === 's3' ? 'e.g. 500' : 'the whole disk'}
          hint={kind === 's3' ? 'Buckets have no size of their own; set how much AI Studio may use.' : 'Optional cap below the disk size.'} />
        <Input label="Priority" type="number" value={priority} onChange={(e) => setPriority(e.target.value)} hint="Lower is preferred when placing new data." />
        <Input label="Region / site" value={region} onChange={(e) => setRegion(e.target.value)} placeholder="Colombo DC" />
        <Input label="Tags" value={tags} onChange={(e) => setTags(e.target.value)} placeholder="fast, backups" />
        <div className="sm:col-span-2"><Toggle checked={readonly} onChange={setReadonly} label="Read-only" description="Checked and readable, never written to (e.g. an archive)." /></div>
      </div>
    </FormModal>
  );
}
