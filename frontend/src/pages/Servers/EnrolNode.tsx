import { useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { CheckIcon, ClipboardDocumentIcon } from '@heroicons/react/24/outline';
import { Alert, Button, CodeBlock, IconButton, Input, Modal, Segmented, useToast } from '../../components/ui';
import { post } from '../../lib/api';

interface Enrolment { token: string; expires_at: string; commands: { unix: string; windows: string } }
type Os = 'unix' | 'windows';

/**
 * Connect a node: issue a one-time token and show the aistudio-node command for the machine.
 * The tool installs Ollama if needed, registers the machine and keeps a reverse SSH tunnel to this server.
 */
export default function EnrolNode({ onClose }: { onClose: () => void }) {
  const qc = useQueryClient();
  const toast = useToast();
  const [label, setLabel] = useState('');
  const [os, setOs] = useState<Os>(() => (navigator.userAgent.includes('Windows') ? 'windows' : 'unix'));

  const issue = useMutation({
    mutationFn: () => post<Enrolment>('/servers/enrolments', { label, hours: 24 }),
    onError: (e) => toast.error(e),
  });
  const close = () => { qc.invalidateQueries({ queryKey: ['servers'] }); onClose(); };
  const e = issue.data;

  return (
    <Modal
      open
      onClose={close}
      size="lg"
      title="Connect a node"
      description="Run one command on a Mac, Linux or Windows machine to add it as an Ollama node. No open ports or VPN needed."
      footer={e
        ? <Button variant="primary" onClick={close}>Done</Button>
        : <>
            <Button onClick={close}>Cancel</Button>
            <Button variant="primary" loading={issue.isPending} onClick={() => issue.mutate()}>Create command</Button>
          </>}
    >
      {!e ? (
        <div className="space-y-4">
          <Input label="Node name (optional)" placeholder="e.g. Training PC — RTX 4090" value={label} onChange={(ev) => setLabel(ev.target.value)} hint="Defaults to the machine's hostname." />
          <p className="text-sm text-slate-500 dark:text-slate-400">
            The command contains a one-time token valid for 24 hours. The machine connects out to this server over SSH as a
            tunnel-only user that can do nothing but forward its own Ollama port. Removing the node revokes its access.
          </p>
        </div>
      ) : (
        <div className="space-y-4">
          <Segmented label="Operating system" value={os} onChange={setOs} options={[{ value: 'unix', label: 'macOS / Linux' }, { value: 'windows', label: 'Windows' }]} />
          <div>
            <div className="mb-1 flex items-center justify-between text-sm font-medium text-slate-700 dark:text-slate-200">
              {os === 'windows' ? 'Run in PowerShell' : 'Run in Terminal'}
              <CopyButton text={e.commands[os]} />
            </div>
            <CodeBlock className="whitespace-pre-wrap break-all">{e.commands[os]}</CodeBlock>
          </div>
          <Alert tone="warning" title="Shown once">
            Anyone with this command can add a node until it is used or expires ({e.expires_at}). Don't post it in shared channels.
          </Alert>
          <p className="text-sm text-slate-500 dark:text-slate-400">
            The node shows as online after the next health check (within 5 minutes). It reconnects by itself after restarts. To
            disconnect it, run the same command with <code>{os === 'windows' ? '-Uninstall' : '--uninstall'}</code> in place of the
            token, then remove it here.
          </p>
        </div>
      )}
    </Modal>
  );
}

function CopyButton({ text }: { text: string }) {
  const [done, setDone] = useState(false);
  return (
    <IconButton
      label={done ? 'Copied' : 'Copy'}
      icon={done ? CheckIcon : ClipboardDocumentIcon}
      variant="ghost"
      size="sm"
      onClick={() => { void navigator.clipboard?.writeText(text); setDone(true); setTimeout(() => setDone(false), 1500); }}
    />
  );
}
