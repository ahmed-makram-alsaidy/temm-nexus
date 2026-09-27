/**
 * Protected page (Server Component): reads the user token from the session
 * cookie, fetches `me` server-side, redirects to /login on 401.
 * The browser bundle never sees the token here — it stays in the cookie.
 */
import { cookies } from 'next/headers';
import { redirect } from 'next/navigation';
import { ApiError } from '@platform/backend-sdk';
import { getServerClient } from '../../lib/backend';

export default async function DashboardPage() {
  const token = cookies().get('backend_token')?.value ?? null;
  if (!token) redirect('/login');

  const backend = getServerClient(token);
  try {
    const me = await backend.auth.me();
    const health = await backend.health();
    return (
      <main>
        <h1>Hello, {me.name}</h1>
        <p>{me.email}</p>
        <p>API: {health.ok ? 'healthy' : 'degraded'}</p>
        <form action="/api/logout" method="post">
          <button type="submit">Sign out</button>
        </form>
      </main>
    );
  } catch (err) {
    // Invalid/revoked/expired token → drop the session, start over.
    if (err instanceof ApiError && err.code === 'UNAUTHENTICATED') redirect('/login');
    throw err;
  }
}
