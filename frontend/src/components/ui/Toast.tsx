import { createContext, useCallback, useContext, useState, type ReactNode } from 'react';
import { Transition } from '@headlessui/react';
import { CheckCircleIcon, ExclamationCircleIcon, InformationCircleIcon, XMarkIcon } from '@heroicons/react/24/outline';

type Tone = 'success' | 'error' | 'info';
interface ToastItem { id: number; tone: Tone; title: string; body?: string }
interface ToastApi { success: (t: string, b?: string) => void; error: (t: string | unknown, b?: string) => void; info: (t: string, b?: string) => void }

const Ctx = createContext<ToastApi | null>(null);
let seq = 0;

export function ToastProvider({ children }: { children: ReactNode }) {
  const [items, setItems] = useState<ToastItem[]>([]);
  const dismiss = useCallback((id: number) => setItems((xs) => xs.filter((x) => x.id !== id)), []);
  const push = useCallback((tone: Tone, title: string, body?: string) => {
    const id = ++seq;
    setItems((xs) => [...xs.slice(-3), { id, tone, title, body }]);
    setTimeout(() => dismiss(id), tone === 'error' ? 7000 : 4000);
  }, [dismiss]);
  const api: ToastApi = {
    success: (t, b) => push('success', t, b),
    info: (t, b) => push('info', t, b),
    error: (t, b) => push('error', t instanceof Error ? t.message : typeof t === 'string' ? t : 'Something went wrong.', b),
  };
  return (
    <Ctx.Provider value={api}>
      {children}
      <div aria-live="assertive" className="pointer-events-none fixed inset-0 z-[60] flex items-end px-4 py-6 sm:items-start sm:p-6">
        <div className="flex w-full flex-col items-center space-y-3 sm:items-end">
          {items.map((t) => (
            <Transition key={t.id} show appear>
              <div className="pointer-events-auto w-full max-w-sm rounded-lg bg-white shadow-lg ring-1 ring-slate-900/5 transition data-closed:opacity-0 data-enter:transform data-enter:duration-300 data-enter:ease-out data-closed:data-enter:translate-y-2 sm:data-closed:data-enter:translate-x-2 sm:data-closed:data-enter:translate-y-0 dark:bg-slate-800 dark:ring-white/10">
                <div className="flex items-start gap-3 p-4">
                  {t.tone === 'success' ? (
                    <CheckCircleIcon aria-hidden className="size-6 shrink-0 text-green-500" />
                  ) : t.tone === 'error' ? (
                    <ExclamationCircleIcon aria-hidden className="size-6 shrink-0 text-red-500" />
                  ) : (
                    <InformationCircleIcon aria-hidden className="size-6 shrink-0 text-blue-500" />
                  )}
                  <div className="w-0 flex-1 pt-0.5">
                    <p className="text-sm font-medium text-slate-900 dark:text-white">{t.title}</p>
                    {t.body && <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">{t.body}</p>}
                  </div>
                  <button type="button" onClick={() => dismiss(t.id)} className="rounded-md text-slate-400 hover:text-slate-500 focus-visible:outline-2 focus-visible:outline-brand-600 dark:hover:text-white">
                    <span className="sr-only">Close</span>
                    <XMarkIcon aria-hidden className="size-5" />
                  </button>
                </div>
              </div>
            </Transition>
          ))}
        </div>
      </div>
    </Ctx.Provider>
  );
}

export function useToast() {
  const v = useContext(Ctx);
  if (!v) throw new Error('useToast outside ToastProvider');
  return v;
}
