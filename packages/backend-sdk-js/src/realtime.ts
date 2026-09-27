import { ApiError } from './errors.js';
import type { RealtimeEvent } from './types.js';

export type SocketState = 'closed' | 'connecting' | 'open';

export interface WsSocket {
  readonly readyState: number;
  send(data: string): void;
  close(): void;
  onopen: ((e: unknown) => void) | null;
  onmessage: ((e: { data: string }) => void) | null;
  onerror: ((e: unknown) => void) | null;
  onclose: ((e: unknown) => void) | null;
}

export type WsFactory = (url: string) => WsSocket;

export interface SubscribeOptions<T = unknown> {
  channel: string;
  event?: string;
  onEvent: (e: RealtimeEvent<T>) => void;
  /** Project API key for `private-` / `presence-` channels. */
  apiKey?: string;
}

export interface Subscription {
  readonly channel: string;
  readonly active: boolean;
  unsubscribe(): Promise<void> | void;
}

export interface RealtimeConfig {
  /** e.g. `ws://reverb.test:8080/app/<key>?protocol=7` — from the Connect page. */
  wsUrl?: string | null;
  appKey?: string | null;
  wsFactory?: WsFactory | null;
  maxReconnectAttempts?: number;
}

const CHANNEL_RE = /^[A-Za-z0-9_.:-]{1,160}$/;

function defaultWsFactory(url: string): WsSocket {
  const WS = (globalThis as unknown as { WebSocket?: new (url: string) => WsSocket }).WebSocket;
  if (!WS) throw new Error('No WebSocket implementation: pass wsFactory (browser WebSocket or `ws` package) to BackendClient realtime config.');
  return new WS(url);
}

/**
 * Minimal Pusher-protocol client for Reverb. No duplicate subscriptions:
 * subscribing to an active channel returns the existing handle.
 */
export class RealtimeModule {
  private wsUrl: string | null;
  private appKey: string | null;
  private wsFactory: WsFactory;
  private maxReconnectAttempts: number;
  private socket: WsSocket | null = null;
  private socketId: string | null = null;
  private connecting: Promise<void> | null = null;
  private reconnectAttempts = 0;
  private readonly subs = new Map<string, { onEvent: (e: RealtimeEvent) => void; event?: string }>();
  private readonly apiKeys = new Map<string, string>();

  constructor(cfg?: RealtimeConfig) {
    this.wsUrl = cfg?.wsUrl ?? null;
    this.appKey = cfg?.appKey ?? null;
    this.wsFactory = cfg?.wsFactory ?? defaultWsFactory;
    this.maxReconnectAttempts = cfg?.maxReconnectAttempts ?? 5;
  }

  configure(cfg: RealtimeConfig): void {
    if (cfg.wsUrl !== undefined) this.wsUrl = cfg.wsUrl;
    if (cfg.appKey !== undefined) this.appKey = cfg.appKey;
    if (cfg.wsFactory !== undefined && cfg.wsFactory !== null) this.wsFactory = cfg.wsFactory;
    if (cfg.maxReconnectAttempts !== undefined) this.maxReconnectAttempts = cfg.maxReconnectAttempts;
  }

  get state(): SocketState {
    if (!this.socket) return 'closed';
    return this.socket.readyState === 1 ? 'open' : 'connecting';
  }

  activeChannels(): string[] {
    return [...this.subs.keys()];
  }

  async subscribe<T = unknown>(opts: SubscribeOptions<T>): Promise<Subscription> {
    const channel = opts.channel.trim();
    if (CHANNEL_RE.test(channel) !== true) throw new ApiError({ message: 'Invalid channel name.', code: 'BAD_REQUEST', status: 400 });
    if (this.subs.has(channel)) {
      // No duplicate subscriptions: return a handle to the existing one.
      return this.handle(channel);
    }
    if ((channel.startsWith('private-') || channel.startsWith('presence-')) && !opts.apiKey) {
      throw new ApiError({ message: 'Private channels require a project API key.', code: 'UNAUTHENTICATED', status: 401 });
    }
    this.subs.set(channel, { onEvent: opts.onEvent as (e: RealtimeEvent) => void, event: opts.event });
    if (opts.apiKey) this.apiKeys.set(channel, opts.apiKey);
    // connect() re-subscribes every known channel on (re)establishment, so
    // only send an explicit subscribe when the socket was already open.
    const wasOpen = this.socket !== null && this.socket.readyState === 1;
    try {
      await this.connect();
    } catch (e) {
      this.subs.delete(channel);
      this.apiKeys.delete(channel);
      throw e;
    }
    if (wasOpen) this.sendSubscribe(channel);
    return this.handle(channel);
  }

  async unsubscribe(channel: string): Promise<void> {
    if (!this.subs.has(channel)) return;
    this.subs.delete(channel);
    this.apiKeys.delete(channel);
    if (this.socket && this.socket.readyState === 1) {
      this.socket.send(JSON.stringify({ event: 'pusher:unsubscribe', data: { channel } }));
    }
    if (this.subs.size === 0) this.disconnect();
  }

  async unsubscribeAll(): Promise<void> {
    const channels = [...this.subs.keys()];
    for (const c of channels) await this.unsubscribe(c);
  }

  async connect(): Promise<void> {
    if (this.socket && this.socket.readyState === 1) return;
    if (this.connecting) return this.connecting;
    if (!this.wsUrl) throw new Error('Realtime not configured: pass realtime.wsUrl (+ appKey) to BackendClient.');
    this.connecting = new Promise<void>((resolve, reject) => {
      let socket: WsSocket;
      try {
        socket = this.wsFactory(this.wsUrl as string);
      } catch (e) {
        this.connecting = null;
        reject(e);
        return;
      }
      this.socket = socket;
      const timeout = setTimeout(() => {
        this.connecting = null;
        reject(new ApiError({ message: 'Realtime connection timed out', code: 'TIMEOUT', status: 0 }));
      }, 10000);
      socket.onopen = () => {};
      socket.onerror = () => {};
      socket.onclose = () => {
        this.socket = null;
        this.socketId = null;
        this.connecting = null;
        void this.maybeReconnect();
      };
      socket.onmessage = (msg) => {
        let frame: { event?: string; data?: unknown; channel?: string };
        try {
          frame = JSON.parse(msg.data) as typeof frame;
        } catch {
          return;
        }
        const data = typeof frame.data === 'string' ? safeJson(frame.data) : frame.data;
        if (frame.event === 'pusher:connection_established') {
          const d = data as { socket_id?: string };
          this.socketId = d?.socket_id ?? null;
          this.reconnectAttempts = 0;
          clearTimeout(timeout);
          this.connecting = null;
          // Re-subscribe everything after (re)connect.
          for (const ch of this.subs.keys()) this.sendSubscribe(ch);
          resolve();
          return;
        }
        if (frame.event === 'pusher:error') {
          clearTimeout(timeout);
          this.connecting = null;
          reject(new ApiError({ message: 'Realtime connection refused', code: 'UNAUTHENTICATED', status: 401 }));
          return;
        }
        // Protocol-internal frames (e.g. pusher_internal:subscription_succeeded)
        // are transport bookkeeping, never application events.
        if (typeof frame.event === 'string' && (frame.event.startsWith('pusher:') || frame.event.startsWith('pusher_internal:'))) {
          return;
        }
        const sub = frame.channel ? this.subs.get(frame.channel) : undefined;
        const targets = frame.channel
          ? (sub ? [{ channel: frame.channel, ...sub }] : [])
          : [...this.subs.entries()].map(([channel, s]) => ({ channel, ...s }));
        for (const t of targets) {
          if (t.event && frame.event !== t.event) continue;
          t.onEvent({ channel: t.channel, event: frame.event ?? '', data: (data as { message?: unknown })?.message ?? data });
        }
      };
    });
    return this.connecting;
  }

  disconnect(): void {
    try {
      this.socket?.close();
    } catch { /* ignore */ }
    this.socket = null;
    this.socketId = null;
    this.connecting = null;
  }

  private handle(channel: string): Subscription {
    return {
      channel,
      get active() {
        return true;
      },
      unsubscribe: () => this.unsubscribe(channel),
    };
  }

  private sendSubscribe(channel: string): void {
    if (!this.socket || this.socket.readyState !== 1) return;
    const auth = this.apiKeys.get(channel);
    // Channel auth digest is computed server-side via the auth endpoint in
    // production apps; here we forward the key reference so the app's own
    // authorizer can exchange it. Raw secrets are never fabricated client-side.
    this.socket.send(JSON.stringify({
      event: 'pusher:subscribe',
      data: auth ? { channel, auth: `key:${auth.slice(0, 12)}…` } : { channel },
    }));
  }

  private async maybeReconnect(): Promise<void> {
    if (this.subs.size === 0) return;
    if (this.reconnectAttempts >= this.maxReconnectAttempts) return;
    this.reconnectAttempts += 1;
    const delay = Math.min(1000 * 2 ** (this.reconnectAttempts - 1), 15000) + Math.random() * 250;
    await new Promise((r) => setTimeout(r, delay));
    if (this.subs.size === 0) return;
    try {
      await this.connect();
    } catch { /* backoff continues on next close */ }
  }
}

function safeJson(s: string): unknown {
  try {
    return JSON.parse(s);
  } catch {
    return s;
  }
}
