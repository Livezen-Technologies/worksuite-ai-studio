import { ApiError, renewSession, tokens, url } from '../../lib/api';

/**
 * Multipart upload with progress (fetch cannot report upload progress).
 * Resolves with the `data` of the `{status, message, data}` envelope plus the message.
 */
export function uploadWithProgress<T>(
  path: string,
  form: FormData,
  onProgress: (fraction: number) => void,
  signal?: AbortSignal,
): Promise<{ data: T; message?: string }> {
  // An expired access token answers 401 before the body is read; renew once and send again.
  return once<T>(path, form, onProgress, signal).catch(async (e) => {
    if (e instanceof ApiError && e.status === 401 && tokens.refresh && (await renewSession())) return once<T>(path, form, onProgress, signal);
    throw e;
  });
}

function once<T>(path: string, form: FormData, onProgress: (fraction: number) => void, signal?: AbortSignal): Promise<{ data: T; message?: string }> {
  return new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest();
    xhr.open('POST', url(path));
    xhr.setRequestHeader('Accept', 'application/json');
    if (tokens.access) xhr.setRequestHeader('Authorization', `Bearer ${tokens.access}`);
    xhr.upload.onprogress = (e) => { if (e.lengthComputable) onProgress(e.loaded / e.total); };
    xhr.onload = () => {
      let body: { status?: string; message?: string; data?: unknown } | null = null;
      try { body = JSON.parse(xhr.responseText); } catch { /* not JSON */ }
      if (xhr.status < 200 || xhr.status >= 300 || body?.status === 'error') {
        const msg = body?.message || (xhr.status === 413 ? 'The file is larger than the server accepts.' : `Upload failed (${xhr.status})`);
        reject(new ApiError(msg, xhr.status, body?.data));
      } else resolve({ data: body?.data as T, message: body?.message });
    };
    xhr.onerror = () => reject(new ApiError('Network error during upload.', 0));
    xhr.onabort = () => reject(new ApiError('Upload cancelled.', 0));
    signal?.addEventListener('abort', () => xhr.abort());
    xhr.send(form);
  });
}
