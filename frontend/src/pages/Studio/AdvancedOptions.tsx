import type { Dispatch, RefObject, SetStateAction } from 'react';
import { Textarea } from '../../components/ui';
import FilePick from './FilePick';
import type { Upload } from './shared';

/** The collapsible LoRA adapter / chat template section. */
export default function AdvancedOptions({ showAdvanced, setShowAdvanced, adapter, setAdapter, template, setTemplate, disabled, serverId, adapterInput, onUpload }: {
  showAdvanced: boolean; setShowAdvanced: Dispatch<SetStateAction<boolean>>; adapter: Upload | null; setAdapter: (u: Upload | null) => void;
  template: string; setTemplate: (v: string) => void; disabled: boolean; serverId: string;
  adapterInput: RefObject<HTMLInputElement | null>; onUpload: (file: File) => void;
}) {
  return (
  <div>
    <button
      type="button"
      onClick={() => setShowAdvanced((v) => !v)}
      className="text-sm font-medium text-brand-600 hover:text-brand-500 dark:text-blue-400 dark:hover:text-blue-300"
      aria-expanded={showAdvanced}
    >
      {showAdvanced ? 'Hide' : 'Show'} advanced: LoRA adapter / chat template
    </button>
    {showAdvanced && (
      <div className="mt-4 space-y-4 rounded-lg border border-slate-200 p-4 dark:border-white/10">
        <FilePick
          label="LoRA adapter"
          hint="An adapter from a training run, applied on top of the base model."
          upload={adapter}
          disabled={disabled || !serverId}
          onPick={() => adapterInput.current?.click()}
          onClear={() => setAdapter(null)}
        />
        <input ref={adapterInput} type="file" hidden onChange={(e) => { const f = e.target.files?.[0]; if (f) onUpload(f); e.target.value = ''; }} />
        <Textarea label="Chat template (rarely needed)" rows={4} value={template} onChange={(e) => setTemplate(e.target.value)} className="font-mono" placeholder="{{ .System }} {{ .Prompt }}" disabled={disabled} />
      </div>
    )}
  </div>
  );
}
