import { memo, useState, type ReactNode } from 'react';
import { CheckIcon, ClipboardDocumentIcon } from '@heroicons/react/20/solid';

/**
 * A small, safe Markdown renderer for model replies. Everything becomes React text nodes,
 * so model output is never injected as HTML. Covers fenced code, headings, lists,
 * block quotes, rules, paragraphs and inline code / bold / italic / links.
 */

function CodeFence({ lang, code }: { lang: string; code: string }) {
  const [copied, setCopied] = useState(false);
  const copy = () => {
    void navigator.clipboard?.writeText(code).then(() => {
      setCopied(true);
      setTimeout(() => setCopied(false), 1500);
    });
  };
  return (
    <div className="my-3 overflow-hidden rounded-lg bg-slate-900 ring-1 ring-slate-900/10 dark:bg-black/40 dark:ring-white/10">
      <div className="flex items-center justify-between border-b border-white/10 px-3 py-1.5 text-xs text-slate-400">
        <span className="font-mono">{lang || 'code'}</span>
        <button type="button" onClick={copy} className="inline-flex items-center gap-1 rounded px-1.5 py-0.5 hover:bg-white/10 hover:text-white">
          {copied ? <CheckIcon aria-hidden className="size-3.5 text-emerald-400" /> : <ClipboardDocumentIcon aria-hidden className="size-3.5" />}
          {copied ? 'Copied' : 'Copy'}
        </button>
      </div>
      <pre className="overflow-x-auto p-3 font-mono text-xs/5 text-slate-100"><code>{code}</code></pre>
    </div>
  );
}

const safeHref = (h: string) => (/^(https?:|mailto:)/i.test(h) ? h : undefined);

/** Inline: `code`, **bold**, *italic* / _italic_, [text](url). */
export function inline(text: string, keyBase = 'i'): ReactNode[] {
  const out: ReactNode[] = [];
  const re = /(`[^`\n]+`)|(\*\*[^*\n]+\*\*|__[^_\n]+__)|(\*[^*\s][^*\n]*\*|_[^_\s][^_\n]*_)|(\[[^\]\n]+\]\([^)\s]+\))/g;
  let last = 0;
  let m: RegExpExecArray | null;
  let k = 0;
  while ((m = re.exec(text))) {
    if (m.index > last) out.push(text.slice(last, m.index));
    const t = m[0];
    const key = `${keyBase}-${k++}`;
    if (m[1]) {
      out.push(<code key={key} className="rounded bg-slate-100 px-1 py-0.5 font-mono text-[0.85em] text-pink-600 dark:bg-white/10 dark:text-pink-300">{t.slice(1, -1)}</code>);
    } else if (m[2]) {
      out.push(<strong key={key} className="font-semibold">{inline(t.slice(2, -2), key)}</strong>);
    } else if (m[3]) {
      out.push(<em key={key}>{inline(t.slice(1, -1), key)}</em>);
    } else {
      const lm = /^\[([^\]]+)\]\(([^)]+)\)$/.exec(t)!;
      const href = safeHref(lm[2]);
      out.push(href
        ? <a key={key} href={href} target="_blank" rel="noreferrer noopener" className="text-brand-600 underline dark:text-blue-400">{lm[1]}</a>
        : lm[1]);
    }
    last = m.index + t.length;
  }
  if (last < text.length) out.push(text.slice(last));
  return out;
}

type Block =
  | { t: 'code'; lang: string; code: string }
  | { t: 'h'; level: number; text: string }
  | { t: 'ul' | 'ol'; items: string[]; start: number }
  | { t: 'quote'; text: string }
  | { t: 'hr' }
  | { t: 'p'; text: string };

function parse(src: string): Block[] {
  const lines = src.replace(/\r\n?/g, '\n').split('\n');
  const blocks: Block[] = [];
  let i = 0;
  while (i < lines.length) {
    const line = lines[i];
    const fence = /^\s*(```|~~~)\s*([\w+#.-]*)/.exec(line);
    if (fence) {
      const body: string[] = [];
      i++;
      while (i < lines.length && !lines[i].trim().startsWith(fence[1])) body.push(lines[i++]);
      i++; // closing fence (or end of a still-streaming reply)
      blocks.push({ t: 'code', lang: fence[2], code: body.join('\n') });
      continue;
    }
    if (!line.trim()) { i++; continue; }
    const h = /^(#{1,6})\s+(.*)$/.exec(line);
    if (h) { blocks.push({ t: 'h', level: h[1].length, text: h[2] }); i++; continue; }
    if (/^\s*([-*_])(\s*\1){2,}\s*$/.test(line)) { blocks.push({ t: 'hr' }); i++; continue; }
    if (/^\s*[-*+]\s+/.test(line) || /^\s*\d+[.)]\s+/.test(line)) {
      const ordered = /^\s*\d+[.)]\s+/.test(line);
      const start = ordered ? parseInt(line, 10) || 1 : 1;
      const items: string[] = [];
      const itemRe = ordered ? /^\s*\d+[.)]\s+(.*)$/ : /^\s*[-*+]\s+(.*)$/;
      while (i < lines.length) {
        const m = itemRe.exec(lines[i]);
        if (m) { items.push(m[1]); i++; continue; }
        if (lines[i].trim() && /^\s{2,}/.test(lines[i]) && items.length) { items[items.length - 1] += ' ' + lines[i].trim(); i++; continue; }
        break;
      }
      blocks.push({ t: ordered ? 'ol' : 'ul', items, start });
      continue;
    }
    if (/^\s*>/.test(line)) {
      const q: string[] = [];
      while (i < lines.length && /^\s*>/.test(lines[i])) q.push(lines[i++].replace(/^\s*>\s?/, ''));
      blocks.push({ t: 'quote', text: q.join('\n') });
      continue;
    }
    const p: string[] = [];
    while (i < lines.length && lines[i].trim() && !/^\s*(```|~~~|#{1,6}\s|>|[-*+]\s|\d+[.)]\s)/.test(lines[i])) p.push(lines[i++]);
    if (!p.length) p.push(lines[i++]);
    blocks.push({ t: 'p', text: p.join('\n') });
  }
  return blocks;
}

const withBreaks = (text: string, key: string) =>
  text.split('\n').flatMap((ln, j) => (j ? [<br key={`${key}-br${j}`} />, ...inline(ln, `${key}-${j}`)] : inline(ln, `${key}-${j}`)));

const hClass = ['text-lg font-semibold', 'text-base font-semibold', 'text-sm font-semibold', 'text-sm font-semibold', 'text-sm font-medium', 'text-sm font-medium'];

export const Markdown = memo(function Markdown({ text }: { text: string }) {
  return (
    <div className="space-y-2 text-sm/6 break-words text-slate-800 dark:text-slate-200">
      {parse(text).map((b, n) => {
        const key = `b${n}`;
        switch (b.t) {
          case 'code': return <CodeFence key={key} lang={b.lang} code={b.code} />;
          case 'h': return <p key={key} className={`${hClass[b.level - 1]} mt-3 text-slate-900 first:mt-0 dark:text-white`}>{inline(b.text, key)}</p>;
          case 'hr': return <hr key={key} className="my-3 border-slate-200 dark:border-white/10" />;
          case 'quote': return <blockquote key={key} className="border-l-2 border-slate-300 pl-3 text-slate-600 dark:border-white/20 dark:text-slate-400">{withBreaks(b.text, key)}</blockquote>;
          case 'ul': return <ul key={key} className="ml-5 list-disc space-y-1">{b.items.map((it, j) => <li key={j}>{inline(it, `${key}-${j}`)}</li>)}</ul>;
          case 'ol': return <ol key={key} start={b.start} className="ml-5 list-decimal space-y-1">{b.items.map((it, j) => <li key={j}>{inline(it, `${key}-${j}`)}</li>)}</ol>;
          default: return <p key={key}>{withBreaks(b.text, key)}</p>;
        }
      })}
    </div>
  );
});
