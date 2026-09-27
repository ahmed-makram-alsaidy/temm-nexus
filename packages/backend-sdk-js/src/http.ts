import { ApiError, toApiError } from './errors.js';
import type { TokenStore } from './token-store.js';
import { MemoryTokenStore } from './token-store.js';

export type FetchFn = typeof fetch;

export interface HttpConfig {
  baseUrl: string;
  apiKey?: string | null;
  /** 'bearer' (default) sends `Authorization: Bearer <key>`; 'header' sends `X-API-Key`. */
  apiKeyPlacement?: 'bearer' | 'header';
  timeoutMs?: number;
  fetchFn?: FetchFn;
  tokenStore?: TokenStore;
  /** Extra headers applied to every request (explicit per-call headers win). */
  headers?: Record<string, string>;
}

export interface CallOptions {
  query?: Record<string, string | number | boolean | undefined | null>;
  headers?: Record<string, string>;
  body?: unknown;
  form?: FormData;
  timeoutMs?: number;
  signal?: AbortSignal;
  token?: string | null;
  apiKey?: string | null;
  requestId?: string;
}

function newRequestId(): string {
  const c = globalThis.crypto as unknown as { randomUUID?: () => string } | undefined;
  if (c && typeof c.randomUUID === 'function') return c.randomUUID();
  return `req_${Date.now().toString(36)}_${Math.random().toString(36).slice(2, 10)}`;
}

export function buildQuery(query?: Record<string, string | number | boolean | undefined | null>): string {
  if (!query) return '';
  const p = new URLSearchParams();
  for (const [k, v] of Object.entries(query)) {
    if (v === undefined || v === null) continue;
    p.append(k, String(v));
  }
  const s = p.toString();
  return s ? `?${s}` : '';
}

/** Build `?filter[x]=&search=&sort=&page=&per_page=` per the contract. */
export function qs(args: {
  page?: number;
  perPage?: number;
  search?: string;
  sort?: string | string[];
  filter?: Record<string, string | number | boolean>;
}): Record<string, string | number | boolean> {
  const out: Record<string, string | number | boolean> = {};
  if (args.page !== undefined) out['page'] = args.page;
  if (args.perPage !== undefined) out['per_page'] = args.perPage;
  if (args.search) out['search'] = args.search;
  if (args.sort) out['sort'] = Array.isArray(args.sort) ? args.sort.join(',') : args.sort;
  if (args.filter) for (const [k, v] of Object.entries(args.filter)) out[`filter[${k}]`] = v;
  return out;
}

function parseRetryAfter(h: Headers): number | undefined {
  const v = h.get('retry-after');
  if (!v) return undefined;
  const s = Number(v);
  if (!Number.isFinite(s) || s < 0) return undefined;
  return s * 1000;
}

async function parseBody(res: Response): Promise<unknown> {
  const text = await res.text();
  if (!text) return null;
  try {
    return JSON.parse(text);
  } catch {
    return text;
  }
}

export class HttpClient {
  readonly baseUrl: string;
  apiKey: string | null;
  readonly apiKeyPlacement: 'bearer' | 'header';
  readonly timeoutMs: number;
  readonly fetchFn: FetchFn;
  readonly tokenStore: TokenStore;
  readonly defaultHeaders: Record<string, string>;

  constructor(cfg: HttpConfig) {
    if (!cfg.baseUrl || typeof cfg.baseUrl !== 'string') throw new Error('baseUrl is required');
    this.baseUrl = cfg.baseUrl.replace(/\/+$/, '');
    this.apiKey = cfg.apiKey ?? null;
    this.apiKeyPlacement = cfg.apiKeyPlacement ?? 'bearer';
    this.timeoutMs = cfg.timeoutMs ?? 15000;
    this.fetchFn = cfg.fetchFn ?? fetch.bind(globalThis);
    this.tokenStore = cfg.tokenStore ?? new MemoryTokenStore();
    this.defaultHeaders = { ...(cfg.headers ?? {}) };
  }

  async currentToken(): Promise<string | null> {
    return (await this.tokenStore.get()) ?? null;
  }

  /** Headers for a call. Precedence (high → low): explicit token, explicit
   * apiKey, stored token, configured apiKey. Explicit per-call credentials
   * always override stored ones; an explicit `Authorization` header wins. */
  async buildHeaders(explicit?: Record<string, string>, opts?: { token?: string | null; apiKey?: string | null }): Promise<Record<string, string>> {
    const headers: Record<string, string> = { Accept: 'application/json', ...this.defaultHeaders };
    const explicitToken = opts?.token !== undefined ? opts.token : undefined;
    const explicitKey = opts?.apiKey !== undefined ? opts.apiKey : undefined;
    const stored = explicitToken === undefined && explicitKey === undefined ? await this.currentToken() : null;
    const token = explicitToken !== undefined ? explicitToken : stored;
    if (token) {
      headers['Authorization'] = `Bearer ${token}`;
    } else {
      const key = explicitKey !== undefined ? explicitKey : this.apiKey;
      if (key && !headers['Authorization']) {
        if (this.apiKeyPlacement === 'header') headers['X-API-Key'] = key;
        else headers['Authorization'] = `Bearer ${key}`;
      }
    }
    if (explicit) for (const [k, v] of Object.entries(explicit)) headers[k] = v;
    return headers;
  }

  async request<T>(method: string, path: string, opts?: CallOptions): Promise<{ data: T; status: number; headers: Headers; requestId: string }> {
    const requestId = opts?.requestId ?? newRequestId();
    const url = `${this.baseUrl}${path.startsWith('/') ? '' : '/'}${path}${buildQuery(opts?.query)}`;
    const headers = await this.buildHeaders(
      { 'X-Request-ID': requestId, ...(opts?.headers ?? {}) },
      { token: opts?.token, apiKey: opts?.apiKey },
    );
    let body: BodyInit | undefined;
    if (opts?.form) {
      body = opts.form as BodyInit;
      delete headers['Content-Type']; // let fetch set the multipart boundary
    } else if (opts?.body !== undefined) {
      headers['Content-Type'] = headers['Content-Type'] ?? 'application/json';
      body = typeof opts.body === 'string' ? opts.body : JSON.stringify(opts.body);
    }

    const timeoutMs = opts?.timeoutMs ?? this.timeoutMs;
    const ctrl = new AbortController();
    const timer = timeoutMs > 0 ? setTimeout(() => ctrl.abort(new Error('timeout')), timeoutMs) : undefined;
    const onAbort = () => ctrl.abort(opts?.signal?.reason);
    opts?.signal?.addEventListener('abort', onAbort, { once: true });
    if (opts?.signal?.aborted) ctrl.abort(opts.signal.reason);

    let res: Response;
    try {
      res = await this.fetchFn(url, { method, headers, body, signal: ctrl.signal });
    } catch (e: unknown) {
      const err = e as Error & { name?: string };
      if (opts?.signal?.aborted || ctrl.signal.aborted) {
        const abortedByCaller = opts?.signal?.aborted === true;
        throw new ApiError({
          message: abortedByCaller ? 'Request aborted' : `Request timed out after ${timeoutMs}ms`,
          code: abortedByCaller ? 'ABORTED' : 'TIMEOUT',
          status: 0,
          requestId,
        });
      }
      throw new ApiError({ message: err?.message ?? 'Network error', code: 'NETWORK_ERROR', status: 0, requestId });
    } finally {
      if (timer) clearTimeout(timer);
      opts?.signal?.removeEventListener('abort', onAbort);
    }

    const responseId = res.headers.get('x-request-id') ?? requestId;
    if (!res.ok) {
      const parsed = await parseBody(res);
      throw toApiError({ status: res.status, body: parsed, requestId: responseId, retryAfterMs: parseRetryAfter(res.headers) });
    }
    const parsed = await parseBody(res);
    return { data: parsed as T, status: res.status, headers: res.headers, requestId: responseId };
  }

  get<T>(path: string, opts?: CallOptions): Promise<{ data: T; status: number; headers: Headers; requestId: string }> {
    return this.request<T>('GET', path, opts);
  }
  post<T>(path: string, body?: unknown, opts?: CallOptions): Promise<{ data: T; status: number; headers: Headers; requestId: string }> {
    return this.request<T>('POST', path, { ...opts, body });
  }
  put<T>(path: string, body?: unknown, opts?: CallOptions): Promise<{ data: T; status: number; headers: Headers; requestId: string }> {
    return this.request<T>('PUT', path, { ...opts, body });
  }
  patch<T>(path: string, body?: unknown, opts?: CallOptions): Promise<{ data: T; status: number; headers: Headers; requestId: string }> {
    return this.request<T>('PATCH', path, { ...opts, body });
  }
  delete<T>(path: string, opts?: CallOptions): Promise<{ data: T; status: number; headers: Headers; requestId: string }> {
    return this.request<T>('DELETE', path, opts);
  }
}
