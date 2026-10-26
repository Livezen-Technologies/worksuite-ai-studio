import type { Sample } from './api';

type Key = 'cpu' | 'ram' | 'gpu' | 'vram' | 'requests' | 'tokens';

/** Small SVG time chart: lines for percentages, bars for counts. No chart library, to keep the app small. */
export function Chart({ samples, series, max, unit = '', bars }: {
  samples: Sample[]; series: [Key, string, string][]; max?: number; unit?: string; bars?: boolean;
}) {
  const W = 480, H = 140, P = 4;
  const t0 = samples[0].t, t1 = samples[samples.length - 1].t || t0 + 1;
  const top = max ?? Math.max(1, ...samples.flatMap((s) => series.map(([k]) => s[k])));
  const x = (t: number) => P + ((t - t0) / Math.max(1, t1 - t0)) * (W - 2 * P);
  const y = (v: number) => H - P - (v / top) * (H - 2 * P);
  const fmt = (t: number) => new Date(t * 1000).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });

  return (
    <div className="chart">
      <svg viewBox={`0 0 ${W} ${H}`} preserveAspectRatio="none" role="img">
        {[0.25, 0.5, 0.75].map((f) => <line key={f} x1={0} x2={W} y1={y(top * f)} y2={y(top * f)} className="grid" />)}
        {series.map(([k, , color]) =>
          bars ? (
            samples.map((s, i) => s[k] > 0 && (
              <rect key={`${k}${i}`} x={x(s.t) - 1.5} y={y(s[k])} width={3} height={H - P - y(s[k])} fill={color} />
            ))
          ) : (
            <polyline key={k} fill="none" stroke={color} strokeWidth={1.8} vectorEffect="non-scaling-stroke"
              points={samples.map((s) => `${x(s.t).toFixed(1)},${y(s[k]).toFixed(1)}`).join(' ')} />
          ))}
      </svg>
      <div className="chart-foot">
        <span>{fmt(t0)}</span>
        <span className="legend">{series.map(([k, label, color]) => <span key={k}><i style={{ background: color }} />{label}</span>)}</span>
        <span>max {Math.round(top)}{unit} · {fmt(t1)}</span>
      </div>
    </div>
  );
}
