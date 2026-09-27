import type { HttpClient } from './http.js';
import type { StorageObject, UploadOptions } from './types.js';

function toForm(file: Blob | Buffer | Uint8Array, opts: UploadOptions): FormData {
  const form = new FormData();
  const name = opts.fileName ?? 'file';
  const typed = opts.mime ? new Blob([file as BlobPart], { type: opts.mime }) : (file as BlobPart);
  form.append('file', typed instanceof Blob ? typed : new Blob([typed]), name);
  if (opts.key) form.append('key', opts.key);
  if (opts.visibility) form.append('visibility', opts.visibility);
  return form;
}

/** File storage. Auth: user token or API key with `storage:read/write`. */
export class StorageModule {
  constructor(private readonly http: HttpClient) {}

  async upload(file: Blob | Buffer | Uint8Array, opts: UploadOptions): Promise<StorageObject> {
    const { data } = await this.http.request<StorageObject>('POST', `/api/v1/storage/${encodeURIComponent(opts.bucket)}/upload`, {
      form: toForm(file, opts),
      query: opts.key ? { prefix: opts.key } : undefined,
      headers: opts.headers,
      timeoutMs: opts.timeoutMs ?? 60000,
      signal: opts.signal,
      token: opts.token,
      apiKey: opts.apiKey,
    });
    return data;
  }

  /** Returns the raw Response so callers can stream (`res.body`) or buffer it. */
  async download(bucket: string, key: string, opts?: { token?: string | null; apiKey?: string | null; timeoutMs?: number; signal?: AbortSignal }): Promise<Response> {
    const headers = await this.http.buildHeaders(undefined, { token: opts?.token, apiKey: opts?.apiKey });
    const url = `${this.http.baseUrl}/api/v1/storage/${encodeURIComponent(bucket)}/${key.split('/').map(encodeURIComponent).join('/')}`;
    const ctrl = new AbortController();
    const timer = (opts?.timeoutMs ?? this.http.timeoutMs) > 0
      ? setTimeout(() => ctrl.abort(new Error('timeout')), opts?.timeoutMs ?? this.http.timeoutMs)
      : undefined;
    try {
      return await this.http.fetchFn(url, { method: 'GET', headers, signal: opts?.signal ?? ctrl.signal });
    } finally {
      if (timer) clearTimeout(timer);
    }
  }

  /** Full signed-URL download link (signature is the bearer; URL expires). */
  async signedUrl(bucket: string, key: string, opts?: { expiresInSeconds?: number }): Promise<string> {
    const { data } = await this.http.post<{ url: string }>(`/api/v1/storage/${encodeURIComponent(bucket)}/signed-url`, {
      key,
      expires_in: opts?.expiresInSeconds ?? 3600,
    });
    return data.url;
  }

  async remove(bucket: string, key: string): Promise<void> {
    await this.http.delete(`/api/v1/storage/${encodeURIComponent(bucket)}/${key.split('/').map(encodeURIComponent).join('/')}`);
  }
}
