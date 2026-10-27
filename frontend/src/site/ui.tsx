/**
 * Public site building blocks, ported from Tailwind Plus "Radiant" (licensed) to React Router: links, buttons,
 * containers, headings, the plus-grid lines, gradients, bento cards and animated numbers. Used only by the public
 * pages (src/site); the app keeps its own UI kit.
 */
import * as Headless from '@headlessui/react';
import { clsx } from 'clsx';
import { motion, useInView, useMotionValue, useSpring, useTransform } from 'framer-motion';
import { forwardRef, useEffect, useRef, type ComponentPropsWithoutRef, type ReactNode } from 'react';
import { Link as RouterLink } from 'react-router-dom';

/** Internal paths use the router; http(s) and #anchors stay plain links. */
export const Link = forwardRef(function Link(
  { href, ...props }: { href: string } & ComponentPropsWithoutRef<'a'>,
  ref: React.ForwardedRef<HTMLAnchorElement>,
) {
  const external = /^(https?:|mailto:|#)/.test(href);
  return (
    <Headless.DataInteractive>
      {external ? <a ref={ref} href={href} {...props} /> : <RouterLink ref={ref} to={href} {...props} />}
    </Headless.DataInteractive>
  );
});

const variants = {
  primary: clsx(
    'inline-flex items-center justify-center px-4 py-[calc(--spacing(2)-1px)]',
    'rounded-full border border-transparent bg-gray-950 shadow-md',
    'text-base font-medium whitespace-nowrap text-white',
    'data-disabled:bg-gray-950 data-disabled:opacity-40 data-hover:bg-gray-800',
  ),
  secondary: clsx(
    'relative inline-flex items-center justify-center px-4 py-[calc(--spacing(2)-1px)]',
    'rounded-full border border-transparent bg-white/15 shadow-md ring-1 ring-[#D15052]/15',
    'after:absolute after:inset-0 after:rounded-full after:shadow-[inset_0_0_2px_1px_#ffffff4d]',
    'text-base font-medium whitespace-nowrap text-gray-950',
    'data-disabled:bg-white/15 data-disabled:opacity-40 data-hover:bg-white/20',
  ),
  outline: clsx(
    'inline-flex items-center justify-center px-2 py-[calc(--spacing(1.5)-1px)]',
    'rounded-lg border border-transparent shadow-sm ring-1 ring-black/10',
    'text-sm font-medium whitespace-nowrap text-gray-950',
    'data-disabled:bg-transparent data-disabled:opacity-40 data-hover:bg-gray-50',
  ),
};

export function Button({ variant = 'primary', className, href, ...props }: { variant?: keyof typeof variants; href?: string } & ComponentPropsWithoutRef<'a'> & Headless.ButtonProps) {
  const cls = clsx(className, variants[variant]);
  if (href === undefined) return <Headless.Button {...(props as Headless.ButtonProps)} className={cls} />;
  return <Link href={href} {...(props as ComponentPropsWithoutRef<'a'>)} className={cls} />;
}

export function Container({ className, children }: { className?: string; children: ReactNode }) {
  return (
    <div className={clsx(className, 'px-6 lg:px-8')}>
      <div className="mx-auto max-w-2xl lg:max-w-7xl">{children}</div>
    </div>
  );
}

type HeadingProps = { as?: 'div' | 'h1' | 'h2' | 'h3' | 'h4'; dark?: boolean } & ComponentPropsWithoutRef<'h2'>;

export function Heading({ className, as: El = 'h2', dark = false, ...props }: HeadingProps) {
  return <El {...props} data-dark={dark ? 'true' : undefined} className={clsx(className, 'text-4xl font-medium tracking-tighter text-pretty text-gray-950 data-dark:text-white sm:text-6xl')} />;
}

export function Subheading({ className, as: El = 'h2', dark = false, ...props }: HeadingProps) {
  return <El {...props} data-dark={dark ? 'true' : undefined} className={clsx(className, 'font-mono text-xs/5 font-semibold tracking-widest text-gray-500 uppercase data-dark:text-gray-400')} />;
}

export function Lead({ className, ...props }: ComponentPropsWithoutRef<'p'>) {
  return <p className={clsx(className, 'text-2xl font-medium text-gray-500')} {...props} />;
}

const GRADIENT = 'bg-linear-115 from-[#fff1be] from-28% via-[#ee87cb] via-70% to-[#b060ff]';

export function Gradient({ className, ...props }: ComponentPropsWithoutRef<'div'>) {
  return <div {...props} className={clsx(className, GRADIENT, 'sm:bg-linear-145')} />;
}

export function GradientBackground() {
  return (
    <div className="relative mx-auto max-w-7xl">
      <div className={clsx('absolute -top-44 -right-60 h-60 w-xl transform-gpu md:right-0', GRADIENT, 'rotate-[-10deg] rounded-full blur-3xl')} />
    </div>
  );
}

export function PlusGrid({ className = '', children }: { className?: string; children: ReactNode }) {
  return <div className={className}>{children}</div>;
}

export function PlusGridRow({ className = '', children }: { className?: string; children: ReactNode }) {
  return (
    <div className={clsx(className, 'group/row relative isolate pt-[calc(--spacing(2)+1px)] last:pb-[calc(--spacing(2)+1px)]')}>
      <div aria-hidden="true" className="absolute inset-y-0 left-1/2 -z-10 w-screen -translate-x-1/2">
        <div className="absolute inset-x-0 top-0 border-t border-black/5" />
        <div className="absolute inset-x-0 top-2 border-t border-black/5" />
        <div className="absolute inset-x-0 bottom-0 hidden border-b border-black/5 group-last/row:block" />
        <div className="absolute inset-x-0 bottom-2 hidden border-b border-black/5 group-last/row:block" />
      </div>
      {children}
    </div>
  );
}

export function PlusGridItem({ className = '', children }: { className?: string; children: ReactNode }) {
  return (
    <div className={clsx(className, 'group/item relative')}>
      <PlusGridIcon placement="top left" className="hidden group-first/item:block" />
      <PlusGridIcon placement="top right" />
      <PlusGridIcon placement="bottom left" className="hidden group-first/item:group-last/row:block" />
      <PlusGridIcon placement="bottom right" className="hidden group-last/row:block" />
      {children}
    </div>
  );
}

function PlusGridIcon({ className = '', placement }: { className?: string; placement: `${'top' | 'bottom'} ${'right' | 'left'}` }) {
  const [y, x] = placement.split(' ');
  return (
    <svg viewBox="0 0 15 15" aria-hidden="true" className={clsx(className, 'absolute size-[15px] fill-black/10', y === 'top' ? '-top-2' : '-bottom-2', x === 'left' ? '-left-2' : '-right-2')}>
      <path d="M8 0H7V7H0V8H7V15H8V8H15V7H8V0Z" />
    </svg>
  );
}

export function BentoCard({ dark = false, className = '', eyebrow, title, description, graphic, fade = [] }: {
  dark?: boolean; className?: string; eyebrow: ReactNode; title: ReactNode; description: ReactNode; graphic: ReactNode; fade?: ('top' | 'bottom')[];
}) {
  return (
    <motion.div
      initial="idle" whileHover="active" variants={{ idle: {}, active: {} }}
      data-dark={dark ? 'true' : undefined}
      className={clsx(className, 'group relative flex flex-col overflow-hidden rounded-lg bg-white shadow-xs ring-1 ring-black/5 data-dark:bg-gray-800 data-dark:ring-white/15')}
    >
      <div className="relative h-80 shrink-0">
        {graphic}
        {fade.includes('top') && <div className="absolute inset-0 bg-linear-to-b from-white to-50% group-data-dark:from-gray-800 group-data-dark:from-[-25%]" />}
        {fade.includes('bottom') && <div className="absolute inset-0 bg-linear-to-t from-white to-50% group-data-dark:from-gray-800 group-data-dark:from-[-25%]" />}
      </div>
      <div className="relative p-10">
        <Subheading as="h3" dark={dark}>{eyebrow}</Subheading>
        <p className="mt-1 text-2xl/8 font-medium tracking-tight text-gray-950 group-data-dark:text-white">{title}</p>
        <p className="mt-2 max-w-[600px] text-sm/6 text-gray-600 group-data-dark:text-gray-400">{description}</p>
      </div>
    </motion.div>
  );
}

export function AnimatedNumber({ start, end, decimals = 0 }: { start: number; end: number; decimals?: number }) {
  const ref = useRef(null);
  const inView = useInView(ref, { once: true, amount: 0.5 });
  const value = useMotionValue(start);
  const spring = useSpring(value, { damping: 30, stiffness: 100 });
  const display = useTransform(spring, (n) => n.toLocaleString(undefined, { minimumFractionDigits: decimals, maximumFractionDigits: decimals }));
  useEffect(() => { value.set(inView ? end : start); }, [start, end, inView, value]);
  return <motion.span ref={ref}>{display}</motion.span>;
}
