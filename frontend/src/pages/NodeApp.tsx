import { useQuery } from '@tanstack/react-query';
import { ArrowDownTrayIcon, CommandLineIcon } from '@heroicons/react/24/outline';
import { Alert, Badge, Card, CardHeader, CodeBlock, PageHeader } from '../components/ui';

/** public/node/downloads/manifest.json — written when installers are uploaded (see node-app/README.md). */
interface Manifest {
  version: string;
  released: string;
  files: { platform: 'windows' | 'macos' | 'linux'; label: string; file: string; size: number }[];
}

const PLATFORMS = [
  { key: 'windows', name: 'Windows', note: 'Windows 10 or 11, 64-bit' },
  { key: 'macos', name: 'macOS', note: 'macOS 11 or later, Apple silicon and Intel' },
  { key: 'linux', name: 'Linux desktop', note: 'Ubuntu 22.04+, Debian, Fedora (.deb, .rpm, AppImage)' },
] as const;

const base = typeof window !== 'undefined' ? window.location.origin : 'https://aistudio.livezentech.com';

export default function NodeApp() {
  const m = useQuery({
    queryKey: ['node-app-manifest'],
    queryFn: async () => {
      const r = await fetch('/node/downloads/manifest.json', { cache: 'no-store' });
      if (!r.ok) throw new Error('No downloads published yet.');
      return (await r.json()) as Manifest;
    },
    retry: false,
  });
  const mb = (n: number) => `${(n / 1048576).toFixed(1)} MB`;

  return (
    <>
      <PageHeader
        title="Node App"
        description="Turn any computer into an AI Studio node: it serves Ollama models to the Studio through a secure tunnel, with live stats and usage."
        meta={m.data ? <span className="text-sm text-slate-500 dark:text-slate-400">Version {m.data.version} · {m.data.released}</span> : undefined}
      />

      <div className="grid gap-4 lg:grid-cols-3">
        {PLATFORMS.map((p) => {
          const files = m.data?.files.filter((f) => f.platform === p.key) ?? [];
          return (
            <Card key={p.key} padded>
              <h3 className="text-base font-semibold text-slate-900 dark:text-white">{p.name}</h3>
              <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">{p.note}</p>
              <div className="mt-4 space-y-2">
                {files.length ? files.map((f) => (
                  <a key={f.file} href={`/node/downloads/${encodeURIComponent(f.file)}`} download
                    className="flex items-center justify-between gap-3 rounded-md border border-slate-200 px-3 py-2 text-sm font-medium text-brand-700 hover:bg-slate-50 dark:border-white/10 dark:text-blue-300 dark:hover:bg-white/5">
                    <span className="flex items-center gap-2"><ArrowDownTrayIcon className="size-4" aria-hidden />{f.label}</span>
                    <span className="text-xs font-normal text-slate-500 dark:text-slate-400">{mb(f.size)}</span>
                  </a>
                )) : <Badge>{m.isLoading ? 'Checking…' : 'Coming soon'}</Badge>}
              </div>
            </Card>
          );
        })}
      </div>

      <Card className="mt-6">
        <CardHeader title="Servers and machines without a desktop" description="Windows Server, Linux servers and headless machines use the command-line tool instead. It does the same enrolment and keeps the tunnel running as a service." />
        <div className="space-y-3 p-4 text-sm sm:px-6">
          <p>Create the command on <a className="font-medium text-brand-600 dark:text-blue-400" href="/servers">Servers → Connect a node</a>, then run it on the machine:</p>
          <div className="text-xs font-medium text-slate-500 dark:text-slate-400">Linux / macOS</div>
          <CodeBlock>{`curl -fsSL ${base}/node/aistudio-node.sh | bash -s -- --server ${base} --token aisn_…`}</CodeBlock>
          <div className="text-xs font-medium text-slate-500 dark:text-slate-400">Windows / Windows Server (PowerShell)</div>
          <CodeBlock>{`& ([scriptblock]::Create((irm ${base}/node/aistudio-node.ps1))) -Server ${base} -Token aisn_…`}</CodeBlock>
        </div>
      </Card>

      <Card className="mt-6">
        <CardHeader title="User guide" />
        <div className="prose-sm max-w-none space-y-5 p-4 text-sm text-slate-700 sm:px-6 dark:text-slate-300">
          <Step n={1} title="Before you start">
            Install <a className="font-medium text-brand-600 dark:text-blue-400" href="https://ollama.com/download" target="_blank" rel="noreferrer">Ollama</a> on the computer and make sure it is running.
            An NVIDIA GPU (or an Apple silicon Mac) makes models much faster but is not required. The computer only needs outbound internet access — no open ports, VPN or fixed IP.
          </Step>
          <Step n={2} title="Install the app">
            Download the installer for your system above and run it. On Windows, if SmartScreen warns about an unknown publisher, choose <em>More info → Run anyway</em>. On macOS, open it from Applications with right-click → Open the first time.
          </Step>
          <Step n={3} title="Get an enrolment token">
            In AI Studio go to <b>Servers → Connect a node</b>, optionally type a name such as “Training PC — RTX 4090”, and click <b>Create command</b>. Copy the token that starts with <code>aisn_</code>. It works once and expires after 24 hours — anyone holding it can add a node, so don't share it.
          </Step>
          <Step n={4} title="Connect">
            Open AI Studio Node, check the AI Studio address, paste the token and click <b>Connect</b>. The app creates its own key, registers the computer and opens the tunnel. Within about five minutes the node shows as online on the Servers page.
          </Step>
          <Step n={5} title="Use the app">
            <ul className="ml-5 list-disc space-y-1">
              <li><b>Overview</b> — tunnel status, requests served, CPU, memory, disk and GPU in real time. <em>Pause serving</em> takes the node out of rotation without disconnecting it.</li>
              <li><b>Analytics</b> — CPU, GPU, VRAM, requests and tokens over the last hour, day or week, plus usage per model and the latest requests from AI Studio.</li>
              <li><b>Models</b> — models installed in Ollama and which ones are loaded. Install models from AI Studio's Models page.</li>
              <li><b>Settings</b> — start at sign-in, start minimized, keep running in the tray, Ollama address, and the activity log.</li>
            </ul>
          </Step>
          <Step n={6} title="Keep it running">
            Closing the window keeps the node running in the system tray. Turn on <em>Start when I sign in</em> so it reconnects after a restart. If the connection drops it reconnects by itself every 15 seconds.
          </Step>
          <Step n={7} title="Disconnect or move a computer">
            In the app choose <b>Settings → Disconnect this computer</b>, then remove the node on the Servers page — that revokes its key immediately. To use the same computer again, create a new token and connect again.
          </Step>
          <Alert tone="info" title="Already used the command-line tool on this computer?">
            The app takes over that connection automatically when it starts — it stops the script's scheduled task or service, so there is only one tunnel.
          </Alert>
          <Step n={8} title="Troubleshooting">
            <ul className="ml-5 list-disc space-y-1">
              <li><b>“Ollama not running”</b> — start Ollama, or set its address in Settings if it runs on another port.</li>
              <li><b>“The server refused this computer's key”</b> — the node was removed in AI Studio. Disconnect in the app and connect again with a new token.</li>
              <li><b>“Port is still held”</b> — another copy of the app or the command-line tool is running for this node. Close it; the app retries by itself.</li>
              <li><b>Shows as degraded</b> — the node answers slowly (usually a distant or busy computer). It still serves requests.</li>
              <li>The app's files and log are in <code>~/.aistudio-node</code> (Windows: <code>%USERPROFILE%\.aistudio-node</code>).</li>
            </ul>
          </Step>
          <p className="flex items-center gap-2 text-xs text-slate-500 dark:text-slate-400"><CommandLineIcon className="size-4" aria-hidden />Security: nodes connect as a tunnel-only account that can open nothing but their own port on this server; AI Studio never gets a login on your computer.</p>
        </div>
      </Card>
    </>
  );
}

function Step({ n, title, children }: { n: number; title: string; children: React.ReactNode }) {
  return (
    <div className="flex gap-3">
      <span className="flex size-6 shrink-0 items-center justify-center rounded-full bg-brand-50 text-xs font-semibold text-brand-700 dark:bg-blue-500/10 dark:text-blue-300">{n}</span>
      <div><h4 className="font-semibold text-slate-900 dark:text-white">{title}</h4><div className="mt-1">{children}</div></div>
    </div>
  );
}
