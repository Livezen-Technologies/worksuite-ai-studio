import type { RefObject } from 'react';
import { Select } from '../../components/ui';
import FilePick from './FilePick';
import type { Mode, Upload } from './shared';

/** The base-model picker, or the GGUF import field, depending on the build mode. */
export default function ModelSource({ mode, from, setFrom, modelOptions, modelsLoading, gguf, setGguf, errors, disabled, serverId, ggufInput, onUpload }: {
  mode: Mode; from: string; setFrom: (v: string) => void; modelOptions: { value: string; label: string }[]; modelsLoading: boolean;
  gguf: Upload | null; setGguf: (u: Upload | null) => void; errors: Record<string, string>; disabled: boolean; serverId: string;
  ggufInput: RefObject<HTMLInputElement | null>; onUpload: (file: File) => void;
}) {
  return (
    <>
    {mode === 'customize' ? (
      <Select
        label="Base model (installed on the node)"
        required
        value={from}
        onChange={(e) => setFrom(e.target.value)}
        options={modelOptions}
        placeholder={modelsLoading ? 'Loading models…' : modelOptions.length ? 'Select a model' : 'No models on this node — install one first'}
        error={errors.from}
        disabled={disabled || !serverId}
      />
    ) : (
      <FilePick
        label="Trained model (.gguf)"
        hint="A full GGUF exported by a training kit. Large files are fine — it is streamed into the node's blob store."
        upload={gguf}
        error={errors.gguf}
        disabled={disabled || !serverId}
        onPick={() => ggufInput.current?.click()}
        onClear={() => setGguf(null)}
      />
    )}
    <input ref={ggufInput} type="file" accept=".gguf" hidden onChange={(e) => { const f = e.target.files?.[0]; if (f) onUpload(f); e.target.value = ''; }} />
    </>
  );
}
