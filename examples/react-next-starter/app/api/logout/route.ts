import { NextResponse } from 'next/server';
import { cookies } from 'next/headers';

/**
 * POST /api/logout — server action route: revokes the token server-side
 * (proves logout invalidates state) then clears the session cookie.
 * NOTE: this example keeps the token client-side in BrowserTokenStore AND
 * mirrors it into an HttpOnly cookie at login for SSR. Either is fine alone;
 * pick one per app and delete the other path.
 */
export async function POST() {
  const token = cookies().get('backend_token')?.value ?? null;
  if (token) {
    const { BackendClient } = await import('@platform/backend-sdk');
    const { MemoryTokenStore } = await import('@platform/backend-sdk');
    const store = new MemoryTokenStore();
    await store.set(token);
    const backend = new BackendClient({
      baseUrl: process.env['API_URL'] ?? process.env['NEXT_PUBLIC_API_URL'] ?? '',
      tokenStore: store,
    });
    try {
      await backend.auth.logout(); // server revokes the token
    } catch {
      // Token already invalid → still clear local state below.
    }
  }
  const res = NextResponse.redirect(new URL('/login', process.env['NEXT_PUBLIC_APP_URL'] ?? 'http://localhost:3000'));
  res.cookies.delete('backend_token');
  return res;
}

export async function GET() {
  return POST();
}
