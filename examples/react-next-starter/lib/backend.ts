/**
 * Client factories. The cardinal rule: ONE client per context.
 *
 * - Browser ("use client"): singleton with BrowserTokenStore.
 * - Server (Server Components / Route Handlers / Server Actions): a FRESH
 *   BackendClient with MemoryTokenStore PER REQUEST, token forwarded from the
 *   session cookie. Never a module-global token on the server (cross-user leak).
 *
 * NOTE: every NEXT_PUBLIC_* read below uses LITERAL process.env access.
 * Next.js inlines env into the browser bundle only for literal references —
 * a dynamic helper like process.env[name] compiles to undefined client-side
 * (caught live in Phase 22.1: "baseUrl is required").
 */
import { BackendClient, BrowserTokenStore, MemoryTokenStore } from '@platform/backend-sdk';

let browserClient: BackendClient | null = null;

/** Browser singleton. Only call from Client Components. */
export function getBrowserClient(): BackendClient {
  if (typeof window === 'undefined') {
    throw new Error('getBrowserClient() called on the server. Use getServerClient() there.');
  }
  if (!browserClient) {
    browserClient = new BackendClient({
      baseUrl: process.env.NEXT_PUBLIC_API_URL ?? '',
      apiKey: process.env.NEXT_PUBLIC_API_KEY || null,
      tokenStore: new BrowserTokenStore(),
      functionsBaseUrl: process.env.NEXT_PUBLIC_FUNCTIONS_URL || null,
      projectSlug: process.env.NEXT_PUBLIC_PROJECT_SLUG || null,
      realtime: { wsUrl: process.env.NEXT_PUBLIC_REALTIME_WS_URL || null },
    });
  }
  return browserClient;
}

/** Per-request server client. Pass the user token from your session cookie. */
export function getServerClient(userToken: string | null): BackendClient {
  const store = new MemoryTokenStore();
  const client = new BackendClient({
    baseUrl: process.env.API_URL ?? process.env.NEXT_PUBLIC_API_URL ?? '',
    // Server components use the user token, NOT the public key, for user data.
    tokenStore: store,
    functionsBaseUrl: process.env.FUNCTIONS_BASE_URL || null,
    projectSlug: process.env.PROJECT_SLUG || null,
  });
  if (userToken) void store.set(userToken);
  return client;
}

/** Server-only key client for internal automation (Route Handlers only). */
export function getServiceClient(): BackendClient {
  const secret = process.env.API_SECRET_KEY ?? '';
  if (!secret) throw new Error('API_SECRET_KEY is not configured.');
  return new BackendClient({
    baseUrl: process.env.API_URL ?? process.env.NEXT_PUBLIC_API_URL ?? '',
    apiKey: secret,
    tokenStore: new MemoryTokenStore(),
    functionsBaseUrl: process.env.FUNCTIONS_BASE_URL || null,
    projectSlug: process.env.PROJECT_SLUG || null,
  });
}
