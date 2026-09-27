import { AuthModule } from './auth.js';
import { HttpClient, type FetchFn } from './http.js';
import { FunctionsModule } from './functions.js';
import { RealtimeModule, type RealtimeConfig } from './realtime.js';
import { StorageModule } from './storage.js';
import { UsersModule } from './users.js';
import type { TokenStore } from './token-store.js';
import { MemoryTokenStore } from './token-store.js';

export interface BackendClientOptions {
  /** Project API host, e.g. `https://api.example.com`. Trailing slash ignored. */
  baseUrl: string;
  /** CLIENT-SAFE project API key (`prefix.secret`). Never a server secret in browsers. */
  apiKey?: string | null;
  apiKeyPlacement?: 'bearer' | 'header';
  tokenStore?: TokenStore;
  timeoutMs?: number;
  fetchFn?: FetchFn;
  headers?: Record<string, string>;
  /** Console host for `/f/{project}/{function}`. Defaults to baseUrl. */
  functionsBaseUrl?: string | null;
  projectSlug?: string | null;
  realtime?: RealtimeConfig;
}

/**
 * Official client for the Laravel Backend Platform (API v1).
 *
 * ```ts
 * const backend = new BackendClient({ baseUrl: 'https://api.example.com', apiKey: 'cp_abc…' });
 * await backend.auth.login({ email, password });
 * await backend.auth.me();
 * await backend.storage.upload(file, { bucket: 'avatars' });
 * await backend.functions.invoke('hello-platform', { body: { name: 'Ada' } });
 * ```
 *
 * Thin by design: every method maps to one documented HTTP/WebSocket call
 * (see docs/CLIENT_API_CONTRACT.md), so failures are debuggable with the
 * request ID on the thrown ApiError.
 */
export class BackendClient {
  readonly http: HttpClient;
  readonly functionsHttp: HttpClient;
  readonly auth: AuthModule;
  readonly users: UsersModule;
  readonly storage: StorageModule;
  readonly functions: FunctionsModule;
  readonly realtime: RealtimeModule;
  readonly projectSlug: string | null;

  constructor(opts: BackendClientOptions) {
    const store = opts.tokenStore ?? new MemoryTokenStore();
    this.http = new HttpClient({
      baseUrl: opts.baseUrl,
      apiKey: opts.apiKey,
      apiKeyPlacement: opts.apiKeyPlacement,
      timeoutMs: opts.timeoutMs,
      fetchFn: opts.fetchFn,
      tokenStore: store,
      headers: opts.headers,
    });
    this.functionsHttp = new HttpClient({
      baseUrl: opts.functionsBaseUrl ?? opts.baseUrl,
      apiKey: opts.apiKey,
      apiKeyPlacement: opts.apiKeyPlacement,
      timeoutMs: opts.timeoutMs,
      fetchFn: opts.fetchFn,
      tokenStore: store,
      headers: opts.headers,
    });
    this.projectSlug = opts.projectSlug ?? null;
    this.auth = new AuthModule(this.http);
    this.users = new UsersModule(this.http);
    this.storage = new StorageModule(this.http);
    this.functions = new FunctionsModule(this.functionsHttp, this.projectSlug);
    this.realtime = new RealtimeModule(opts.realtime);
  }

  /** Health probe (`GET /api/health`). Never throws auth errors. */
  async health(): Promise<{ ok: boolean; [k: string]: unknown }> {
    const { data } = await this.http.get<{ ok: boolean; [k: string]: unknown }>('/api/health');
    return data;
  }

  setApiKey(key: string | null): void {
    this.http.apiKey = key;
    this.functionsHttp.apiKey = key;
  }
}
