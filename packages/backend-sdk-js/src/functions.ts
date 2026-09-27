import { HttpClient } from './http.js';
import type { FunctionResponse } from './types.js';

export interface InvokeOptions {
  method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';
  query?: Record<string, string | number | boolean | undefined | null>;
  body?: unknown;
  headers?: Record<string, string>;
  timeoutMs?: number;
  signal?: AbortSignal;
  token?: string | null;
  apiKey?: string | null;
}

/**
 * Server functions (`/f/{project}/{function}` on the functions host —
 * usually the console host, NOT the project API host).
 * Auth mode per function: public | key (`functions:invoke`) | user | internal.
 */
export class FunctionsModule {
  constructor(
    private readonly http: HttpClient,
    private readonly projectSlug: string | null,
  ) {}

  private path(slug: string): string {
    if (!this.projectSlug) throw new Error('Functions not configured: pass projectSlug to BackendClient.');
    return `/f/${encodeURIComponent(this.projectSlug)}/${encodeURIComponent(slug)}`;
  }

  async invoke<T = unknown>(slug: string, opts?: InvokeOptions): Promise<FunctionResponse<T>> {
    const started = Date.now();
    const res = await this.http.request<T>(opts?.method ?? 'POST', this.path(slug), {
      query: opts?.query,
      headers: opts?.headers,
      body: (opts?.method ?? 'POST') === 'GET' ? undefined : (opts?.body ?? {}),
      timeoutMs: opts?.timeoutMs,
      signal: opts?.signal,
      token: opts?.token,
      apiKey: opts?.apiKey,
    });
    const data = res.data as unknown as Record<string, unknown> | null;
    return {
      data: res.data,
      status: res.status,
      requestId: res.requestId,
      durationMs: Date.now() - started,
      version: data && typeof data === 'object' && typeof data['version'] === 'number'
        ? (data['version'] as number)
        : undefined,
    };
  }

  /** Direct fetch for non-JSON / streaming function responses. */
  rawUrl(slug: string): string {
    return `${this.http.baseUrl}${this.path(slug)}`;
  }
}
