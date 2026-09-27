/**
 * Pluggable token storage. The SDK core NEVER touches localStorage directly —
 * the app injects the right store for its runtime (see README + SECURITY.md).
 */
export interface TokenStore {
  get(): Promise<string | null> | string | null;
  set(token: string | null): Promise<void> | void;
  clear(): Promise<void> | void;
}

/** Default. Process-memory only. Safe for SSR, tests, CLI. */
export class MemoryTokenStore implements TokenStore {
  private token: string | null = null;
  get(): string | null {
    return this.token;
  }
  set(token: string | null): void {
    this.token = token;
  }
  clear(): void {
    this.token = null;
  }
}

function hasBrowserStorage(): boolean {
  try {
    return typeof globalThis.localStorage !== 'undefined' && globalThis.localStorage !== null;
  } catch {
    return false;
  }
}

/**
 * Browser persistence via localStorage.
 *
 * SECURITY: localStorage is readable by any JS on the origin. Only use on
 * trusted first-party origins; prefer short-lived tokens + HttpOnly-cookie
 * sessions for high-risk apps. NEVER use this store (or any persistent store)
 * for SERVER-ONLY secret API keys — those must stay on the server.
 * On the server (Next.js SSR / Node) this store throws on use instead of
 * silently dropping tokens — fail loud, not lost.
 */
export class BrowserTokenStore implements TokenStore {
  constructor(private readonly key = 'backend.auth.token') {}
  get(): string | null {
    if (!hasBrowserStorage()) throw new Error('BrowserTokenStore used outside a browser context. Use MemoryTokenStore on the server.');
    return globalThis.localStorage.getItem(this.key);
  }
  set(token: string | null): void {
    if (!hasBrowserStorage()) throw new Error('BrowserTokenStore used outside a browser context. Use MemoryTokenStore on the server.');
    if (token === null) globalThis.localStorage.removeItem(this.key);
    else globalThis.localStorage.setItem(this.key, token);
  }
  clear(): void {
    if (!hasBrowserStorage()) throw new Error('BrowserTokenStore used outside a browser context. Use MemoryTokenStore on the server.');
    globalThis.localStorage.removeItem(this.key);
  }
}
