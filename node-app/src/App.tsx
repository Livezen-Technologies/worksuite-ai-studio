import { useCallback, useEffect, useState, type ReactNode } from 'react';
import { disable as autostartOff, enable as autostartOn, isEnabled as autostartIsOn } from '@tauri-apps/plugin-autostart';
import { openUrl } from '@tauri-apps/plugin-opener';
import {
  api, bytes, compact, duration, errorText, gb, pct,
  type Analytics as AnalyticsData, type Overview as OverviewData, type Settings as SettingsData,
} from './api';
import { Chart } from './Chart';

type Page = 'overview' | 'analytics' | 'models' | 'settings';

export default function App() {
  const [data, setData] = useState<OverviewData | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [page, setPage] = useState<Page>('overview');

  const refresh = useCallback(() => {
    api.overview().then((d) => { setData(d); setError(null); }).catch((e) => setError(errorText(e)));
  }, []);
  useEffect(() => {
    refresh();
    const t = setInterval(refresh, 3000);
    return () => clearInterval(t);
  }, [refresh]);

  if (!data) return <div className="center">{error ?? 'Loading…'}</div>;
  if (!data.enrolment) return <Connect data={data} onDone={refresh} />;

  const nav: [Page, string][] = [['overview', 'Overview'], ['analytics', 'Analytics'], ['models', 'Models'], ['settings', 'Settings']];
  return (
    <div className="shell">
      <aside>
        <div className="brand">AI Studio <b>Node</b></div>
        <TunnelPill state={data.tunnel.state} />
        <nav>
          {nav.map(([k, label]) => (
            <button key={k} className={page === k ? 'active' : ''} onClick={() => setPage(k)}>{label}</button>
          ))}
        </nav>
        <div className="aside-foot">
          <div className="muted small">{data.enrolment.name}</div>
          <button className="link small" onClick={() => openUrl(`${data.enrolment!.server}/servers?server=${data.enrolment!.server_id}`)}>Open in AI Studio ↗</button>
        </div>
      </aside>
      <main>
        {error && <div className="alert">{error}</div>}
        {page === 'overview' && <Overview data={data} onChange={refresh} />}
        {page === 'analytics' && <Analytics />}
        {page === 'models' && <Models data={data} />}
        {page === 'settings' && <Settings data={data} onChange={refresh} />}
      </main>
    </div>
  );
}

function TunnelPill({ state }: { state: OverviewData['tunnel']['state'] }) {
  const label = { connected: 'Connected', connecting: 'Connecting…', retrying: 'Reconnecting…', stopped: 'Paused' }[state];
  return <div className={`pill ${state}`}><span className="dot" />{label}</div>;
}

/* ---------- Connect ---------- */

function Connect({ data, onDone }: { data: OverviewData; onDone: () => void }) {
  const [server, setServer] = useState(data.settings.server);
  const [token, setToken] = useState('');
  const [name, setName] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const submit = async () => {
    setBusy(true); setError(null);
    try { await api.enrol(server, token, name); onDone(); } catch (e) { setError(errorText(e)); } finally { setBusy(false); }
  };
  const gpu = data.machine.gpus[0];

  return (
    <div className="connect">
      <div className="card connect-card">
        <h1>Connect this computer to AI Studio</h1>
        <p className="muted">
          In AI Studio, open <b>Servers → Connect a node</b>, create a command and paste its token here. This computer then
          serves Ollama models to AI Studio through a secure tunnel — no open ports needed.
        </p>
        <label>AI Studio address<input value={server} onChange={(e) => setServer(e.target.value)} /></label>
        <label>Enrolment token<input value={token} onChange={(e) => setToken(e.target.value.trim())} placeholder="aisn_…" spellCheck={false} /></label>
        <label>Node name <span className="muted">(optional)</span><input value={name} onChange={(e) => setName(e.target.value)} placeholder={data.machine.hostname} /></label>
        {error && <div className="alert">{error}</div>}
        <button className="primary" disabled={busy || !token.startsWith('aisn_')} onClick={submit}>{busy ? 'Connecting…' : 'Connect'}</button>
        <div className="facts">
          <span>{data.machine.cpu_cores} CPU threads</span>
          <span>{gb(data.machine.ram_total_gb)} RAM</span>
          <span>{gpu ? `${gpu.name} · ${gb(gpu.mem_total_mb / 1024)}` : 'No NVIDIA GPU found'}</span>
          <span className={data.ollama.up ? 'ok' : 'bad'}>{data.ollama.up ? `Ollama ${data.ollama.version}` : 'Ollama not running'}</span>
        </div>
        {!data.ollama.up && (
          <p className="small muted">
            Install and start Ollama first: <button className="link" onClick={() => openUrl('https://ollama.com/download')}>ollama.com/download</button>
          </p>
        )}
      </div>
    </div>
  );
}

/* ---------- Overview ---------- */

function Overview({ data, onChange }: { data: OverviewData; onChange: () => void }) {
  const { machine: m, tunnel: t, ollama: o } = data;
  const uptime = Math.max(1, Date.now() / 1000 - t.started_at);
  return (
    <>
      <header>
        <h2>Overview</h2>
        <button onClick={() => api.setTunnel(t.state === 'stopped').then(onChange)}>{t.state === 'stopped' ? 'Resume serving' : 'Pause serving'}</button>
      </header>
      {t.last_error && <div className="alert">{t.last_error}</div>}
      <div className="grid4">
        <Stat label="Tunnel" value={{ connected: 'Up', connecting: '…', retrying: 'Retrying', stopped: 'Paused' }[t.state]}
          hint={`${pct((t.connected_secs / uptime) * 100)} uptime · ${t.reconnects} reconnects`} />
        <Stat label="Requests served" value={compact(data.requests)} hint={`${data.errors} errors · since app start`} />
        <Stat label="Tokens" value={compact(data.tokens)} hint="prompt + output" />
        <Stat label="Models loaded" value={`${o.running.length} / ${o.models.length}`} hint={o.up ? `Ollama ${o.version}` : 'Ollama not running'} />
      </div>
      <div className="grid2">
        <section className="card">
          <h3>Machine</h3>
          <Meter label="CPU" value={m.cpu} detail={`${m.cpu_cores} threads · ${m.cpu_name}`} />
          <Meter label="Memory" value={(m.ram_used_gb / m.ram_total_gb) * 100} detail={`${gb(m.ram_used_gb)} of ${gb(m.ram_total_gb)}`} />
          <Meter label="Disk" value={m.disk_total_gb ? ((m.disk_total_gb - m.disk_free_gb) / m.disk_total_gb) * 100 : 0} detail={`${gb(m.disk_free_gb)} free of ${gb(m.disk_total_gb)}`} />
          <p className="small muted">{m.hostname} · {m.os}</p>
        </section>
        <section className="card">
          <h3>GPU</h3>
          {m.gpus.length ? m.gpus.map((g, i) => (
            <div key={i}>
              <Meter label={g.name} value={g.util} detail={`${Math.round(g.temp_c)} °C · ${Math.round(g.power_w)} W`} />
              <Meter label="VRAM" value={(g.mem_used_mb / g.mem_total_mb) * 100} detail={`${gb(g.mem_used_mb / 1024)} of ${gb(g.mem_total_mb / 1024)}`} />
            </div>
          )) : <p className="muted">No NVIDIA GPU detected. Ollama uses the CPU{navigator.userAgent.includes('Mac') ? ' or Apple GPU (Metal)' : ''}.</p>}
          {m.cuda && <p className="small muted">CUDA {m.cuda}</p>}
        </section>
      </div>
      <section className="card">
        <h3>Connection</h3>
        <dl>
          <dt>AI Studio</dt><dd>{data.enrolment!.server}</dd>
          <dt>Node</dt><dd>{data.enrolment!.name} (#{data.enrolment!.server_id})</dd>
          <dt>Tunnel</dt><dd>server 127.0.0.1:{data.enrolment!.tunnel_port} → this computer :{data.settings.proxy_port} → Ollama</dd>
          <dt>In this state since</dt><dd>{new Date(t.since * 1000).toLocaleString()}</dd>
        </dl>
      </section>
    </>
  );
}

function Stat({ label, value, hint }: { label: string; value: ReactNode; hint?: string }) {
  return <div className="card stat"><div className="muted small">{label}</div><div className="big">{value}</div>{hint && <div className="muted small">{hint}</div>}</div>;
}

function Meter({ label, value, detail }: { label: string; value: number; detail?: string }) {
  const v = Math.max(0, Math.min(100, value || 0));
  return (
    <div className="meter">
      <div className="meter-row"><span>{label}</span><b>{pct(v)}</b></div>
      <div className="bar"><div className={v > 90 ? 'hot' : ''} style={{ width: `${v}%` }} /></div>
      {detail && <div className="small muted">{detail}</div>}
    </div>
  );
}

/* ---------- Analytics ---------- */

const RANGES: [number, string][] = [[3600, '1 h'], [86400, '24 h'], [7 * 86400, '7 days']];

function Analytics() {
  const [range, setRange] = useState(86400);
  const [a, setA] = useState<AnalyticsData | null>(null);
  useEffect(() => {
    const load = () => api.analytics(range).then(setA).catch(() => {});
    load();
    const t = setInterval(load, 15000);
    return () => clearInterval(t);
  }, [range]);

  const s = a?.samples ?? [];
  const up = s.length ? (s.filter((x) => x.connected).length / s.length) * 100 : null;
  const models = Object.entries(a?.by_model ?? {}).sort((x, y) => y[1].requests - x[1].requests);

  return (
    <>
      <header>
        <h2>Analytics</h2>
        <div className="seg">{RANGES.map(([v, l]) => <button key={v} className={range === v ? 'active' : ''} onClick={() => setRange(v)}>{l}</button>)}</div>
      </header>
      <div className="grid4">
        <Stat label="Requests" value={compact(s.reduce((n, x) => n + x.requests, 0))} />
        <Stat label="Tokens" value={compact(s.reduce((n, x) => n + x.tokens, 0))} />
        <Stat label="Tunnel uptime" value={up == null ? '—' : pct(up)} />
        <Stat label="Avg GPU" value={s.length ? pct(s.reduce((n, x) => n + x.gpu, 0) / s.length) : '—'} />
      </div>
      {s.length < 2 ? (
        <div className="card muted">Collecting data — a point is added every minute while the app runs (Settings → Analytics interval).</div>
      ) : (
        <div className="grid2">
          <section className="card"><h3>CPU & memory</h3><Chart samples={s} series={[['cpu', 'CPU', '#4f46e5'], ['ram', 'Memory', '#0ea5e9']]} max={100} unit="%" /></section>
          <section className="card"><h3>GPU & VRAM</h3><Chart samples={s} series={[['gpu', 'GPU', '#16a34a'], ['vram', 'VRAM', '#f59e0b']]} max={100} unit="%" /></section>
          <section className="card"><h3>Requests</h3><Chart samples={s} series={[['requests', 'Requests', '#4f46e5']]} bars /></section>
          <section className="card"><h3>Tokens</h3><Chart samples={s} series={[['tokens', 'Tokens', '#db2777']]} bars /></section>
        </div>
      )}
      <section className="card">
        <h3>By model <span className="muted small">since the app started</span></h3>
        {models.length ? (
          <table>
            <thead><tr><th>Model</th><th>Requests</th><th>Prompt tokens</th><th>Output tokens</th><th>Avg time</th></tr></thead>
            <tbody>{models.map(([name, t]) => (
              <tr key={name}><td className="mono">{name}</td><td>{compact(t.requests)}</td><td>{compact(t.prompt_tokens)}</td><td>{compact(t.output_tokens)}</td><td>{(t.ms / t.requests / 1000).toFixed(1)} s</td></tr>
            ))}</tbody>
          </table>
        ) : <p className="muted">No model requests from AI Studio yet.</p>}
      </section>
      <section className="card">
        <h3>Recent requests</h3>
        {a?.recent.length ? (
          <table>
            <thead><tr><th>Time</th><th>Call</th><th>Model</th><th>Tokens</th><th>Time</th><th /></tr></thead>
            <tbody>{a.recent.map((r, i) => (
              <tr key={i}><td>{new Date(r.at * 1000).toLocaleTimeString()}</td><td className="mono">{r.method} {r.path}</td><td className="mono">{r.model}</td>
                <td>{r.prompt_tokens} → {r.output_tokens}</td><td>{(r.ms / 1000).toFixed(1)} s</td><td className={r.ok ? 'ok' : 'bad'}>{r.ok ? 'OK' : 'Error'}</td></tr>
            ))}</tbody>
          </table>
        ) : <p className="muted">Nothing yet.</p>}
      </section>
    </>
  );
}

/* ---------- Models ---------- */

function Models({ data }: { data: OverviewData }) {
  const running = new Map(data.ollama.running.map((m) => [m.name, m]));
  return (
    <>
      <header><h2>Models</h2><span className="muted small">Install models from AI Studio's Models page, or with <code>ollama pull</code>.</span></header>
      {!data.ollama.up ? <div className="alert">Ollama is not running at {data.settings.ollama_url}.</div> : (
        <section className="card">
          <table>
            <thead><tr><th>Model</th><th>Size</th><th>Parameters</th><th>Quantization</th><th>State</th></tr></thead>
            <tbody>{data.ollama.models.map((m) => {
              const r = running.get(m.name);
              return (
                <tr key={m.name}><td className="mono">{m.name}</td><td>{bytes(m.size)}</td><td>{m.details?.parameter_size ?? '—'}</td><td>{m.details?.quantization_level ?? '—'}</td>
                  <td>{r ? <span className="ok">Loaded{r.size_vram ? ` · ${bytes(r.size_vram)} VRAM` : ''}</span> : <span className="muted">Idle</span>}</td></tr>
              );
            })}</tbody>
          </table>
          {!data.ollama.models.length && <p className="muted">No models installed yet.</p>}
        </section>
      )}
    </>
  );
}

/* ---------- Settings ---------- */

function Settings({ data, onChange }: { data: OverviewData; onChange: () => void }) {
  const [s, setS] = useState<SettingsData>(data.settings);
  const [autostart, setAutostart] = useState<boolean | null>(null);
  const [log, setLog] = useState('');
  const [saved, setSaved] = useState(false);
  const [confirm, setConfirm] = useState(false);
  useEffect(() => { autostartIsOn().then(setAutostart).catch(() => setAutostart(null)); api.readLog().then(setLog); }, []);

  const save = async () => { await api.saveSettings(s); setSaved(true); setTimeout(() => setSaved(false), 1500); onChange(); };
  const toggleAutostart = async (on: boolean) => { await (on ? autostartOn() : autostartOff()); setAutostart(await autostartIsOn()); };
  const set = <K extends keyof SettingsData>(k: K, v: SettingsData[K]) => setS((p) => ({ ...p, [k]: v }));

  return (
    <>
      <header><h2>Settings</h2></header>
      <section className="card form">
        <h3>Startup</h3>
        <Toggle label="Start when I sign in to this computer" checked={!!autostart} onChange={toggleAutostart} disabled={autostart === null} />
        <Toggle label="Start minimized to the tray" checked={s.start_minimized} onChange={(v) => set('start_minimized', v)} />
        <Toggle label="Keep running in the tray when the window is closed" checked={s.close_to_tray} onChange={(v) => set('close_to_tray', v)} />
      </section>
      <section className="card form">
        <h3>Ollama</h3>
        <label>Ollama address<input value={s.ollama_url} onChange={(e) => set('ollama_url', e.target.value)} /></label>
        <label>Metering port <span className="muted">(local port the tunnel uses; restart the app after changing)</span>
          <input type="number" value={s.proxy_port} onChange={(e) => set('proxy_port', Number(e.target.value))} /></label>
        <label>Analytics interval (seconds)<input type="number" min={10} value={s.sample_seconds} onChange={(e) => set('sample_seconds', Number(e.target.value))} /></label>
        <div><button className="primary" onClick={save}>{saved ? 'Saved' : 'Save'}</button></div>
      </section>
      <section className="card">
        <h3>Activity log</h3>
        <pre className="log">{log || 'Empty.'}</pre>
        <button onClick={() => api.readLog().then(setLog)}>Refresh</button>
      </section>
      <section className="card danger">
        <h3>Disconnect</h3>
        <p className="muted">Stops serving and forgets this connection on this computer. Also remove “{data.enrolment!.name}” on the AI Studio Servers page to revoke its key.</p>
        {confirm
          ? <div className="row"><button className="danger" onClick={() => api.disconnect().then(onChange)}>Yes, disconnect</button><button onClick={() => setConfirm(false)}>Cancel</button></div>
          : <button className="danger" onClick={() => setConfirm(true)}>Disconnect this computer</button>}
      </section>
    </>
  );
}

function Toggle({ label, checked, onChange, disabled }: { label: string; checked: boolean; onChange: (v: boolean) => void; disabled?: boolean }) {
  return (
    <label className="toggle">
      <input type="checkbox" checked={checked} disabled={disabled} onChange={(e) => onChange(e.target.checked)} />
      <span>{label}</span>
    </label>
  );
}
