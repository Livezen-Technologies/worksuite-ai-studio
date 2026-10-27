import { ChevronLeftIcon, ChevronRightIcon } from '@heroicons/react/16/solid';
import { useMemo, useState, type ReactNode } from 'react';
import { useParams } from 'react-router-dom';
import { Footer, Navbar, SitePage } from '../chrome';
import { findPost, formatDate, posts, type Post } from '../posts';
import { Button, Container, GradientBackground, Heading, Lead, Link, Subheading } from '../ui';

/** **bold**, `code` and [links](url) inside a line of release notes. */
function inline(text: string): ReactNode[] {
  const parts: ReactNode[] = [];
  const re = /(\*\*[^*]+\*\*|`[^`]+`|\[[^\]]+\]\([^)]+\))/g;
  let last = 0; let m: RegExpExecArray | null; let k = 0;
  while ((m = re.exec(text))) {
    if (m.index > last) parts.push(text.slice(last, m.index));
    const t = m[0];
    if (t.startsWith('**')) parts.push(<strong key={k++} className="font-semibold text-gray-950">{t.slice(2, -2)}</strong>);
    else if (t.startsWith('`')) parts.push(<code key={k++} className="rounded bg-gray-100 px-1 py-0.5 font-mono text-[0.85em] text-gray-800">{t.slice(1, -1)}</code>);
    else { const [, label, href] = t.match(/\[([^\]]+)\]\(([^)]+)\)/)!; parts.push(<a key={k++} href={href} className="font-medium text-gray-950 underline decoration-gray-400 underline-offset-4">{label}</a>); }
    last = m.index + t.length;
  }
  if (last < text.length) parts.push(text.slice(last));
  return parts;
}

/** The release notes' markdown: bullet lists (with indented sub-bullets) and paragraphs. */
function Body({ md }: { md: string }) {
  const blocks: ReactNode[] = [];
  let list: { text: string; sub: string[] }[] = [];
  const flush = () => {
    if (!list.length) return;
    blocks.push(
      <ul key={blocks.length} className="my-8 list-disc space-y-4 pl-4 text-base/8 text-gray-700 marker:text-gray-400">
        {list.map((li, i) => (
          <li key={i} className="pl-2">
            {inline(li.text)}
            {li.sub.length > 0 && <ul className="mt-2 list-[circle] space-y-2 pl-6">{li.sub.map((s, j) => <li key={j}>{inline(s)}</li>)}</ul>}
          </li>
        ))}
      </ul>,
    );
    list = [];
  };
  for (const raw of md.split('\n')) {
    const line = raw.trimEnd();
    if (/^\s{2,}- /.test(line) && list.length) list[list.length - 1].sub.push(line.replace(/^\s+- /, ''));
    else if (line.startsWith('- ')) list.push({ text: line.slice(2), sub: [] });
    else if (line.trim() === '') continue;
    else { flush(); blocks.push(<p key={blocks.length} className="my-6 text-base/8 text-gray-700">{inline(line)}</p>); }
  }
  flush();
  return <>{blocks}</>;
}

function Card({ post }: { post: Post }) {
  return (
    <div className="relative flex flex-col rounded-3xl bg-white p-2 shadow-md ring-1 shadow-black/5 ring-black/5">
      <div className="flex flex-1 flex-col p-8">
        <div className="text-sm/5 text-gray-700">{formatDate(post.date)} · {post.version}</div>
        <div className="mt-2 text-base/7 font-medium"><Link href={`/blog/${post.slug}`}><span className="absolute inset-0" />{post.title}</Link></div>
        <div className="mt-2 flex-1 text-sm/6 text-gray-500">{post.summary}</div>
        <div className="mt-6"><span className="rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-600">{post.category}</span></div>
      </div>
    </div>
  );
}

const PER_PAGE = 12;

export default function Blog() {
  const all = posts();
  const [cat, setCat] = useState('All');
  const [page, setPage] = useState(1);
  const cats = useMemo(() => ['All', ...[...new Set(all.map((p) => p.category))].sort()], [all]);
  const list = all.slice(3).filter((p) => cat === 'All' || p.category === cat);
  const pages = Math.max(1, Math.ceil(list.length / PER_PAGE));
  const shown = list.slice((page - 1) * PER_PAGE, page * PER_PAGE);
  return (
    <SitePage title="Blog — AI Studio">
      <GradientBackground />
      <Container><Navbar /></Container>
      <Container className="mt-16">
        <Heading as="h1">What's new in AI Studio.</Heading>
        <Lead className="mt-6 max-w-3xl">Every release, as we shipped it — what changed, what we measured and what we fixed.</Lead>
      </Container>
      <div className="mt-16 bg-linear-to-t from-gray-100 pb-14">
        <Container>
          <h2 className="text-2xl font-medium tracking-tight">Latest</h2>
          <div className="mt-6 grid grid-cols-1 gap-8 lg:grid-cols-3">{all.slice(0, 3).map((p) => <Card key={p.slug} post={p} />)}</div>
        </Container>
      </div>
      <Container className="mt-16 pb-24">
        <div className="flex flex-wrap gap-2">
          {cats.map((c) => (
            <button key={c} type="button" onClick={() => { setCat(c); setPage(1); }}
              className={`rounded-full px-3 py-1 text-sm font-medium ring-1 ${c === cat ? 'bg-gray-950 text-white ring-gray-950' : 'text-gray-700 ring-black/10 hover:bg-gray-50'}`}>{c}</button>
          ))}
        </div>
        <div className="mt-10">
          {shown.map((p) => (
            <div key={p.slug} className="relative grid grid-cols-1 border-b border-b-gray-100 py-10 first:border-t first:border-t-gray-200 max-sm:gap-3 sm:grid-cols-3">
              <div>
                <div className="text-sm/5 max-sm:text-gray-700 sm:font-medium">{formatDate(p.date)}</div>
                <div className="mt-2.5 text-sm/5 text-gray-500">{p.version} · {p.category}</div>
              </div>
              <div className="sm:col-span-2 sm:max-w-2xl">
                <h2 className="text-sm/5 font-medium">{p.title}</h2>
                <p className="mt-3 text-sm/6 text-gray-500">{p.summary}</p>
                <div className="mt-4">
                  <Link href={`/blog/${p.slug}`} className="flex items-center gap-1 text-sm/5 font-medium"><span className="absolute inset-0" />Read more<ChevronRightIcon className="size-4 fill-gray-400" /></Link>
                </div>
              </div>
            </div>
          ))}
          {!shown.length && <p className="py-10 text-sm text-gray-500">No releases in this category yet.</p>}
        </div>
        {pages > 1 && (
          <div className="mt-6 flex items-center justify-between gap-2">
            <Button variant="outline" disabled={page === 1} onClick={() => setPage((n) => n - 1)}><ChevronLeftIcon className="size-4" />Previous</Button>
            <span className="text-sm text-gray-500">Page {page} of {pages}</span>
            <Button variant="outline" disabled={page === pages} onClick={() => setPage((n) => n + 1)}>Next<ChevronRightIcon className="size-4" /></Button>
          </div>
        )}
      </Container>
      <Footer />
    </SitePage>
  );
}

export function BlogPost() {
  const { slug = '' } = useParams();
  const post = findPost(slug);
  const all = posts();
  const i = post ? all.indexOf(post) : -1;
  const newer = i > 0 ? all[i - 1] : undefined;
  const older = i >= 0 && i < all.length - 1 ? all[i + 1] : undefined;
  return (
    <SitePage title={post ? `${post.title} — AI Studio` : 'Not found — AI Studio'}>
      <GradientBackground />
      <Container><Navbar /></Container>
      {!post ? (
        <Container className="mt-16 pb-24">
          <Heading as="h1">That post is not here.</Heading>
          <div className="mt-8"><Button variant="outline" href="/blog"><ChevronLeftIcon className="size-4" />Back to the blog</Button></div>
        </Container>
      ) : (
        <Container className="mt-16 pb-24">
          <Subheading className="mt-16">{formatDate(post.date)}</Subheading>
          <Heading as="h1" className="mt-2">{post.title}</Heading>
          <div className="mt-16 grid grid-cols-1 gap-8 pb-24 lg:grid-cols-[15rem_1fr] xl:grid-cols-[15rem_1fr_15rem]">
            <div className="flex flex-wrap items-center gap-8 max-lg:justify-between lg:flex-col lg:items-start">
              <div className="text-sm/5 text-gray-700">Release {post.version}</div>
              <span className="rounded-full border border-dotted border-gray-300 bg-gray-50 px-2 text-sm/6 font-medium text-gray-500">{post.category}</span>
            </div>
            <div className="text-gray-700">
              <div className="max-w-2xl xl:mx-auto">
                <Body md={post.body} />
                <div className="mt-10 flex flex-wrap justify-between gap-4">
                  {older ? <Button variant="outline" href={`/blog/${older.slug}`}><ChevronLeftIcon className="size-4" />{older.version}</Button> : <span />}
                  {newer ? <Button variant="outline" href={`/blog/${newer.slug}`}>{newer.version}<ChevronRightIcon className="size-4" /></Button> : <span />}
                </div>
                <div className="mt-6"><Button variant="outline" href="/blog"><ChevronLeftIcon className="size-4" />All releases</Button></div>
              </div>
            </div>
          </div>
        </Container>
      )}
      <Footer />
    </SitePage>
  );
}

