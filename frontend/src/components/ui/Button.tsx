import { forwardRef, type ButtonHTMLAttributes, type ComponentType, type SVGProps } from 'react';
import { cx } from './cx';
import { Spinner } from './Feedback';

type Variant = 'primary' | 'secondary' | 'danger' | 'ghost' | 'soft';
type Size = 'sm' | 'md' | 'lg';

const variants: Record<Variant, string> = {
  primary: 'bg-brand-600 text-white shadow-xs hover:bg-brand-500 focus-visible:outline-brand-600',
  secondary:
    'bg-white text-slate-900 shadow-xs ring-1 ring-inset ring-slate-300 hover:bg-slate-50 dark:bg-white/10 dark:text-white dark:ring-white/10 dark:hover:bg-white/20',
  danger: 'bg-red-600 text-white shadow-xs hover:bg-red-500 focus-visible:outline-red-600',
  ghost: 'text-slate-700 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-white/10',
  soft: 'bg-brand-50 text-brand-700 hover:bg-brand-100 dark:bg-brand-500/15 dark:text-blue-300 dark:hover:bg-brand-500/25',
};
const sizes: Record<Size, string> = {
  sm: 'gap-1 px-2.5 py-1.5 text-xs',
  md: 'gap-1.5 px-3 py-2 text-sm',
  lg: 'gap-2 px-3.5 py-2.5 text-sm',
};

export interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: Variant;
  size?: Size;
  loading?: boolean;
  icon?: ComponentType<SVGProps<SVGSVGElement>>;
}

export const Button = forwardRef<HTMLButtonElement, ButtonProps>(function Button(
  { variant = 'secondary', size = 'md', loading, icon: Icon, className, children, disabled, type = 'button', ...rest },
  ref,
) {
  return (
    <button
      ref={ref}
      type={type}
      disabled={disabled || loading}
      className={cx(
        'inline-flex shrink-0 items-center justify-center rounded-md font-semibold whitespace-nowrap transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 disabled:cursor-not-allowed disabled:opacity-50',
        variants[variant],
        sizes[size],
        className,
      )}
      {...rest}
    >
      {loading ? <Spinner className="size-4" /> : Icon ? <Icon aria-hidden className="-ml-0.5 size-4" /> : null}
      {children}
    </button>
  );
});

export function IconButton({ label, icon: Icon, className, ...rest }: Omit<ButtonProps, 'children'> & { label: string; icon: ComponentType<SVGProps<SVGSVGElement>> }) {
  return (
    <button
      type="button"
      aria-label={label}
      title={label}
      className={cx(
        'inline-flex items-center justify-center rounded-md p-1.5 text-slate-500 transition-colors hover:bg-slate-100 hover:text-slate-700 focus-visible:outline-2 focus-visible:outline-brand-600 disabled:opacity-40 dark:text-slate-400 dark:hover:bg-white/10 dark:hover:text-white',
        className,
      )}
      {...rest}
    >
      <Icon aria-hidden className="size-5" />
    </button>
  );
}
