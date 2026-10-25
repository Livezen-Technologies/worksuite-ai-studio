import { forwardRef, useId, type InputHTMLAttributes, type ReactNode, type SelectHTMLAttributes, type TextareaHTMLAttributes } from 'react';
import { Switch as HSwitch } from '@headlessui/react';
import { ChevronDownIcon } from '@heroicons/react/16/solid';
import { cx } from './cx';

/* Tailwind Plus v4 form controls: outline-based so focus and error rings never shift layout. */
const control =
  'block w-full rounded-md bg-white px-3 py-1.5 text-base text-slate-900 outline-1 -outline-offset-1 outline-slate-300 placeholder:text-slate-400 focus:outline-2 focus:-outline-offset-2 focus:outline-brand-600 disabled:cursor-not-allowed disabled:bg-slate-50 disabled:text-slate-500 sm:text-sm/6 dark:bg-white/5 dark:text-white dark:outline-white/10 dark:placeholder:text-slate-500 dark:focus:outline-blue-500 dark:disabled:bg-white/[0.02]';
const invalid = 'outline-red-300 text-red-900 focus:outline-red-600 dark:outline-red-500/50 dark:text-red-300';

export interface FieldProps {
  label?: ReactNode;
  hint?: ReactNode;
  error?: string;
  required?: boolean;
  className?: string;
  children: (id: string, describedBy?: string) => ReactNode;
  action?: ReactNode;
}

/** Label, control, hint and error laid out once, wired with ids for screen readers. */
export function Field({ label, hint, error, required, className, children, action }: FieldProps) {
  const id = useId();
  const describedBy = error ? `${id}-error` : hint ? `${id}-hint` : undefined;
  return (
    <div className={className}>
      {(label || action) && (
        <div className="flex items-center justify-between gap-2">
          {label && (
            <label htmlFor={id} className="block text-sm/6 font-medium text-slate-900 dark:text-white">
              {label}
              {required && <span className="ml-0.5 text-red-500" aria-hidden>*</span>}
            </label>
          )}
          {action}
        </div>
      )}
      <div className={cx(label && 'mt-1.5')}>{children(id, describedBy)}</div>
      {error ? (
        <p id={`${id}-error`} className="mt-1.5 text-sm text-red-600 dark:text-red-400">{error}</p>
      ) : hint ? (
        <p id={`${id}-hint`} className="mt-1.5 text-sm text-slate-500 dark:text-slate-400">{hint}</p>
      ) : null}
    </div>
  );
}

type Common = { label?: ReactNode; hint?: ReactNode; error?: string; wrapperClassName?: string; action?: ReactNode };

export const Input = forwardRef<HTMLInputElement, InputHTMLAttributes<HTMLInputElement> & Common & { leading?: ReactNode; trailing?: ReactNode }>(
  function Input({ label, hint, error, wrapperClassName, action, className, leading, trailing, required, ...rest }, ref) {
    return (
      <Field label={label} hint={hint} error={error} required={required} className={wrapperClassName} action={action}>
        {(id, describedBy) => (
          <div className="relative">
            {leading && <div className="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-slate-400">{leading}</div>}
            <input
              ref={ref}
              id={id}
              required={required}
              aria-invalid={error ? true : undefined}
              aria-describedby={describedBy}
              className={cx(control, error && invalid, leading && 'pl-9', trailing && 'pr-9', className)}
              {...rest}
            />
            {trailing && <div className="absolute inset-y-0 right-0 flex items-center pr-3 text-slate-400">{trailing}</div>}
          </div>
        )}
      </Field>
    );
  },
);

export const Textarea = forwardRef<HTMLTextAreaElement, TextareaHTMLAttributes<HTMLTextAreaElement> & Common>(function Textarea(
  { label, hint, error, wrapperClassName, action, className, rows = 4, required, ...rest },
  ref,
) {
  return (
    <Field label={label} hint={hint} error={error} required={required} className={wrapperClassName} action={action}>
      {(id, describedBy) => (
        <textarea
          ref={ref}
          id={id}
          rows={rows}
          required={required}
          aria-invalid={error ? true : undefined}
          aria-describedby={describedBy}
          className={cx(control, error && invalid, className)}
          {...rest}
        />
      )}
    </Field>
  );
});

export interface Option { value: string | number; label: string; disabled?: boolean }

export const Select = forwardRef<HTMLSelectElement, SelectHTMLAttributes<HTMLSelectElement> & Common & { options: Option[]; placeholder?: string }>(
  function Select({ label, hint, error, wrapperClassName, action, className, options, placeholder, required, ...rest }, ref) {
    return (
      <Field label={label} hint={hint} error={error} required={required} className={wrapperClassName} action={action}>
        {(id, describedBy) => (
          <div className="grid grid-cols-1">
            <select
              ref={ref}
              id={id}
              required={required}
              aria-invalid={error ? true : undefined}
              aria-describedby={describedBy}
              className={cx(control, 'col-start-1 row-start-1 appearance-none pr-8 dark:*:bg-slate-800', error && invalid, className)}
              {...rest}
            >
              {placeholder !== undefined && <option value="">{placeholder}</option>}
              {options.map((o) => (
                <option key={String(o.value)} value={o.value} disabled={o.disabled}>{o.label}</option>
              ))}
            </select>
            <ChevronDownIcon aria-hidden className="pointer-events-none col-start-1 row-start-1 mr-2 size-5 self-center justify-self-end text-slate-500 sm:size-4 dark:text-slate-400" />
          </div>
        )}
      </Field>
    );
  },
);

export function Toggle({ checked, onChange, label, description, disabled, className }: {
  checked: boolean; onChange: (v: boolean) => void; label?: ReactNode; description?: ReactNode; disabled?: boolean; className?: string;
}) {
  const sw = (
    <HSwitch
      checked={checked}
      onChange={onChange}
      disabled={disabled}
      aria-label={typeof label === 'string' ? label : undefined}
      className="group relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full bg-slate-200 p-0.5 inset-ring inset-ring-slate-900/5 outline-offset-2 outline-brand-600 transition-colors duration-200 ease-in-out focus-visible:outline-2 data-checked:bg-brand-600 data-disabled:cursor-not-allowed data-disabled:opacity-50 dark:bg-white/10 dark:inset-ring-white/10 dark:data-checked:bg-blue-500"
    >
      <span className="size-5 rounded-full bg-white shadow-xs ring-1 ring-slate-900/5 transition-transform duration-200 ease-in-out group-data-checked:translate-x-5" />
    </HSwitch>
  );
  if (!label) return sw;
  return (
    <div className={cx('flex items-center justify-between gap-4', className)}>
      <span className="flex grow flex-col">
        <span className="text-sm/6 font-medium text-slate-900 dark:text-white">{label}</span>
        {description && <span className="text-sm text-slate-500 dark:text-slate-400">{description}</span>}
      </span>
      {sw}
    </div>
  );
}

export function Checkbox({ checked, onChange, label, className, disabled }: { checked: boolean; onChange: (v: boolean) => void; label?: ReactNode; className?: string; disabled?: boolean }) {
  return (
    <label className={cx('inline-flex items-center gap-2 text-sm text-slate-700 dark:text-slate-300', disabled && 'opacity-50', className)}>
      <input
        type="checkbox"
        checked={checked}
        disabled={disabled}
        onChange={(e) => onChange(e.target.checked)}
        className="size-4 rounded border-slate-300 text-brand-600 accent-blue-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
      />
      {label}
    </label>
  );
}

/** A form section: heading and description on the left, fields on the right (Tailwind Plus "two-column" form). */
export function FormSection({ title, description, children, className }: { title: string; description?: ReactNode; children: ReactNode; className?: string }) {
  return (
    <div className={cx('grid grid-cols-1 gap-x-8 gap-y-6 py-8 first:pt-0 md:grid-cols-3', className)}>
      <div>
        <h2 className="text-base/7 font-semibold text-slate-900 dark:text-white">{title}</h2>
        {description && <p className="mt-1 text-sm/6 text-slate-600 dark:text-slate-400">{description}</p>}
      </div>
      {/* A child that does not pick its own width (no col-span class) spans the row; otherwise a nested grid gets 1/6. */}
      <div className="grid max-w-2xl grid-cols-1 gap-x-6 gap-y-6 sm:grid-cols-6 md:col-span-2 [&>*:not([class*=col-span])]:col-span-full">{children}</div>
    </div>
  );
}
