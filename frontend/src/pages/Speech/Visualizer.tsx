import { useEffect, useRef } from 'react';

/** Live audio bars drawn from an AnalyserNode; mirrored around the centre line, coloured from the theme. */
export function Visualizer({ analyser, active, className }: { analyser: AnalyserNode | null; active: boolean; className?: string }) {
  const canvas = useRef<HTMLCanvasElement>(null);
  useEffect(() => {
    const c = canvas.current;
    if (!c) return;
    const ctx = c.getContext('2d')!;
    let raf = 0;
    const bars = 48;
    const data = new Uint8Array(analyser?.frequencyBinCount ?? 256);
    const smooth = new Float32Array(bars);
    const draw = (t: number) => {
      const dpr = window.devicePixelRatio || 1;
      const w = c.clientWidth, h = c.clientHeight;
      if (c.width !== w * dpr) { c.width = w * dpr; c.height = h * dpr; }
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
      ctx.clearRect(0, 0, w, h);
      if (analyser && active) analyser.getByteFrequencyData(data);
      const color = getComputedStyle(c).color;
      const gap = 3, bw = (w - gap * (bars - 1)) / bars;
      for (let i = 0; i < bars; i++) {
        // voice sits in the lower bins; spread them across the bars
        const bin = Math.floor(Math.pow(i / bars, 1.6) * (data.length * 0.6));
        const target = active ? data[bin] / 255 : 0.04 + 0.03 * Math.sin(t / 600 + i / 3);
        smooth[i] += (target - smooth[i]) * 0.35;
        const bh = Math.max(3, smooth[i] * h * 0.95);
        ctx.fillStyle = color;
        ctx.globalAlpha = active ? 0.55 + smooth[i] * 0.45 : 0.35;
        const x = i * (bw + gap), y = (h - bh) / 2;
        ctx.beginPath();
        ctx.roundRect(x, y, bw, bh, Math.min(bw / 2, 3));
        ctx.fill();
      }
      raf = requestAnimationFrame(draw);
    };
    raf = requestAnimationFrame(draw);
    return () => cancelAnimationFrame(raf);
  }, [analyser, active]);
  return <canvas ref={canvas} aria-hidden className={className} />;
}
