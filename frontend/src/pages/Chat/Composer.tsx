import { useRef, type Dispatch, type KeyboardEvent, type RefObject, type SetStateAction } from 'react';
import { BookOpenIcon, PaperAirplaneIcon, PaperClipIcon, PhotoIcon, StopIcon, XMarkIcon } from '@heroicons/react/24/outline';
import { Button, IconButton, cx, useToast } from '../../components/ui';
import { VoiceInput } from '../Speech/VoiceInput';

/** The input bar: attached image previews, attach/prompt/voice buttons, the textarea and Send/Stop. */
export function Composer({ inputRef, draft, setDraft, images, setImages, isVision, modelKey, streaming, hasPrompts, canVoice, onOpenPrompts, onSend, onStop }: {
  inputRef: RefObject<HTMLTextAreaElement | null>;
  draft: string;
  setDraft: Dispatch<SetStateAction<string>>;
  images: string[];
  setImages: Dispatch<SetStateAction<string[]>>;
  isVision: boolean;
  modelKey: string;
  streaming: boolean;
  hasPrompts: boolean;
  canVoice: boolean;
  onOpenPrompts: () => void;
  onSend: () => void;
  onStop: () => void;
}) {
  const toast = useToast();
  const fileRef = useRef<HTMLInputElement>(null);

function attach(files: FileList | null) {
  if (!files) return;
  Array.from(files).slice(0, 4).forEach((f) => {
    if (!f.type.startsWith('image/')) return;
    if (f.size > 8 * 1024 * 1024) { toast.error(`${f.name} is over 8 MB`); return; }
    const r = new FileReader();
    r.onload = () => setImages((p) => [...p, String(r.result)].slice(0, 4));
    r.readAsDataURL(f);
  });
  if (fileRef.current) fileRef.current.value = '';
}

  const onKey = (e: KeyboardEvent<HTMLTextAreaElement>) => {
    if (e.key === 'Enter' && !e.shiftKey && !e.nativeEvent.isComposing) { e.preventDefault(); onSend(); }
  };

  return (
    <div className="border-t border-slate-200 p-3 dark:border-white/10">
      <div className="mx-auto max-w-3xl">
        {images.length > 0 && (
          <div className="mb-2 flex flex-wrap gap-2">
            {images.map((src, j) => (
              <div key={j} className="relative">
                <img src={src} alt="Attachment" className="size-16 rounded-lg object-cover ring-1 ring-slate-200 dark:ring-white/10" />
                <button type="button" aria-label="Remove image" onClick={() => setImages((p) => p.filter((_, k) => k !== j))} className="absolute -top-1.5 -right-1.5 flex size-5 items-center justify-center rounded-full bg-slate-700 text-white dark:bg-slate-600">
                  <XMarkIcon aria-hidden className="size-3" />
                </button>
              </div>
            ))}
          </div>
        )}
        <div className="flex items-end gap-2">
          <input ref={fileRef} type="file" accept="image/*" multiple hidden onChange={(e) => attach(e.target.files)} />
          <IconButton
            label={isVision ? 'Attach images for the vision model' : 'Pick a vision model (e.g. llava, moondream) to attach images'}
            icon={isVision ? PhotoIcon : PaperClipIcon}
            disabled={!modelKey}
            className={cx('size-10 shrink-0 ring-1', isVision ? 'text-brand-600 ring-brand-200 dark:text-blue-400 dark:ring-blue-500/30' : 'ring-slate-200 dark:ring-white/10')}
            onClick={() => fileRef.current?.click()}
          />
          {hasPrompts && (
            <IconButton label="Insert a saved prompt" icon={BookOpenIcon} className="hidden size-10 shrink-0 ring-1 ring-slate-200 sm:inline-flex dark:ring-white/10" onClick={onOpenPrompts} />
          )}
          {canVoice && (
            <VoiceInput
              disabled={!modelKey || streaming}
              onText={(t) => { setDraft((d) => (d && !/\s$/.test(d) ? `${d} ${t}` : `${d}${t}`)); inputRef.current?.focus(); }}
            />
          )}
          <textarea
            ref={inputRef}
            value={draft}
            onChange={(e) => setDraft(e.target.value)}
            onKeyDown={onKey}
            disabled={!modelKey}
            rows={Math.min(6, Math.max(1, draft.split('\n').length))}
            aria-label="Message"
            placeholder={isVision ? 'Ask about an attached image…' : 'Message…  (Enter to send, Shift+Enter for a new line)'}
            className="block min-h-10 w-full resize-none rounded-md bg-white px-3 py-2 text-base text-slate-900 outline-1 -outline-offset-1 outline-slate-300 placeholder:text-slate-400 focus:outline-2 focus:-outline-offset-2 focus:outline-brand-600 disabled:cursor-not-allowed disabled:bg-slate-50 sm:text-sm/6 dark:bg-white/5 dark:text-white dark:outline-white/10 dark:placeholder:text-slate-500 dark:focus:outline-blue-500 dark:disabled:bg-white/[0.02]"
          />
          {streaming ? (
            <Button icon={StopIcon} onClick={onStop} className="h-10">Stop</Button>
          ) : (
            <Button variant="primary" icon={PaperAirplaneIcon} className="h-10" disabled={(!draft.trim() && !images.length) || !modelKey} onClick={onSend} aria-label="Send">
              <span className="hidden sm:inline">Send</span>
            </Button>
          )}
        </div>
      </div>
    </div>
  );
}
