/**
 * Product illustrations for the public pages, drawn in code from what AI Studio actually shows (chat, live speech,
 * a training run, nodes, the API, release gates, datasets). Example content only — no customer data.
 */
import { clsx } from 'clsx';
import { motion } from 'framer-motion';

function Window({ title, className, children, dark = false }: { title: string; className?: string; children: React.ReactNode; dark?: boolean }) {
  return (
    <div className={clsx(className, 'overflow-hidden rounded-xl shadow-xl ring-1', dark ? 'bg-gray-900 ring-white/10' : 'bg-white ring-black/10')}>
      <div className={clsx('flex items-center gap-2 border-b px-4 py-2.5', dark ? 'border-white/10' : 'border-black/5')}>
        <span className="size-2.5 rounded-full bg-[#ff5f57]" /><span className="size-2.5 rounded-full bg-[#febc2e]" /><span className="size-2.5 rounded-full bg-[#28c840]" />
        <span className={clsx('ml-2 text-xs font-medium', dark ? 'text-gray-400' : 'text-gray-500')}>{title}</span>
      </div>
      {children}
    </div>
  );
}

/** The hero preview: chat on the left, a training run and nodes on the right. */
export function AppPreview() {
  return (
    <div className="grid gap-4 lg:grid-cols-5">
      <Window title="Chat · helalm:4b" className="lg:col-span-3">
        <div className="space-y-4 p-6">
          <div className="ml-auto max-w-[80%] rounded-2xl rounded-br-sm bg-gray-950 px-4 py-2.5 text-sm text-white">ඉන්වොයිස් 1042 ගෙවා ඇති බව පාරිභෝගිකයාට කෙටි පණිවිඩයක් ලියන්න.</div>
          <div className="max-w-[85%] rounded-2xl rounded-bl-sm bg-gray-100 px-4 py-2.5 text-sm text-gray-800">
            ආයුබෝවන්! ඔබගේ ඉන්වොයිස් අංක 1042 සඳහා ගෙවීම අපට ලැබී ඇත. ඔබගේ සහයෝගයට ස්තූතියි.
          </div>
          <div className="ml-auto max-w-[80%] rounded-2xl rounded-br-sm bg-gray-950 px-4 py-2.5 text-sm text-white">mata heta office enna baha, boss ta message ekak</div>
          <div className="max-w-[85%] rounded-2xl rounded-bl-sm bg-gray-100 px-4 py-2.5 text-sm text-gray-800">සර්, මට හෙට කාර්යාලයට පැමිණීමට නොහැකි බව කරුණාවෙන් දන්වමි…</div>
          <div className="flex items-center gap-2 rounded-xl px-3 py-2 ring-1 ring-black/10">
            <span className="flex-1 text-sm text-gray-400">Type in Sinhala, Singlish or English — or speak</span>
            <span className="flex size-8 items-center justify-center rounded-full bg-gray-950 text-white">
              <svg viewBox="0 0 20 20" className="size-4" fill="currentColor" aria-hidden><path d="M10 2a3 3 0 0 0-3 3v5a3 3 0 1 0 6 0V5a3 3 0 0 0-3-3Zm-5 8a1 1 0 1 0-2 0 7 7 0 0 0 6 6.93V19a1 1 0 1 0 2 0v-2.07A7 7 0 0 0 17 10a1 1 0 1 0-2 0 5 5 0 0 1-10 0Z" /></svg>
            </span>
          </div>
        </div>
      </Window>
      <div className="grid gap-4 lg:col-span-2">
        <TrainingCard />
        <NodesCard />
      </div>
    </div>
  );
}

export function TrainingCard({ dark = false }: { dark?: boolean }) {
  const loss = [0.18, 0.07, 0.041, 0.039, 0.055, 0.045, 0.043, 0.016, 0.041, 0.04, 0.026, 0.028, 0.019, 0.021, 0.021, 0.026, 0.019, 0.022];
  const pts = loss.map((v, i) => `${(i / (loss.length - 1)) * 100},${40 - (v / 0.18) * 36}`).join(' ');
  return (
    <Window title="Training · job #5" dark={dark}>
      <div className="p-5">
        <div className="flex items-baseline justify-between">
          <span className={clsx('text-3xl font-medium tabular-nums', dark ? 'text-white' : 'text-gray-950')}>64%</span>
          <span className={clsx('text-xs', dark ? 'text-gray-400' : 'text-gray-500')}>step 200 of 312 · 6 min left</span>
        </div>
        <div className={clsx('mt-3 h-1.5 overflow-hidden rounded-full', dark ? 'bg-white/10' : 'bg-gray-100')}>
          <motion.div className="h-full rounded-full bg-linear-to-r from-[#ee87cb] to-[#b060ff]" initial={{ width: '20%' }} whileInView={{ width: '64%' }} transition={{ duration: 1.4, ease: 'easeOut' }} />
        </div>
        <svg viewBox="0 0 100 42" className="mt-4 h-16 w-full" preserveAspectRatio="none" aria-hidden>
          <polyline points={pts} fill="none" stroke="#b060ff" strokeWidth="1.2" vectorEffect="non-scaling-stroke" />
        </svg>
        <div className={clsx('mt-3 grid grid-cols-3 gap-2 text-xs', dark ? 'text-gray-400' : 'text-gray-500')}>
          <span>GPU <b className={dark ? 'text-white' : 'text-gray-950'}>96%</b></span>
          <span>VRAM <b className={dark ? 'text-white' : 'text-gray-950'}>27.7 GB</b></span>
          <span>2 × Tesla T4</span>
        </div>
      </div>
    </Window>
  );
}

export function NodesCard() {
  const nodes = [['GPU node · 2 × Tesla T4', 'training', 'bg-violet-500'], ['CPU node · 64 cores', 'speech', 'bg-emerald-500'], ['Workstation · Quadro T1000', 'ready', 'bg-emerald-500'], ['This server · 8 cores', 'chat', 'bg-emerald-500']];
  return (
    <Window title="Nodes">
      <ul className="divide-y divide-black/5">
        {nodes.map(([n, s, c]) => (
          <li key={n} className="flex items-center justify-between px-5 py-3 text-sm">
            <span className="flex items-center gap-2.5 text-gray-800"><span className={clsx('size-2 rounded-full', c)} />{n}</span>
            <span className="text-xs text-gray-500">{s}</span>
          </li>
        ))}
      </ul>
    </Window>
  );
}

/** Live speech: an animated waveform and the transcript it produces. */
export function SpeechGraphic() {
  const bars = Array.from({ length: 48 }, (_, i) => 0.25 + Math.abs(Math.sin(i * 0.55) * Math.cos(i * 0.21)) * 0.75);
  return (
    <div className="absolute inset-0 flex flex-col justify-center gap-6 px-10">
      <div className="flex h-20 items-center gap-[3px]">
        {bars.map((h, i) => (
          <motion.span key={i} className="w-1.5 rounded-full bg-linear-to-t from-[#ee87cb] to-[#b060ff]"
            variants={{ idle: { height: `${h * 60}%` }, active: { height: [`${h * 60}%`, `${(1 - h * 0.6) * 90}%`, `${h * 60}%`] } }}
            transition={{ duration: 0.9, repeat: Infinity, delay: i * 0.02 }} />
        ))}
      </div>
      <div className="space-y-2 text-sm">
        <p><span className="mr-2 font-mono text-xs text-gray-400">0:02</span><span className="text-gray-800">වෑංජන 10ක් ගෙනල්ලා</span></p>
        <p><span className="mr-2 font-mono text-xs text-gray-400">0:05</span><span className="text-gray-800">රුපියල් 2500ක් ගෙවන්න</span></p>
      </div>
    </div>
  );
}

/** Datasets by category, as bars. */
export function DatasetsGraphic() {
  const rows: [string, number][] = [['Instructions', 40], ['Q&A', 20], ['Singlish', 15], ['Corpus', 15], ['Translation', 10]];
  return (
    <div className="absolute inset-0 flex flex-col justify-center gap-3 px-10">
      {rows.map(([k, v]) => (
        <div key={k} className="flex items-center gap-3 text-sm">
          <span className="w-24 text-gray-500">{k}</span>
          <div className="h-3 flex-1 rounded-full bg-gray-100">
            <motion.div className="h-full rounded-full bg-gray-950" variants={{ idle: { width: `${v * 2}%` }, active: { width: `${v * 2.3}%` } }} />
          </div>
          <span className="w-10 text-right font-mono text-xs text-gray-500">{v}%</span>
        </div>
      ))}
      <p className="mt-2 text-xs text-gray-400">A balanced training mix — one huge source can't drown the rest.</p>
    </div>
  );
}

/** The gateway: one request. */
export function ApiGraphic() {
  return (
    <div className="absolute inset-0 p-8">
      <pre className="h-full overflow-hidden rounded-xl bg-gray-950 p-5 font-mono text-[11px]/5 text-gray-300">
        <span className="text-gray-500"># chat through your own gateway{'\n'}</span>
        <span className="text-[#ee87cb]">curl</span> https://your-studio/api/v1/chat \{'\n'}
        {'  '}-H <span className="text-emerald-300">"X-Api-Key: ohub_…"</span> \{'\n'}
        {'  '}-d <span className="text-emerald-300">{`'{"model":"helalm:4b",`}</span>{'\n'}
        {'       '}<span className="text-emerald-300">{`"messages":[{"role":"user",`}</span>{'\n'}
        {'       '}<span className="text-emerald-300">{`"content":"ආයුබෝවන්"}]}'`}</span>
      </pre>
    </div>
  );
}

/** Release gates: a checklist with one failing. */
export function GatesGraphic() {
  const gates: [string, string, boolean][] = [['Clean word error ≤ 20%', '17.9%', true], ['Character error ≤ 10%', '5.4%', true], ['Noisy word error ≤ 35%', '24.4%', true], ['Silence → no text ≥ 99%', '100%', true], ['Numbers right ≥ 90%', '71.3%', false]];
  return (
    <div className="absolute inset-0 flex flex-col justify-center gap-2.5 px-10">
      {gates.map(([g, v, ok]) => (
        <div key={g} className="flex items-center justify-between rounded-lg bg-white/5 px-4 py-2.5 text-sm ring-1 ring-white/10">
          <span className="flex items-center gap-2.5 text-gray-300">
            <span className={clsx('flex size-5 items-center justify-center rounded-full text-[11px] font-bold', ok ? 'bg-emerald-500/20 text-emerald-300' : 'bg-rose-500/20 text-rose-300')}>{ok ? '✓' : '!'}</span>{g}
          </span>
          <span className="font-mono text-xs text-gray-400">{v}</span>
        </div>
      ))}
    </div>
  );
}

/** Nodes joining over a tunnel, drawn as connected dots. */
export function NetworkGraphic() {
  const nodes = [[20, 30], [80, 22], [50, 55], [18, 78], [82, 76]];
  return (
    <svg viewBox="0 0 100 100" className="absolute inset-0 size-full p-8" aria-hidden>
      {nodes.slice(1).map(([x, y], i) => (
        <motion.line key={i} x1={50} y1={55} x2={x} y2={y} stroke="#b060ff" strokeOpacity={0.5} strokeWidth={0.6} strokeDasharray="2 2"
          variants={{ idle: { strokeDashoffset: 0 }, active: { strokeDashoffset: [0, -8] } }} transition={{ duration: 1, repeat: Infinity, ease: 'linear' }} />
      ))}
      {nodes.map(([x, y], i) => (
        <g key={i}>
          <circle cx={x} cy={y} r={i === 2 ? 7 : 5} fill={i === 2 ? '#ffffff' : '#1f2937'} stroke={i === 2 ? '#b060ff' : '#4b5563'} strokeWidth={0.8} />
          {i === 2 && <text x={50} y={57} textAnchor="middle" fontSize="4" fill="#111827" fontWeight={600}>Studio</text>}
        </g>
      ))}
    </svg>
  );
}
