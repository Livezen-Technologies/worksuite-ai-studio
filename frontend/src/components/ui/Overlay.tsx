import { useState, type ComponentType, type ReactNode, type SVGProps } from 'react';
import { Dialog, DialogBackdrop, DialogPanel, DialogTitle, Menu, MenuButton, MenuItem, MenuItems } from '@headlessui/react';
import { EllipsisVerticalIcon, ExclamationTriangleIcon, XMarkIcon } from '@heroicons/react/24/outline';
import { Button } from './Button';
import { cx } from './cx';

const widths = { sm: 'sm:max-w-md', md: 'sm:max-w-lg', lg: 'sm:max-w-2xl', xl: 'sm:max-w-4xl' };

export function Modal({ open, onClose, title, description, children, footer, size = 'md' }: {
  open: boolean; onClose: () => void; title: ReactNode; description?: ReactNode; children?: ReactNode; footer?: ReactNode; size?: keyof typeof widths;
}) {
  return (
    <Dialog open={open} onClose={onClose} className="relative z-50">
      <DialogBackdrop transition className="fixed inset-0 bg-slate-900/50 transition-opacity data-closed:opacity-0 data-enter:duration-200 data-enter:ease-out data-leave:duration-150 data-leave:ease-in dark:bg-slate-950/70" />
      <div className="fixed inset-0 z-10 w-screen overflow-y-auto">
        <div className="flex min-h-full items-end justify-center p-4 sm:items-center sm:p-6">
          <DialogPanel
            transition
            className={cx(
              'relative w-full transform overflow-hidden rounded-xl bg-white text-left shadow-xl ring-1 ring-slate-900/5 transition-all data-closed:translate-y-4 data-closed:opacity-0 data-enter:duration-200 data-enter:ease-out data-leave:duration-150 data-leave:ease-in sm:data-closed:translate-y-0 sm:data-closed:scale-95 dark:bg-slate-900 dark:ring-white/10',
              widths[size],
            )}
          >
            <div className="flex items-start justify-between gap-4 border-b border-slate-200 px-5 py-4 dark:border-white/10">
              <div className="min-w-0">
                <DialogTitle className="text-base font-semibold text-slate-900 dark:text-white">{title}</DialogTitle>
                {description && <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">{description}</p>}
              </div>
              <button type="button" onClick={onClose} className="-m-1 rounded-md p-1 text-slate-400 hover:text-slate-600 focus-visible:outline-2 focus-visible:outline-brand-600 dark:hover:text-white">
                <span className="sr-only">Close</span>
                <XMarkIcon aria-hidden className="size-5" />
              </button>
            </div>
            {children && <div className="max-h-[70vh] overflow-y-auto px-5 py-5">{children}</div>}
            {footer && <div className="flex flex-col-reverse gap-2 border-t border-slate-200 bg-slate-50 px-5 py-3 sm:flex-row sm:justify-end dark:border-white/10 dark:bg-white/[0.02]">{footer}</div>}
          </DialogPanel>
        </div>
      </div>
    </Dialog>
  );
}

/** A form inside a modal: Enter submits, the primary button shows progress. */
export function FormModal({ open, onClose, title, description, onSubmit, submitLabel = 'Save', submitting, children, size, danger }: {
  open: boolean; onClose: () => void; title: ReactNode; description?: ReactNode; onSubmit: () => void; submitLabel?: string; submitting?: boolean; children: ReactNode; size?: keyof typeof widths; danger?: boolean;
}) {
  return (
    <Modal open={open} onClose={onClose} title={title} description={description} size={size}>
      <form
        onSubmit={(e) => { e.preventDefault(); onSubmit(); }}
        className="space-y-5"
      >
        {children}
        <div className="-mx-5 -mb-5 flex flex-col-reverse gap-2 border-t border-slate-200 bg-slate-50 px-5 py-3 sm:flex-row sm:justify-end dark:border-white/10 dark:bg-white/[0.02]">
          <Button onClick={onClose}>Cancel</Button>
          <Button type="submit" variant={danger ? 'danger' : 'primary'} loading={submitting}>{submitLabel}</Button>
        </div>
      </form>
    </Modal>
  );
}

export function ConfirmDialog({ open, onClose, onConfirm, title, message, confirmLabel = 'Delete', busy, tone = 'danger' }: {
  open: boolean; onClose: () => void; onConfirm: () => void; title: string; message: ReactNode; confirmLabel?: string; busy?: boolean; tone?: 'danger' | 'primary';
}) {
  return (
    <Dialog open={open} onClose={onClose} className="relative z-50">
      <DialogBackdrop transition className="fixed inset-0 bg-slate-900/50 transition-opacity data-closed:opacity-0 data-enter:duration-200 data-leave:duration-150 dark:bg-slate-950/70" />
      <div className="fixed inset-0 z-10 flex min-h-full items-end justify-center p-4 sm:items-center">
        <DialogPanel transition className="w-full max-w-md transform overflow-hidden rounded-xl bg-white p-5 text-left shadow-xl ring-1 ring-slate-900/5 transition-all data-closed:translate-y-4 data-closed:opacity-0 data-enter:duration-200 sm:data-closed:scale-95 dark:bg-slate-900 dark:ring-white/10">
          <div className="sm:flex sm:items-start sm:gap-4">
            <div className={cx('mx-auto flex size-11 shrink-0 items-center justify-center rounded-full sm:mx-0 sm:size-10', tone === 'danger' ? 'bg-red-100 dark:bg-red-500/10' : 'bg-blue-100 dark:bg-blue-500/10')}>
              <ExclamationTriangleIcon aria-hidden className={cx('size-6', tone === 'danger' ? 'text-red-600 dark:text-red-400' : 'text-brand-600 dark:text-blue-400')} />
            </div>
            <div className="mt-3 text-center sm:mt-0 sm:text-left">
              <DialogTitle className="text-base font-semibold text-slate-900 dark:text-white">{title}</DialogTitle>
              <div className="mt-2 text-sm text-slate-500 dark:text-slate-400">{message}</div>
            </div>
          </div>
          <div className="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button onClick={onClose}>Cancel</Button>
            <Button variant={tone === 'danger' ? 'danger' : 'primary'} loading={busy} onClick={onConfirm}>{confirmLabel}</Button>
          </div>
        </DialogPanel>
      </div>
    </Dialog>
  );
}

/** Hook-style confirm: `const confirm = useConfirm(); confirm({...}).then(ok => ...)` is avoided in favour of explicit state. */
export function useDisclosure(initial = false) {
  const [open, setOpen] = useState(initial);
  return { open, onOpen: () => setOpen(true), onClose: () => setOpen(false), setOpen };
}

export interface RowAction {
  label: string;
  icon?: ComponentType<SVGProps<SVGSVGElement>>;
  onClick: () => void;
  danger?: boolean;
  disabled?: boolean;
  hidden?: boolean;
}

/** The per-row "⋮" menu used by every table. */
export function ActionMenu({ actions, label = 'Actions' }: { actions: RowAction[]; label?: string }) {
  const shown = actions.filter((a) => !a.hidden);
  if (!shown.length) return null;
  return (
    <Menu as="div" className="relative inline-block text-left">
      <MenuButton className="-m-1.5 flex items-center rounded-md p-1.5 text-slate-500 hover:bg-slate-100 hover:text-slate-900 focus-visible:outline-2 focus-visible:outline-brand-600 dark:text-slate-400 dark:hover:bg-white/10 dark:hover:text-white">
        <span className="sr-only">{label}</span>
        <EllipsisVerticalIcon aria-hidden className="size-5" />
      </MenuButton>
      <MenuItems
        transition
        anchor="bottom end"
        className="z-50 mt-1 w-48 origin-top-right rounded-md bg-white py-1 shadow-lg ring-1 ring-slate-900/5 transition focus:outline-none data-closed:scale-95 data-closed:opacity-0 data-enter:duration-100 data-leave:duration-75 dark:bg-slate-800 dark:ring-white/10"
      >
        {shown.map((a) => (
          <MenuItem key={a.label} disabled={a.disabled}>
            <button
              type="button"
              onClick={a.onClick}
              className={cx(
                'flex w-full items-center gap-2.5 px-3 py-2 text-left text-sm data-disabled:opacity-40 data-focus:bg-slate-50 data-focus:outline-hidden dark:data-focus:bg-white/5',
                a.danger ? 'text-red-600 dark:text-red-400' : 'text-slate-700 dark:text-slate-200',
              )}
            >
              {a.icon && <a.icon aria-hidden className={cx('size-4', a.danger ? '' : 'text-slate-400')} />}
              {a.label}
            </button>
          </MenuItem>
        ))}
      </MenuItems>
    </Menu>
  );
}
